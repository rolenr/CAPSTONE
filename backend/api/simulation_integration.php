<?php
declare(strict_types=1);

/** Camera observations are separate from the slot allocation held at entry. */
function ensureSimulationSchema(PDO $db): void {
    $sessionColumns = array_column($db->query('PRAGMA table_info(parking_sessions)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('vehicle_color', $sessionColumns, true)) {
        $db->exec("ALTER TABLE parking_sessions ADD COLUMN vehicle_color TEXT NOT NULL DEFAULT 'UNKNOWN'");
    }
    if (!in_array('color_confidence', $sessionColumns, true)) {
        $db->exec('ALTER TABLE parking_sessions ADD COLUMN color_confidence REAL');
    }
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
    $observationColumns = array_column($db->query('PRAGMA table_info(slot_observations)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('vehicle_color', $observationColumns, true)) {
        $db->exec("ALTER TABLE slot_observations ADD COLUMN vehicle_color TEXT NOT NULL DEFAULT 'UNKNOWN'");
    }
    if (!in_array('color_confidence', $observationColumns, true)) {
        $db->exec('ALTER TABLE slot_observations ADD COLUMN color_confidence REAL');
    }
    if (!in_array('recognized_plate', $observationColumns, true)) {
        $db->exec('ALTER TABLE slot_observations ADD COLUMN recognized_plate TEXT');
    }
    $db->exec("CREATE TABLE IF NOT EXISTS gate_color_observations (
        id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER NOT NULL,
        camera_role TEXT NOT NULL, vehicle_color TEXT NOT NULL,
        confidence REAL NOT NULL, observed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS sectional_events (
        event_id TEXT PRIMARY KEY, scenario_id TEXT NOT NULL, event_type TEXT NOT NULL,
        camera_id TEXT NOT NULL, frame_index INTEGER NOT NULL, observed_at TEXT NOT NULL,
        result TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS simulation_occupancy_runs (
        scenario_id TEXT PRIMARY KEY, zone TEXT NOT NULL, status TEXT NOT NULL,
        original_parked_count INTEGER, persist_results INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $occupancyRunColumns = array_column(
        $db->query('PRAGMA table_info(simulation_occupancy_runs)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('original_parked_count',$occupancyRunColumns,true)) {
        $db->exec('ALTER TABLE simulation_occupancy_runs ADD COLUMN original_parked_count INTEGER');
    }
    if (!in_array('persist_results',$occupancyRunColumns,true)) {
        $db->exec('ALTER TABLE simulation_occupancy_runs ADD COLUMN persist_results INTEGER NOT NULL DEFAULT 0');
    }
    $db->exec("CREATE TABLE IF NOT EXISTS simulation_occupancy_originals (
        scenario_id TEXT NOT NULL, slot_id INTEGER NOT NULL, original_occupied INTEGER NOT NULL,
        PRIMARY KEY (scenario_id,slot_id)
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
    reconcileSectionalFines($db);
}

/** Apply the current fine policy to old demo records and confirmed alerts once. */
function reconcileSectionalFines(PDO $db): void {
    $staleFines = (int)$db->query("SELECT COUNT(*) FROM violations
        WHERE UPPER(COALESCE(violation_type,'')) NOT LIKE '%LOST%TICKET%'
          AND COALESCE(penalty_amount,-1) != CASE WHEN status='DISMISSED' THEN 0 ELSE 200 END")
        ->fetchColumn();
    $unlinkedAlerts = (int)$db->query("SELECT COUNT(*) FROM sectional_alerts
        WHERE status='CONFIRMED' AND violation_id IS NULL AND session_id IS NOT NULL")
        ->fetchColumn();
    if ($staleFines === 0 && $unlinkedAlerts === 0) return;
    $db->exec('BEGIN IMMEDIATE');
    try {
        $changed = $db->exec("UPDATE violations SET penalty_amount=CASE WHEN status='DISMISSED' THEN 0 ELSE 200 END
            WHERE UPPER(COALESCE(violation_type,'')) NOT LIKE '%LOST%TICKET%'
              AND COALESCE(penalty_amount,-1) != CASE WHEN status='DISMISSED' THEN 0 ELSE 200 END");
        $alerts = $db->query("SELECT alert_id,scenario_id,session_id,camera_id,violation_type,region
            FROM sectional_alerts WHERE status='CONFIRMED' AND violation_id IS NULL AND session_id IS NOT NULL
            ORDER BY alert_id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($alerts as $alert) {
            $find = $db->prepare("SELECT violation_id FROM sectional_alerts
                WHERE scenario_id=? AND session_id=? AND camera_id=? AND violation_type=?
                  AND COALESCE(region,'')=COALESCE(?,'') AND status='CONFIRMED'
                  AND violation_id IS NOT NULL ORDER BY alert_id LIMIT 1");
            $find->execute([$alert['scenario_id'],$alert['session_id'],$alert['camera_id'],
                $alert['violation_type'],$alert['region']]);
            $violationId = $find->fetchColumn();
            if ($violationId === false) {
                $label = sectionalFineLabel($alert['violation_type']);
                $fine = $db->prepare("INSERT INTO violations(session_id,violation_type,penalty_amount,status)
                    VALUES(?,?,200,'UNPAID')");
                $fine->execute([(int)$alert['session_id'],$label]);
                $violationId = (int)$db->lastInsertId();
            }
            $db->prepare('UPDATE sectional_alerts SET violation_id=? WHERE alert_id=?')
                ->execute([(int)$violationId,(int)$alert['alert_id']]);
        }
        if ($changed || $alerts) {
            $db->prepare("INSERT INTO audit_logs(admin_email,action_type,target_entity,details)
                VALUES('SYSTEM','FINE_POLICY_RECONCILED','violations',?)")
                ->execute(["Repriced {$changed} existing violations; linked " . count($alerts)
                    . ' previously confirmed alerts at PHP 200; dismissed rows carry no balance.']);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function sectionalFineLabel(string $type): string {
    return match ($type) {
        'DRIVEWAY' => 'Driveway Obstruction / No Parking Zone',
        'DOUBLE_PARKING' => 'Driveway Obstruction / Double Parking',
        'WRONG_SLOT' => 'Wrong Slot Parking',
        'WRONG_PLATE' => 'Unrecognized Plate in Assigned Bay',
        'COLOR_REVIEW' => 'Confirmed Vehicle Color Mismatch',
        'ZONE_A_PRIORITY' => 'Unauthorized Zone A (VIP / PWD) Parking',
        default => $type,
    };
}

/** Preserve physical slot flags before a recorded sectional feed changes them. */
function simulationOccupancyStart(PDO $db, string $scenario, string $zone, bool $persist = false): array {
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$scenario) || !in_array($zone,['A','B','C','D','E'],true)) {
        throw new InvalidArgumentException('A valid scenario and recorded zone are required.',400);
    }
    ensureSimulationSchema($db);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $active = $db->prepare("SELECT scenario_id FROM simulation_occupancy_runs WHERE zone=? AND status='ACTIVE' LIMIT 1");
        $active->execute([$zone]);
        if ($active->fetchColumn() !== false) throw new RuntimeException('Another occupancy simulation is active in this zone; restore it first.',409);
        $count = $db->prepare('SELECT active_parked_count FROM spatial_allocation WHERE zone_id=?');
        $count->execute([$zone]);
        $originalCount = $count->fetchColumn();
        if ($originalCount === false) throw new RuntimeException('Zone allocation is unavailable.',409);
        $db->prepare("INSERT INTO simulation_occupancy_runs(scenario_id,zone,status,original_parked_count,persist_results)
            VALUES(?,?,'ACTIVE',?,?)")->execute([$scenario,$zone,(int)$originalCount,$persist ? 1 : 0]);
        $save = $db->prepare("INSERT INTO simulation_occupancy_originals(scenario_id,slot_id,original_occupied)
            SELECT ?,slot_id,is_occupied FROM parking_slots WHERE zone=? AND is_active=1");
        $save->execute([$scenario,$zone]);
        $count = $save->rowCount();
        $db->commit();
        return ['success'=>true,'scenario_id'=>$scenario,'zone'=>$zone,'saved_slots'=>$count,
                'persist_results'=>$persist];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

/** Restore only the slot flags changed by this run; active sessions retain their bay. */
function simulationOccupancyFinish(PDO $db, string $scenario): array {
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$scenario)) {
        throw new InvalidArgumentException('A valid scenario is required.',400);
    }
    ensureSimulationSchema($db);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $run = $db->prepare('SELECT zone,status,original_parked_count,persist_results FROM simulation_occupancy_runs WHERE scenario_id=?');
        $run->execute([$scenario]);
        $found = $run->fetch(PDO::FETCH_ASSOC);
        if (!$found) throw new RuntimeException('Occupancy simulation was not started.',404);
        if ($found['status'] !== 'ACTIVE') {
            $db->commit();
            return ['success'=>true,'restored_slots'=>0,'already_restored'=>true];
        }
        if ((int)$found['persist_results'] === 1) {
            $summary = $db->prepare("SELECT COUNT(*) AS observed,
                SUM(CASE WHEN o.occupancy_state='OCCUPIED' THEN 1 ELSE 0 END) AS occupied,
                SUM(CASE WHEN o.occupancy_state='VACANT' AND EXISTS (
                    SELECT 1 FROM parking_sessions ps WHERE ps.slot_id=o.slot_id
                    AND ps.exit_time IS NULL AND ps.location_status='PARKED') THEN 1 ELSE 0 END) AS conflicts
                FROM slot_observations o JOIN parking_slots s ON s.slot_id=o.slot_id
                WHERE o.scenario_id=? AND s.zone=? AND s.is_active=1");
            $summary->execute([$scenario,$found['zone']]);
            $totals = $summary->fetch(PDO::FETCH_ASSOC);
            $db->prepare("INSERT INTO audit_logs(admin_email,action_type,target_entity,target_id,details)
                VALUES('SECTIONAL_CAMERA','SECTIONAL_SNAPSHOT_SYNC','zone',?,?)")
                ->execute([$found['zone'],"scenario={$scenario}; observed={$totals['observed']}; "
                    ."occupied={$totals['occupied']}; vacant_session_conflicts={$totals['conflicts']}"]);
            $db->prepare("UPDATE simulation_occupancy_runs SET status='COMPLETED' WHERE scenario_id=?")
                ->execute([$scenario]);
            $db->commit();
            return ['success'=>true,'restored_slots'=>0,'persisted'=>true,
                    'active_session_slots_preserved'=>0];
        }
        $restore = $db->prepare("UPDATE parking_slots SET is_occupied=(
                SELECT original_occupied FROM simulation_occupancy_originals o
                WHERE o.scenario_id=? AND o.slot_id=parking_slots.slot_id)
            WHERE slot_id IN (SELECT slot_id FROM simulation_occupancy_originals WHERE scenario_id=?)
              AND NOT EXISTS (SELECT 1 FROM parking_sessions ps WHERE ps.slot_id=parking_slots.slot_id
                  AND ps.exit_time IS NULL AND ps.location_status='PARKED')");
        $restore->execute([$scenario,$scenario]);
        $skipped = $db->prepare("SELECT COUNT(*) FROM simulation_occupancy_originals o
            JOIN parking_sessions ps ON ps.slot_id=o.slot_id
            WHERE o.scenario_id=? AND ps.exit_time IS NULL AND ps.location_status='PARKED'");
        $skipped->execute([$scenario]);
        $activeCount = $db->prepare("SELECT COUNT(*) FROM parking_sessions ps
            JOIN parking_slots s ON s.slot_id=ps.slot_id
            WHERE s.zone=? AND ps.exit_time IS NULL AND ps.location_status='PARKED'");
        $activeCount->execute([$found['zone']]);
        $restoredCount = max((int)$found['original_parked_count'],(int)$activeCount->fetchColumn());
        $db->prepare('UPDATE spatial_allocation SET active_parked_count=? WHERE zone_id=?')
            ->execute([$restoredCount,$found['zone']]);
        $db->prepare("UPDATE simulation_occupancy_runs SET status='RESTORED' WHERE scenario_id=?")
            ->execute([$scenario]);
        $db->commit();
        return ['success'=>true,'restored_slots'=>$restore->rowCount(),
                'active_session_slots_preserved'=>(int)$skipped->fetchColumn()];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function normalizeObservedColor($color, $confidence): array {
    $label = strtoupper(trim((string)$color));
    $allowed = ['WHITE','BLACK','GRAY','RED','BLUE','GREEN','YELLOW','BROWN','OTHER'];
    $score = is_numeric($confidence) ? (float)$confidence : 0.0;
    if (!in_array($label,$allowed,true) || !is_finite($score) || $score < 0.55 || $score > 1) {
        return ['UNKNOWN',0.0];
    }
    return [$label,$score];
}

function strongColorMismatch(string $expected, float $expectedScore, string $observed, float $observedScore): bool {
    if ($expected === $observed || $expectedScore < .85 || $observedScore < .85) return false;
    if (in_array($expected,['UNKNOWN','OTHER'],true) || in_array($observed,['UNKNOWN','OTHER'],true)) return false;
    if (in_array('GRAY',[$expected,$observed],true)) return false;
    return true;
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

function simulationEntry(PDO $db, string $plate, string $scenarioId, string $actor,
                         string $preferredSlot = '', string $vehicleColor = 'UNKNOWN', float $colorConfidence = 0.0,
                         string $allocationZone = '', bool $reservedBayTest = false, string $clipSlot = '',
                         bool $qrOnly = false): array {
    if ($qrOnly ? !preg_match('/^QR_ONLY_[A-F0-9]{16}$/D', $plate)
                : !preg_match('/^[A-Z]{3}[0-9]{3,4}$/D', $plate)) {
        throw new InvalidArgumentException('A valid normalized plate is required.', 400);
    }
    if ($preferredSlot !== '' && !preg_match('/^[A-F]-[0-9]{2}$/D', $preferredSlot)) {
        throw new InvalidArgumentException('Invalid preferred bay.', 400);
    }
    if ($reservedBayTest && ($allocationZone === '' || !in_array($allocationZone, ['A','B','C','D','E'], true)
            || !preg_match('/^sim_reserved_slot_'.strtolower($allocationZone).'_[A-Za-z0-9_-]+$/D', $scenarioId)
            || !preg_match('/^[A-E]-[0-9]{2}$/D', $clipSlot) || $clipSlot[0] !== $allocationZone
            || $preferredSlot !== '')) {
        throw new InvalidArgumentException('Reserved-bay test requires a zone and separate clip bay.', 400);
    }
    ensureSimulationSchema($db);
    [$vehicleColor,$colorConfidence] = normalizeObservedColor($vehicleColor,$colorConfidence);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $existing = $db->prepare("SELECT ps.session_id, ps.entry_token, ps.payment_status,
                                         ps.vehicle_color,ps.color_confidence,
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
            $insert = $db->prepare('INSERT INTO vehicles(plate_number,vehicle_type) VALUES(?,?)');
            $insert->execute([$plate,$qrOnly ? 'QR_ONLY' : 'Transient']);
            $vehicleId = (int)$db->lastInsertId();
        }

        $pending = $db->prepare("SELECT 1 FROM reservations WHERE vehicle_id=?
            AND status IN ('PENDING_PAYMENT','ACTIVE')
            AND payment_state NOT IN ('PAID','ADMIN_EXEMPT') LIMIT 1");
        $pending->execute([$vehicleId]);
        if ($pending->fetchColumn()) throw new RuntimeException('Reservation payment is unconfirmed.', 409);

        $reserved = $db->prepare("SELECT r.reservation_id,r.payment_state,r.token_id,s.slot_id,s.slot_number,s.zone
            FROM reservations r JOIN parking_slots s ON s.slot_id=r.slot_id
            WHERE r.vehicle_id=? AND r.status='ACTIVE'
              AND r.payment_state IN ('PAID','ADMIN_EXEMPT') AND s.is_active=1
            ORDER BY r.reservation_id DESC LIMIT 1");
        $reserved->execute([$vehicleId]);
        $booking = $reserved->fetch(PDO::FETCH_ASSOC);
        if ($reservedBayTest && $booking) {
            throw new RuntimeException('Reserved-bay test entrant must be a walk-in vehicle without its own active reservation.', 409);
        }
        $snapshot = $db->prepare("SELECT sl.slot_id,sl.slot_number,sl.zone,so.occupancy_state
            FROM slot_observations so JOIN parking_slots sl ON sl.slot_id=so.slot_id
            WHERE so.scenario_id=? AND sl.is_active=1 AND sl.zone!='F'");
        $snapshot->execute([$scenarioId]);
        $observed = [];
        foreach ($snapshot->fetchAll(PDO::FETCH_ASSOC) as $row) $observed[$row['slot_number']] = $row;
        $vip = $db->prepare("SELECT is_vip FROM accounts
            WHERE UPPER(REPLACE(REPLACE(plate_number,' ',''),'-',''))=? LIMIT 1");
        $vip->execute([$plate]);
        $isVip = (int)$vip->fetchColumn() === 1;
        $relocateReservation = $booking && $booking['zone'] === 'A' && !$isVip;
        if ($booking && !$relocateReservation) {
            if ($preferredSlot !== '' && $booking['slot_number'] !== $preferredSlot) {
                throw new RuntimeException('Active reservation is for a different bay than this simulation clip.', 409);
            }
            if ($observed && (!isset($observed[$booking['slot_number']]) || $observed[$booking['slot_number']]['occupancy_state'] !== 'VACANT')) {
                throw new RuntimeException('Reserved bay is occupied or outside the sectional baseline.', 409);
            }
            $slot = $booking;
            $payment = $booking['payment_state']==='PAID' ? 'PAID_RESERVATION' : 'BYPASSED';
        } else {
            if ($reservedBayTest) {
                $target = $db->prepare("SELECT s.slot_id FROM parking_slots s JOIN reservations r ON r.slot_id=s.slot_id
                    WHERE s.slot_number=? AND s.zone=? AND s.is_active=1 AND s.is_occupied=0
                      AND r.status='ACTIVE' AND r.payment_state IN ('PAID','ADMIN_EXEMPT')
                      AND r.vehicle_id!=? AND EXISTS (SELECT 1 FROM slot_observations so
                          WHERE so.slot_id=s.slot_id AND so.scenario_id=? AND so.occupancy_state='VACANT')
                    LIMIT 1");
                $target->execute([$clipSlot,$allocationZone,$vehicleId,$scenarioId]);
                if ($target->fetchColumn() === false) {
                    throw new RuntimeException('Reserved-bay test requires the clip bay to be actively reserved and physically vacant.', 409);
                }
            }
            // The recorded clip may show an ineligible vehicle parking in A,
            // but the entry allocator must still direct that vehicle elsewhere.
            $recordedZoneA = false;
            if (($preferredSlot !== '' && str_starts_with($preferredSlot,'A-'))
                    || ($reservedBayTest && $allocationZone === 'A')) {
                $run = $db->prepare("SELECT 1 FROM simulation_occupancy_runs
                    WHERE scenario_id=? AND zone='A' AND status='ACTIVE' LIMIT 1");
                $run->execute([$scenarioId]);
                $recordedZoneA = $run->fetchColumn() !== false;
            }
            $priority = $isVip ? "CASE s.zone WHEN 'A' THEN 0 WHEN 'B' THEN 1 WHEN 'C' THEN 2 WHEN 'D' THEN 3 WHEN 'E' THEN 4 WHEN 'F' THEN 5 ELSE 9 END"
                               : "CASE s.zone WHEN 'B' THEN 0 WHEN 'C' THEN 1 WHEN 'D' THEN 2 WHEN 'E' THEN 3 WHEN 'F' THEN 4 ELSE 9 END";
            $redirectFromZoneA = ($recordedZoneA || $relocateReservation) && !$isVip;
            $zoneFilter = $reservedBayTest && !$redirectFromZoneA ? 'AND s.zone=?' : '';
            $cameraFilter = "(CAST(? AS INTEGER)=0 OR CAST(? AS INTEGER)=1 OR EXISTS (SELECT 1 FROM slot_observations so WHERE so.slot_id=s.slot_id
                    AND so.scenario_id=? AND so.occupancy_state='VACANT'))";
            $requestedSlot = $redirectFromZoneA ? '' : $preferredSlot;
            $candidate = $db->prepare("SELECT s.slot_id,s.slot_number,s.zone FROM parking_slots s
                WHERE s.is_active=1 AND s.is_occupied=0
                $zoneFilter
                AND " . ($isVip ? "s.zone IN ('A','B','C','D','E','F')" : "s.zone IN ('B','C','D','E','F')") . "
                AND $cameraFilter
                AND NOT EXISTS (SELECT 1 FROM reservations r WHERE r.slot_id=s.slot_id
                    AND r.status IN ('ACTIVE','PENDING_PAYMENT'))
                ORDER BY CASE WHEN s.slot_number=? THEN 0 ELSE 1 END,$priority,s.slot_number LIMIT 1");
            if ($reservedBayTest) {
                $params = $zoneFilter ? [$allocationZone] : [];
            } else {
                $params = [];
            }
            array_push($params,count($observed),$redirectFromZoneA ? 1 : 0,$scenarioId,$requestedSlot);
            $candidate->execute($params);
            $slot = $candidate->fetch(PDO::FETCH_ASSOC);
            if ($requestedSlot !== '' && (!$slot || $slot['slot_number'] !== $requestedSlot)) {
                throw new RuntimeException('Requested simulation bay is unavailable in the sectional baseline.', 409);
            }
            if (!$slot) throw new RuntimeException('No available slot remains.', 409);
            $payment = $relocateReservation
                ? ($booking['payment_state']==='PAID' ? 'PAID_RESERVATION' : 'BYPASSED')
                : 'UNPAID';
        }

        $occupy = $db->prepare('UPDATE parking_slots SET is_occupied=1 WHERE slot_id=? AND is_active=1 AND is_occupied=0');
        $occupy->execute([(int)$slot['slot_id']]);
        if ($occupy->rowCount() !== 1) throw new RuntimeException('Assigned slot is unavailable.', 409);
        $token = $booking && !empty($booking['token_id'])
            ? (string)$booking['token_id'] : 'QR_' . bin2hex(random_bytes(16));
        $session = $db->prepare('INSERT INTO parking_sessions(vehicle_id,slot_id,payment_status,entry_token,vehicle_color,color_confidence) VALUES(?,?,?,?,?,?)');
        $session->execute([(int)$vehicleId,(int)$slot['slot_id'],$payment,$token,$vehicleColor,$colorConfidence]);
        $sessionId = (int)$db->lastInsertId();
        if ($vehicleColor !== 'UNKNOWN') {
            $db->prepare("INSERT INTO gate_color_observations(session_id,camera_role,vehicle_color,confidence)
                VALUES(?,'ENTRANCE',?,?)")->execute([$sessionId,$vehicleColor,$colorConfidence]);
        }
        $bind = $db->prepare('INSERT INTO simulation_sessions(session_id,scenario_id,plate_number) VALUES(?,?,?)');
        $bind->execute([$sessionId,$scenarioId,$plate]);
        $zone = $db->prepare('UPDATE spatial_allocation SET active_parked_count=active_parked_count+1 WHERE zone_id=?');
        $zone->execute([$slot['zone']]);
        if ($zone->rowCount() !== 1) throw new RuntimeException('Zone allocation is unavailable.', 409);
        if ($booking) {
            $fulfill = $db->prepare("UPDATE reservations SET status='FULFILLED',slot_id=?
                WHERE reservation_id=? AND status='ACTIVE'");
            $fulfill->execute([(int)$slot['slot_id'],$booking['reservation_id']]);
        }
        $audit = $db->prepare("INSERT INTO audit_logs(admin_email,action_type,target_entity,target_id,details)
            VALUES(?,'CAMERA_ENTRY','parking_session',?,?)");
        $auditDetails = "scenario={$scenarioId}; plate={$plate}; slot={$slot['slot_number']}; entry_method="
            . ($qrOnly ? 'QR_FALLBACK' : 'ALPR');
        if ($reservedBayTest) $auditDetails .= "; reserved_bay_test={$clipSlot}; allocated_elsewhere_in_zone={$allocationZone}";
        if ($relocateReservation) $auditDetails .= "; non_vip_zone_a_reservation_moved_from={$booking['slot_number']}";
        $audit->execute([$actor,(string)$sessionId,$auditDetails]);
        $db->commit();
        return ['success'=>true,'duplicate'=>false,'scenario_id'=>$scenarioId,
                'session_id'=>$sessionId,'plate_number'=>$plate,'entry_token'=>$token,
                'payment_status'=>$payment,'slot_number'=>$slot['slot_number'],'zone'=>$slot['zone'],
                'vehicle_color'=>$vehicleColor,'color_confidence'=>$colorConfidence,
                'qr_only'=>$qrOnly];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

/** Adjust effective time only for an open session created by the recorded simulator. */
function simulationAdvanceClock(PDO $db, string $scenarioId, int $sessionId, string $stage): array {
    $offsets = [
        'NORMAL_FARE' => ['entry_time', '-16 minutes'],
        'TENANT_VALID' => ['vendor_scan_time', '-18 minutes'],
        'TENANT_EXPIRED' => ['vendor_scan_time', '-20 minutes', '-1 second'],
    ];
    if (!preg_match('/^sim_(grace|normal_fare|tenant)_[a-e]_[a-z0-9_-]+$/i', $scenarioId, $scenarioMatch)) {
        throw new InvalidArgumentException('Clock adjustment is limited to named timed simulation scenarios.', 400);
    }
    if (!isset($offsets[$stage])) throw new InvalidArgumentException('Unsupported simulation clock stage.', 400);
    $scenarioCase = strtoupper($scenarioMatch[1]);
    $stageCase = str_starts_with($stage,'TENANT_') ? 'TENANT' : $stage;
    if ($scenarioCase !== $stageCase) {
        throw new InvalidArgumentException('The clock stage does not match this simulation case.',400);
    }
    ensureSimulationSchema($db);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $find = $db->prepare("SELECT ps.session_id,ps.entry_time,ps.vendor_scan_time,ps.payment_status
            FROM parking_sessions ps JOIN simulation_sessions ss ON ss.session_id=ps.session_id
            WHERE ps.session_id=? AND ss.scenario_id=? AND ps.exit_time IS NULL
              AND ps.location_status='PARKED'");
        $find->execute([$sessionId,$scenarioId]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Open session does not match this simulation run.',404);
        $parts = $offsets[$stage];
        $column = array_shift($parts);
        if (str_starts_with($stage,'TENANT_') && $row['payment_status'] !== 'PAID_VENDOR') {
            throw new RuntimeException('Validate the tenant before adjusting its simulated departure window.',409);
        }
        $oldValue = $row[$column];
        $modifiers = implode(',', array_fill(0, count($parts), '?'));
        $update = $db->prepare("UPDATE parking_sessions SET $column=datetime('now',$modifiers) WHERE session_id=?");
        $parameters = $parts;
        $parameters[] = $sessionId;
        $update->execute($parameters);
        $newValue = $db->query("SELECT $column FROM parking_sessions WHERE session_id=".(int)$sessionId)->fetchColumn();
        $audit = $db->prepare("INSERT INTO audit_logs(admin_email,action_type,target_entity,target_id,details)
            VALUES('SIMULATION_CLOCK','SIMULATION_TIME_ADVANCE','parking_session',?,?)");
        $audit->execute([(string)$sessionId,"scenario={$scenarioId}; stage={$stage}; {$column}: {$oldValue} -> {$newValue}; effective simulation time only"]);
        $db->commit();
        return ['success'=>true,'session_id'=>$sessionId,'scenario_id'=>$scenarioId,
            'stage'=>$stage,'effective_timestamp'=>$newValue];
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
            $lookup = $db->prepare("SELECT ps.session_id,ps.vehicle_color,ps.color_confidence,
                ve.plate_number,sl.slot_number,sl.zone
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
            $recognizedPlate = strtoupper(preg_replace('/[^A-Z0-9]/i','',(string)($event['recognized_plate'] ?? '')));
            if (!preg_match('/^[A-Z]{3}[0-9]{3,4}$/D',$recognizedPlate)) $recognizedPlate = '';
            [$observedColor,$colorScore] = normalizeObservedColor(
                $event['vehicle_color'] ?? '',$event['color_confidence'] ?? null);
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
                    (slot_id,camera_id,scenario_id,occupancy_state,observed_at,frame_index,source,confidence,vehicle_color,color_confidence,recognized_plate)
                    VALUES(?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(slot_id,camera_id,scenario_id) DO UPDATE SET
                    occupancy_state=excluded.occupancy_state,observed_at=excluded.observed_at,
                    frame_index=excluded.frame_index,source=excluded.source,confidence=excluded.confidence,
                    vehicle_color=CASE WHEN excluded.vehicle_color='UNKNOWN' AND excluded.occupancy_state='OCCUPIED'
                        THEN slot_observations.vehicle_color ELSE excluded.vehicle_color END,
                    color_confidence=CASE WHEN excluded.vehicle_color='UNKNOWN' AND excluded.occupancy_state='OCCUPIED'
                        THEN slot_observations.color_confidence ELSE excluded.color_confidence END,
                    recognized_plate=CASE WHEN excluded.occupancy_state='VACANT' THEN NULL
                        WHEN excluded.recognized_plate IS NOT NULL THEN excluded.recognized_plate
                        ELSE slot_observations.recognized_plate END");
                $upsert->execute([$slotId,$camera,$scenario,$state,$observed,$frame,
                    json_encode($event['source'] ?? [],JSON_UNESCAPED_SLASHES),$event['confidence'] ?? null,
                    $state === 'OCCUPIED' ? $observedColor : 'UNKNOWN',
                    $state === 'OCCUPIED' ? $colorScore : 0,
                    $state === 'OCCUPIED' && $recognizedPlate !== '' ? $recognizedPlate : null]);
                // Only an explicitly started recorded run may mirror camera occupancy
                // into the live slot flags. An open session still owns its assigned bay.
                $mirror = $db->prepare("UPDATE parking_slots SET is_occupied=? WHERE slot_id=?
                    AND EXISTS (SELECT 1 FROM simulation_occupancy_runs r
                        JOIN simulation_occupancy_originals o ON o.scenario_id=r.scenario_id
                        WHERE r.scenario_id=? AND r.status='ACTIVE' AND o.slot_id=parking_slots.slot_id)
                    AND (CAST(? AS INTEGER)=1 OR NOT EXISTS (SELECT 1 FROM parking_sessions ps
                        WHERE ps.slot_id=parking_slots.slot_id AND ps.exit_time IS NULL
                          AND ps.location_status='PARKED'))");
                $mirror->execute([$state === 'OCCUPIED' ? 1 : 0,$slotId,$scenario,
                                  $state === 'OCCUPIED' ? 1 : 0]);
                $zoneCount = $db->prepare("UPDATE spatial_allocation SET active_parked_count=(
                    SELECT COUNT(*) FROM parking_slots WHERE zone=? AND is_active=1 AND is_occupied=1)
                    WHERE zone_id=? AND EXISTS (SELECT 1 FROM simulation_occupancy_runs
                        WHERE scenario_id=? AND status='ACTIVE' AND zone=?)");
                $zoneName = strtok($slotName,'-');
                $zoneCount->execute([$zoneName,$zoneName,$scenario,$zoneName]);
                if ($state === 'OCCUPIED' && $session) {
                    $expectedPlate = strtoupper(preg_replace('/[^A-Z0-9]/i','',$session['plate_number']));
                    $clipPlate = strtoupper(preg_replace('/[^A-Z0-9]/i','',(string)($event['clip_plate'] ?? '')));
                    $clipSlot = (string)($event['clip_slot'] ?? '');
                    $readPlate = $recognizedPlate;
                    if ($clipPlate !== $expectedPlate) $result = 'IDENTITY_UNKNOWN';
                    elseif ($readPlate === '' && $clipSlot !== $slotName) $result = 'UNRELATED_OCCUPANCY';
                    elseif ($readPlate !== '' && $readPlate !== $expectedPlate) $result = 'WRONG_PLATE';
                    elseif ($slotName !== $session['slot_number']) {
                        $result = 'WRONG_SLOT';
                        if (str_starts_with($slotName,'A-')) {
                            $run = $db->prepare("SELECT 1 FROM simulation_occupancy_runs
                                WHERE scenario_id=? AND zone='A' AND status='ACTIVE'");
                            $run->execute([$scenario]);
                            if ($run->fetchColumn()) {
                                $vip = $db->prepare("SELECT is_vip FROM accounts
                                    WHERE UPPER(REPLACE(REPLACE(plate_number,' ',''),'-',''))=? LIMIT 1");
                                $vip->execute([$expectedPlate]);
                                if ((int)$vip->fetchColumn() !== 1) {
                                    // One unauthorized parking incident gets one
                                    // review and one potential fine after confirmation.
                                    $result = 'ZONE_A_PRIORITY_PENDING';
                                }
                            }
                        }
                    }
                    else $result = $readPlate === '' ? 'CLIP_BOUND' : 'MATCH';
                    if (in_array($result,['CLIP_BOUND','MATCH'],true) && strongColorMismatch(
                            (string)$session['vehicle_color'],(float)$session['color_confidence'],
                            $observedColor,$colorScore)) $result = 'COLOR_REVIEW';
                    if (in_array($result,['WRONG_PLATE','WRONG_SLOT','COLOR_REVIEW'],true)) {
                        $alert = $db->prepare("INSERT INTO sectional_alerts
                            (event_id,scenario_id,session_id,camera_id,violation_type,region,evidence,details)
                            VALUES(?,?,?,?,?,?,?,?)");
                        $alert->execute([$id,$scenario,$sessionId,$camera,$result,$slotName,
                            (string)($event['evidence'] ?? ''),"Expected {$expectedPlate} in {$session['slot_number']} ({$session['vehicle_color']}); observed {$readPlate} in {$slotName} ({$observedColor})"]);
                    }
                }
            }
            }
        } else {
            $violation = (string)($event['violation_type'] ?? '');
            $duration = (float)($event['duration_seconds'] ?? 0);
            $region = trim((string)($event['region'] ?? ''));
            $trackId = trim((string)($event['track_id'] ?? ''));
            $requiredDuration = 20;
            if (!in_array($violation,['DRIVEWAY','DOUBLE_PARKING'],true) || $duration < $requiredDuration) {
                throw new InvalidArgumentException("A confirmed {$requiredDuration}-second restricted-area stay is required.",400);
            }
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

/** Check Zone A priority only after the recorded parking clip confirms occupancy. */
function simulationZoneAPriorityCheck(PDO $db, string $scenario, int $sessionId,
                                      string $camera, string $slotName, string $evidence,
                                      string $clipPlate): array {
    ensureSimulationSchema($db);
    if (!preg_match('/^sim_[A-Za-z0-9_-]{1,60}$/D',$scenario)
            || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$camera)
            || !preg_match('/^A-[0-9]{2}$/D',$slotName) || $sessionId < 1) {
        throw new InvalidArgumentException('Recorded Zone A scenario, camera, session, and bay are required.',400);
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $run = $db->prepare("SELECT 1 FROM simulation_occupancy_runs
            WHERE scenario_id=? AND zone='A' AND status='ACTIVE'");
        $run->execute([$scenario]);
        if (!$run->fetchColumn()) throw new RuntimeException('No active recorded Zone A run.',409);
        $session = $db->prepare("SELECT v.plate_number,sl.slot_number,sl.zone
            FROM parking_sessions ps JOIN vehicles v ON v.vehicle_id=ps.vehicle_id
            JOIN parking_slots sl ON sl.slot_id=ps.slot_id
            JOIN simulation_sessions ss ON ss.session_id=ps.session_id
            WHERE ps.session_id=? AND ss.scenario_id=? AND ps.exit_time IS NULL
              AND ps.location_status='PARKED'");
        $session->execute([$sessionId,$scenario]);
        $vehicle = $session->fetch(PDO::FETCH_ASSOC);
        if (!$vehicle) {
            throw new RuntimeException('Active parking session not found for this Zone A run.',404);
        }
        $observation = $db->prepare("SELECT so.recognized_plate FROM slot_observations so
            JOIN parking_slots sl ON sl.slot_id=so.slot_id
            WHERE so.scenario_id=? AND so.camera_id=? AND sl.slot_number=?
              AND so.occupancy_state='OCCUPIED'");
        $observation->execute([$scenario,$camera,$slotName]);
        $parked = $observation->fetch(PDO::FETCH_ASSOC);
        if (!$parked) {
            throw new RuntimeException('Zone A parking has not been observed in this bay.',409);
        }
        if (str_starts_with($vehicle['plate_number'],'QR_ONLY_')) {
            $db->commit();
            return ['success'=>true,'result'=>'IDENTITY_UNKNOWN','alert_id'=>null];
        }
        $clipPlate = strtoupper(preg_replace('/[^A-Z0-9]/i','',$clipPlate));
        if ($clipPlate !== $vehicle['plate_number']) {
            $db->commit();
            return ['success'=>true,'result'=>'IDENTITY_UNKNOWN','alert_id'=>null];
        }
        if (!empty($parked['recognized_plate']) && $parked['recognized_plate'] !== $vehicle['plate_number']) {
            $db->commit();
            return ['success'=>true,'result'=>'IDENTITY_UNKNOWN','alert_id'=>null];
        }
        $vip = $db->prepare("SELECT is_vip FROM accounts
            WHERE UPPER(REPLACE(REPLACE(plate_number,' ',''),'-',''))=? LIMIT 1");
        $vip->execute([strtoupper(preg_replace('/[^A-Z0-9]/i','',$vehicle['plate_number']))]);
        if ((int)$vip->fetchColumn() === 1) {
            $db->commit();
            return ['success'=>true,'result'=>'ELIGIBLE','alert_id'=>null];
        }
        $existing = $db->prepare("SELECT alert_id,status FROM sectional_alerts
            WHERE scenario_id=? AND session_id=? AND violation_type='ZONE_A_PRIORITY' LIMIT 1");
        $existing->execute([$scenario,$sessionId]);
        $prior = $existing->fetch(PDO::FETCH_ASSOC);
        if (!$prior) {
            $alert = $db->prepare("INSERT INTO sectional_alerts
                (event_id,scenario_id,session_id,camera_id,violation_type,region,evidence,details)
                VALUES(?,?,?,?,?,?,?,?)");
            $alert->execute(['zone-a-priority-'.$scenario.'-'.$sessionId,$scenario,$sessionId,$camera,
                'ZONE_A_PRIORITY',$slotName,$evidence,
                "Plate {$vehicle['plate_number']} observed parked in {$slotName}; no VIP / PWD priority status is recorded for this plate. Assigned bay: {$vehicle['slot_number']}."]);
            $alertId = (int)$db->lastInsertId();
        } else {
            $alertId = (int)$prior['alert_id'];
        }
        $db->commit();
        return ['success'=>true,'result'=>$prior['status'] ?? 'PENDING_REVIEW','alert_id'=>(int)$alertId];
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
        if ($decision === 'CONFIRMED') {
            $linked = $sessionId ?: (int)$alert['session_id'];
            if (!$linked) throw new InvalidArgumentException('Select the vehicle session before issuing a fine.',400);
            $exists = $db->prepare('SELECT 1 FROM parking_sessions WHERE session_id=?');
            $exists->execute([$linked]);
            if (!$exists->fetchColumn()) throw new RuntimeException('Vehicle session not found.',404);
            $find = $db->prepare("SELECT violation_id FROM sectional_alerts
                WHERE scenario_id=? AND session_id=? AND camera_id=? AND violation_type=?
                  AND COALESCE(region,'')=COALESCE(?,'') AND status='CONFIRMED'
                  AND violation_id IS NOT NULL ORDER BY alert_id LIMIT 1");
            $find->execute([$alert['scenario_id'],$linked,$alert['camera_id'],
                $alert['violation_type'],$alert['region']]);
            $violationId = $find->fetchColumn();
            if ($violationId === false) {
                $fine = $db->prepare("INSERT INTO violations(session_id,violation_type,penalty_amount,status)
                    VALUES(?,?,200,'UNPAID')");
                $fine->execute([$linked,sectionalFineLabel($alert['violation_type'])]);
                $violationId = (int)$db->lastInsertId();
            }
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
