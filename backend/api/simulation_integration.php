<?php
declare(strict_types=1);

/** Camera observations are separate from the slot allocation held at entry. */
function ensureSimulationSchema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS simulation_sessions (
        session_id INTEGER PRIMARY KEY, scenario_id TEXT NOT NULL,
        plate_number TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS slot_observations (
        slot_id INTEGER NOT NULL, camera_id TEXT NOT NULL, scenario_id TEXT NOT NULL,
        occupancy_state TEXT NOT NULL CHECK (occupancy_state IN ('OCCUPIED','VACANT')),
        observed_at TEXT NOT NULL, frame_index INTEGER NOT NULL, source TEXT NOT NULL,
        confidence REAL, PRIMARY KEY (slot_id, camera_id, scenario_id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS sectional_events (
        event_id TEXT PRIMARY KEY, scenario_id TEXT NOT NULL, event_type TEXT NOT NULL,
        camera_id TEXT NOT NULL, frame_index INTEGER NOT NULL, observed_at TEXT NOT NULL,
        result TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS sectional_alerts (
        alert_id INTEGER PRIMARY KEY AUTOINCREMENT, event_id TEXT NOT NULL UNIQUE,
        scenario_id TEXT NOT NULL, session_id INTEGER, camera_id TEXT NOT NULL,
        violation_type TEXT NOT NULL, region TEXT, track_id TEXT, evidence TEXT,
        details TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'PENDING_REVIEW',
        reviewed_by TEXT, reviewed_at TEXT, violation_id INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS one_restricted_alert_per_stay
        ON sectional_alerts(scenario_id,camera_id,violation_type,region,track_id)
        WHERE track_id IS NOT NULL");
}

/** Recover a paid simulation session left open when its recorded exit was not replayed. */
function closeSimulationSession(PDO $db, int $sessionId, string $reason, string $actor): array {
    if ($sessionId < 1 || strlen($reason) < 8 || strlen($reason) > 500) {
        throw new InvalidArgumentException('Session ID and an 8-500 character reason are required.', 400);
    }
    ensureSimulationSchema($db);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $find = $db->prepare("SELECT ps.session_id,ps.vehicle_id,ps.slot_id,ps.payment_status,ps.vendor_scan_time,
                s.slot_number,s.zone,v.plate_number,ss.scenario_id
            FROM parking_sessions ps JOIN simulation_sessions ss ON ss.session_id=ps.session_id
            JOIN parking_slots s ON s.slot_id=ps.slot_id JOIN vehicles v ON v.vehicle_id=ps.vehicle_id
            WHERE ps.session_id=? AND ps.exit_time IS NULL AND ps.location_status='PARKED'");
        $find->execute([$sessionId]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('No open simulation session was found.', 404);
        if (!isExitPaymentCleared($row['payment_status'], $row['vendor_scan_time'])) {
            throw new RuntimeException('Settle or validate the session before recovery closure.', 409);
        }
        if (hasOutstandingVehicleViolations($db, (int)$row['vehicle_id'])) {
            throw new RuntimeException('Outstanding violation fees must be settled first.', 409);
        }
        $other = $db->prepare("SELECT 1 FROM parking_sessions WHERE slot_id=? AND session_id!=?
            AND exit_time IS NULL AND location_status='PARKED' LIMIT 1");
        $other->execute([$row['slot_id'],$sessionId]);
        if ($other->fetchColumn()) throw new RuntimeException('Another open session uses this bay; reconcile it first.', 409);
        $close = $db->prepare("UPDATE parking_sessions SET exit_time=CURRENT_TIMESTAMP,
            location_status='EXITED',exit_method='SIMULATION_RECOVERY',staff_id=?
            WHERE session_id=? AND exit_time IS NULL AND location_status='PARKED'");
        $close->execute([$actor,$sessionId]);
        if ($close->rowCount() !== 1) throw new RuntimeException('Session changed during closure.', 409);
        $db->prepare('UPDATE parking_slots SET is_occupied=0 WHERE slot_id=?')->execute([$row['slot_id']]);
        $count = $db->prepare("SELECT COUNT(*) FROM parking_sessions ps JOIN parking_slots s ON s.slot_id=ps.slot_id
            WHERE s.zone=? AND ps.exit_time IS NULL AND ps.location_status='PARKED'");
        $count->execute([$row['zone']]);
        $db->prepare('UPDATE spatial_allocation SET active_parked_count=? WHERE zone_id=?')
            ->execute([(int)$count->fetchColumn(),$row['zone']]);
        $audit = $db->prepare("INSERT INTO audit_logs(admin_email,action_type,target_entity,target_id,details)
            VALUES(?,'SIMULATION_SESSION_RECOVERY','parking_session',?,?)");
        $audit->execute([$actor,(string)$sessionId,
            "scenario={$row['scenario_id']}; plate={$row['plate_number']}; slot={$row['slot_number']}; payment={$row['payment_status']}; no exit feed asserted; reason={$reason}"]);
        $db->commit();
        return ['success'=>true,'session_id'=>$sessionId,'slot_number'=>$row['slot_number'],
            'payment_status'=>$row['payment_status'],'exit_method'=>'SIMULATION_RECOVERY'];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function simulationEntry(PDO $db, string $plate, string $scenarioId, string $actor, string $preferredSlot = ''): array {
    if (!preg_match('/^[A-Z]{3}[0-9]{3,4}$/D', $plate)) {
        throw new InvalidArgumentException('A valid normalized plate is required.', 400);
    }
    if ($preferredSlot !== '' && !preg_match('/^[A-F]-[0-9]{2}$/D', $preferredSlot)) {
        throw new InvalidArgumentException('Invalid preferred bay.', 400);
    }
    ensureSimulationSchema($db);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $existing = $db->prepare("SELECT ps.session_id, ps.entry_token, ps.payment_status,
                                         sl.slot_number, sl.zone
            FROM parking_sessions ps JOIN vehicles v ON v.vehicle_id=ps.vehicle_id
            JOIN parking_slots sl ON sl.slot_id=ps.slot_id
            WHERE UPPER(REPLACE(REPLACE(v.plate_number,' ',''),'-',''))=?
              AND ps.exit_time IS NULL AND ps.location_status='PARKED'
            ORDER BY ps.session_id DESC LIMIT 1");
        $existing->execute([$plate]);
        if ($row = $existing->fetch(PDO::FETCH_ASSOC)) {
            $db->commit();
            return ['success'=>true, 'duplicate'=>true, 'scenario_id'=>$scenarioId,
                    'plate_number'=>$plate] + $row;
        }

        $vehicle = $db->prepare("SELECT vehicle_id FROM vehicles
            WHERE UPPER(REPLACE(REPLACE(plate_number,' ',''),'-',''))=? LIMIT 1");
        $vehicle->execute([$plate]);
        $vehicleId = $vehicle->fetchColumn();
        if ($vehicleId === false) {
            $insert = $db->prepare("INSERT INTO vehicles(plate_number,vehicle_type) VALUES(?,'Transient')");
            $insert->execute([$plate]);
            $vehicleId = (int)$db->lastInsertId();
        }

        $pending = $db->prepare("SELECT 1 FROM reservations WHERE vehicle_id=?
            AND status IN ('PENDING_PAYMENT','ACTIVE')
            AND payment_state NOT IN ('PAID','ADMIN_EXEMPT') LIMIT 1");
        $pending->execute([$vehicleId]);
        if ($pending->fetchColumn()) throw new RuntimeException('Reservation payment is unconfirmed.', 409);

        $reserved = $db->prepare("SELECT r.reservation_id,r.payment_state,s.slot_id,s.slot_number,s.zone
            FROM reservations r JOIN parking_slots s ON s.slot_id=r.slot_id
            WHERE r.vehicle_id=? AND r.status='ACTIVE'
              AND r.payment_state IN ('PAID','ADMIN_EXEMPT') AND s.is_active=1
            ORDER BY r.reservation_id DESC LIMIT 1");
        $reserved->execute([$vehicleId]);
        $booking = $reserved->fetch(PDO::FETCH_ASSOC);
        $snapshot = $db->prepare("SELECT sl.slot_id,sl.slot_number,sl.zone,so.occupancy_state
            FROM slot_observations so JOIN parking_slots sl ON sl.slot_id=so.slot_id
            WHERE so.scenario_id=? AND sl.is_active=1 AND sl.zone!='F'");
        $snapshot->execute([$scenarioId]);
        $observed = [];
        foreach ($snapshot->fetchAll(PDO::FETCH_ASSOC) as $row) $observed[$row['slot_number']] = $row;
        if ($booking) {
            if ($preferredSlot !== '' && $booking['slot_number'] !== $preferredSlot) {
                throw new RuntimeException('Active reservation is for a different bay than this simulation clip.', 409);
            }
            if ($observed && (!isset($observed[$booking['slot_number']]) || $observed[$booking['slot_number']]['occupancy_state'] !== 'VACANT')) {
                throw new RuntimeException('Reserved bay is occupied or outside the sectional baseline.', 409);
            }
            $slot = $booking;
            $payment = $booking['payment_state']==='PAID' ? 'PAID_RESERVATION' : 'BYPASSED';
        } else {
            $vip = $db->prepare('SELECT is_vip FROM accounts WHERE plate_number=? LIMIT 1');
            $vip->execute([$plate]);
            $isVip = (int)$vip->fetchColumn() === 1;
            $priority = $isVip ? "CASE s.zone WHEN 'A' THEN 0 WHEN 'B' THEN 1 WHEN 'C' THEN 2 WHEN 'D' THEN 3 WHEN 'E' THEN 4 WHEN 'F' THEN 5 ELSE 9 END"
                               : "CASE s.zone WHEN 'B' THEN 0 WHEN 'C' THEN 1 WHEN 'D' THEN 2 WHEN 'E' THEN 3 WHEN 'F' THEN 4 ELSE 9 END";
            $candidate = $db->prepare("SELECT s.slot_id,s.slot_number,s.zone FROM parking_slots s
                WHERE s.is_active=1 AND s.is_occupied=0
                AND " . ($isVip ? "s.zone IN ('A','B','C','D','E','F')" : "s.zone IN ('B','C','D','E','F')") . "
                AND (?=0 OR EXISTS (SELECT 1 FROM slot_observations so WHERE so.slot_id=s.slot_id
                    AND so.scenario_id=? AND so.occupancy_state='VACANT'))
                AND NOT EXISTS (SELECT 1 FROM reservations r WHERE r.slot_id=s.slot_id
                    AND r.status IN ('ACTIVE','PENDING_PAYMENT'))
                ORDER BY CASE WHEN s.slot_number=? THEN 0 ELSE 1 END,$priority,s.slot_number LIMIT 1");
            $candidate->execute([count($observed),$scenarioId,$preferredSlot]);
            $slot = $candidate->fetch(PDO::FETCH_ASSOC);
            if ($preferredSlot !== '' && (!$slot || $slot['slot_number'] !== $preferredSlot)) {
                throw new RuntimeException('Requested simulation bay is unavailable in the sectional baseline.', 409);
            }
            if (!$slot) throw new RuntimeException('No available slot remains.', 409);
            $payment = 'UNPAID';
        }

        $occupy = $db->prepare('UPDATE parking_slots SET is_occupied=1 WHERE slot_id=? AND is_active=1 AND is_occupied=0');
        $occupy->execute([(int)$slot['slot_id']]);
        if ($occupy->rowCount() !== 1) throw new RuntimeException('Assigned slot is unavailable.', 409);
        $token = 'QR_' . bin2hex(random_bytes(16));
        $session = $db->prepare('INSERT INTO parking_sessions(vehicle_id,slot_id,payment_status,entry_token) VALUES(?,?,?,?)');
        $session->execute([(int)$vehicleId,(int)$slot['slot_id'],$payment,$token]);
        $sessionId = (int)$db->lastInsertId();
        $bind = $db->prepare('INSERT INTO simulation_sessions(session_id,scenario_id,plate_number) VALUES(?,?,?)');
        $bind->execute([$sessionId,$scenarioId,$plate]);
        $zone = $db->prepare('UPDATE spatial_allocation SET active_parked_count=active_parked_count+1 WHERE zone_id=?');
        $zone->execute([$slot['zone']]);
        if ($zone->rowCount() !== 1) throw new RuntimeException('Zone allocation is unavailable.', 409);
        if ($booking) {
            $fulfill = $db->prepare("UPDATE reservations SET status='FULFILLED' WHERE reservation_id=? AND status='ACTIVE'");
            $fulfill->execute([$booking['reservation_id']]);
        }
        $audit = $db->prepare("INSERT INTO audit_logs(admin_email,action_type,target_entity,target_id,details)
            VALUES(?,'CAMERA_ENTRY','parking_session',?,?)");
        $audit->execute([$actor,(string)$sessionId,"scenario={$scenarioId}; plate={$plate}; slot={$slot['slot_number']}"]);
        $db->commit();
        return ['success'=>true,'duplicate'=>false,'scenario_id'=>$scenarioId,
                'session_id'=>$sessionId,'plate_number'=>$plate,'entry_token'=>$token,
                'payment_status'=>$payment,'slot_number'=>$slot['slot_number'],'zone'=>$slot['zone']];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function simulationSectionalEvent(PDO $db, array $event): array {
    ensureSimulationSchema($db);
    $id = (string)($event['event_id'] ?? '');
    $scenario = (string)($event['scenario_id'] ?? '');
    $camera = (string)($event['camera_id'] ?? '');
    $type = (string)($event['event_type'] ?? '');
    $frame = filter_var($event['frame_index'] ?? null, FILTER_VALIDATE_INT);
    $observed = (string)($event['timestamp'] ?? '');
    if (!preg_match('/^[a-f0-9-]{36}$/i', $id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $scenario)
        || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $camera) || $frame === false || $frame < 0
        || strtotime($observed) === false) {
        throw new InvalidArgumentException('Invalid sectional event identity or source.', 400);
    }
    if (!in_array($type, ['sectional.slot.observed','parking.restricted.overstay'], true)) {
        throw new InvalidArgumentException('Unsupported sectional event type.', 400);
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $known = $db->prepare('SELECT result FROM sectional_events WHERE event_id=?');
        $known->execute([$id]);
        if ($previous = $known->fetchColumn()) {
            $db->commit();
            return ['success'=>true,'duplicate'=>true,'result'=>$previous];
        }
        $result = 'OBSERVED';
        $sessionId = filter_var($event['session_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $session = null;
        if ($sessionId) {
            $lookup = $db->prepare("SELECT ps.session_id,ve.plate_number,sl.slot_number
                FROM parking_sessions ps JOIN vehicles ve ON ve.vehicle_id=ps.vehicle_id
                JOIN parking_slots sl ON sl.slot_id=ps.slot_id
                JOIN simulation_sessions ss ON ss.session_id=ps.session_id
                WHERE ps.session_id=? AND ss.scenario_id=?
                  AND ps.exit_time IS NULL AND ps.location_status='PARKED'");
            $lookup->execute([$sessionId,$scenario]);
            $session = $lookup->fetch(PDO::FETCH_ASSOC);
            if (!$session) throw new RuntimeException('Active session not found.', 404);
        }
        if ($type === 'sectional.slot.observed') {
            $slotName = (string)($event['slot_number'] ?? '');
            $state = (string)($event['occupancy_state'] ?? '');
            if (!in_array($state,['OCCUPIED','VACANT'],true)) throw new InvalidArgumentException('Invalid occupancy state.',400);
            $findSlot = $db->prepare("SELECT slot_id FROM parking_slots WHERE slot_number=? AND is_active=1 AND zone!='F'");
            $findSlot->execute([$slotName]);
            $slotId = $findSlot->fetchColumn();
            if ($slotId === false) {
                // Older labeled recordings can include retired bay IDs. Keep
                // the event record without changing the current live map.
                $result = 'INACTIVE_SLOT_IGNORED';
            } else {
            $last = $db->prepare('SELECT frame_index FROM slot_observations WHERE slot_id=? AND camera_id=? AND scenario_id=?');
            $last->execute([$slotId,$camera,$scenario]);
            $oldFrame = $last->fetchColumn();
            if ($oldFrame !== false && $frame <= (int)$oldFrame) {
                $result = 'STALE';
            } else {
                $upsert = $db->prepare("INSERT INTO slot_observations
                    (slot_id,camera_id,scenario_id,occupancy_state,observed_at,frame_index,source,confidence)
                    VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(slot_id,camera_id,scenario_id) DO UPDATE SET
                    occupancy_state=excluded.occupancy_state,observed_at=excluded.observed_at,
                    frame_index=excluded.frame_index,source=excluded.source,confidence=excluded.confidence");
                $upsert->execute([$slotId,$camera,$scenario,$state,$observed,$frame,
                    json_encode($event['source'] ?? [],JSON_UNESCAPED_SLASHES),$event['confidence'] ?? null]);
                if ($state === 'OCCUPIED' && $session) {
                    $expectedPlate = strtoupper(preg_replace('/[^A-Z0-9]/i','',$session['plate_number']));
                    $clipPlate = strtoupper(preg_replace('/[^A-Z0-9]/i','',(string)($event['clip_plate'] ?? '')));
                    $clipSlot = (string)($event['clip_slot'] ?? '');
                    $readPlate = strtoupper(preg_replace('/[^A-Z0-9]/i','',(string)($event['recognized_plate'] ?? '')));
                    if ($clipPlate !== $expectedPlate) $result = 'IDENTITY_UNKNOWN';
                    elseif ($readPlate === '' && $clipSlot !== $slotName) $result = 'UNRELATED_OCCUPANCY';
                    elseif ($readPlate !== '' && $readPlate !== $expectedPlate) $result = 'WRONG_PLATE';
                    elseif ($slotName !== $session['slot_number']) $result = 'WRONG_SLOT';
                    else $result = $readPlate === '' ? 'CLIP_BOUND' : 'MATCH';
                    if (in_array($result,['WRONG_PLATE','WRONG_SLOT'],true)) {
                        $alert = $db->prepare("INSERT INTO sectional_alerts
                            (event_id,scenario_id,session_id,camera_id,violation_type,region,evidence,details)
                            VALUES(?,?,?,?,?,?,?,?)");
                        $alert->execute([$id,$scenario,$sessionId,$camera,$result,$slotName,
                            (string)($event['evidence'] ?? ''),"Expected {$expectedPlate} in {$session['slot_number']}; observed {$readPlate} in {$slotName}"]);
                    }
                }
            }
            }
        } else {
            $violation = (string)($event['violation_type'] ?? '');
            $duration = (float)($event['duration_seconds'] ?? 0);
            if (!in_array($violation,['DRIVEWAY','DOUBLE_PARKING'],true) || $duration < 300) {
                throw new InvalidArgumentException('A confirmed five-minute restricted-area stay is required.',400);
            }
            $region = trim((string)($event['region'] ?? ''));
            $trackId = trim((string)($event['track_id'] ?? ''));
            if ($region === '' || $trackId === '') {
                throw new InvalidArgumentException('Restricted-area alerts require a region and tracked stay.',400);
            }
            $alert = $db->prepare("INSERT INTO sectional_alerts
                (event_id,scenario_id,session_id,camera_id,violation_type,region,track_id,evidence,details)
                VALUES(?,?,?,?,?,?,?,?,?) ON CONFLICT DO NOTHING");
            $alert->execute([$id,$scenario,$sessionId,$camera,$violation,
                $region,$trackId,
                (string)($event['evidence'] ?? ''),"Observed {$duration} seconds in restricted area; staff review required."]);
            $result = $alert->rowCount() ? 'PENDING_REVIEW' : 'DUPLICATE_STAY';
        }
        $saved = $db->prepare('INSERT INTO sectional_events(event_id,scenario_id,event_type,camera_id,frame_index,observed_at,result) VALUES(?,?,?,?,?,?,?)');
        $saved->execute([$id,$scenario,$type,$camera,$frame,$observed,$result]);
        $db->commit();
        return ['success'=>true,'duplicate'=>false,'result'=>$result];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function simulationReviewAlert(PDO $db, int $alertId, string $decision, ?int $sessionId, string $actor): array {
    ensureSimulationSchema($db);
    if (!in_array($decision,['CONFIRMED','REJECTED'],true)) throw new InvalidArgumentException('Invalid review decision.',400);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $stmt = $db->prepare('SELECT * FROM sectional_alerts WHERE alert_id=?');
        $stmt->execute([$alertId]);
        $alert = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$alert) throw new RuntimeException('Alert not found.',404);
        if ($alert['status'] !== 'PENDING_REVIEW') throw new RuntimeException('Alert was already reviewed.',409);
        $violationId = null;
        if ($decision === 'CONFIRMED' && in_array($alert['violation_type'],['DRIVEWAY','DOUBLE_PARKING'],true)) {
            $linked = $sessionId ?: (int)$alert['session_id'];
            if (!$linked) throw new InvalidArgumentException('Select the vehicle session before issuing a fine.',400);
            $exists = $db->prepare('SELECT 1 FROM parking_sessions WHERE session_id=?');
            $exists->execute([$linked]);
            if (!$exists->fetchColumn()) throw new RuntimeException('Vehicle session not found.',404);
            $label = $alert['violation_type'] === 'DRIVEWAY' ? 'Driveway Obstruction / No Parking Zone' : 'Driveway Obstruction / Double Parking';
            $fine = $db->prepare("INSERT INTO violations(session_id,violation_type,penalty_amount,status) VALUES(?,?,300,'UNPAID')");
            $fine->execute([$linked,$label]);
            $violationId = (int)$db->lastInsertId();
            $sessionId = $linked;
        }
        $update = $db->prepare('UPDATE sectional_alerts SET status=?,session_id=?,reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP,violation_id=? WHERE alert_id=?');
        $update->execute([$decision,$sessionId ?: $alert['session_id'],$actor,$violationId,$alertId]);
        $audit = $db->prepare("INSERT INTO audit_logs(admin_email,action_type,target_entity,target_id,details) VALUES(?,'SECTIONAL_REVIEW','sectional_alert',?,?)");
        $audit->execute([$actor,(string)$alertId,"decision={$decision}; violation_id=" . ($violationId ?: 'none')]);
        $db->commit();
        return ['success'=>true,'alert_id'=>$alertId,'status'=>$decision,'violation_id'=>$violationId];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
