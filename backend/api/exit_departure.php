<?php
declare(strict_types=1);
require_once __DIR__ . '/cashier_billing.php';

/** Close a paid parked session and release its slot in one SQLite transaction. */
function recordExitDeparture(PDO $db, int $sessionId, string $exitMethod, string $actor, bool $recordClearance = true): array {
    if (!in_array($exitMethod, ['ALPR', 'QR_FALLBACK'], true)) {
        throw new InvalidArgumentException('Unsupported exit method.');
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $query = $db->prepare("SELECT ps.session_id, ps.vehicle_id, ps.slot_id, ps.payment_status, ps.vendor_scan_time,
                                      ve.plate_number, sl.slot_number, sl.zone, sl.is_occupied
                               FROM parking_sessions ps
                               JOIN vehicles ve ON ve.vehicle_id = ps.vehicle_id
                               JOIN parking_slots sl ON sl.slot_id = ps.slot_id
                               WHERE ps.session_id = ? AND ps.exit_time IS NULL AND ps.location_status = 'PARKED'");
        $query->execute([$sessionId]);
        $session = $query->fetch(PDO::FETCH_ASSOC);
        if (!$session) throw new RuntimeException('No active parked session was found.', 404);
        if (!isExitPaymentCleared($session['payment_status'], $session['vendor_scan_time'])) {
            throw new RuntimeException('Payment status is not valid for exit.', 409);
        }
        if (hasOutstandingVehicleViolations($db, (int)$session['vehicle_id'])) {
            throw new RuntimeException('Outstanding violation fees must be settled before exit.', 409);
        }
        if ((int)$session['is_occupied'] !== 1) {
            throw new RuntimeException('The assigned slot is not marked occupied. Reconcile it before exit.', 409);
        }
        $zone = $db->prepare('SELECT active_parked_count FROM spatial_allocation WHERE zone_id = ?');
        $zone->execute([$session['zone']]);
        $count = $zone->fetchColumn();
        if ($count === false || (int)$count < 1) {
            throw new RuntimeException('Zone occupancy is inconsistent. Reconcile it before exit.', 409);
        }

        $exitTime = gmdate('Y-m-d H:i:s');
        $update = $db->prepare("UPDATE parking_sessions
            SET exit_time = ?, location_status = 'EXITED', exit_method = ?, staff_id = ?
            WHERE session_id = ? AND exit_time IS NULL AND location_status = 'PARKED'");
        $update->execute([$exitTime, $exitMethod, $actor, $sessionId]);
        if ($update->rowCount() !== 1) throw new RuntimeException('The session changed before departure was recorded.', 409);

        $free = $db->prepare('UPDATE parking_slots SET is_occupied = 0 WHERE slot_id = ? AND is_occupied = 1');
        $free->execute([$session['slot_id']]);
        if ($free->rowCount() !== 1) throw new RuntimeException('The parking slot could not be released.', 409);
        $decrement = $db->prepare('UPDATE spatial_allocation SET active_parked_count = active_parked_count - 1 WHERE zone_id = ? AND active_parked_count > 0');
        $decrement->execute([$session['zone']]);
        if ($decrement->rowCount() !== 1) throw new RuntimeException('Zone occupancy could not be updated.', 409);

        $audit = $db->prepare('INSERT INTO audit_logs (admin_email, action_type, target_entity, target_id, details) VALUES (?, ?, ?, ?, ?)');
        if ($recordClearance) {
            $audit->execute([$actor, 'EXIT_CLEARANCE', 'parking_session', (string)$sessionId,
                "outcome=CLEARED_TO_EXIT; method={$exitMethod}; payment_status={$session['payment_status']}; reason=verified_at_exit"]);
        }
        $audit->execute([$actor, 'EXIT_DEPARTURE_CONFIRMED', 'parking_session', (string)$sessionId,
            "Exit {$exitMethod} verified for vehicle {$session['plate_number']}; session closed; slot {$session['slot_number']} (Zone {$session['zone']}) released."]);
        $db->commit();
        return [
            'success' => true, 'departure_recorded' => true,
            'session_id' => $sessionId, 'plate_number' => $session['plate_number'],
            'payment_status' => $session['payment_status'], 'exit_method' => $exitMethod,
            'slot_number' => $session['slot_number'], 'zone' => $session['zone'],
            'exit_time' => $exitTime, 'message' => 'Departure recorded and parking slot released.',
        ];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
