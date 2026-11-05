<?php
declare(strict_types=1);

/** Build the cashier bill from current database state, including earlier unpaid citations. */
function cashierBill(PDO $db, array $session): array {
    $entry = strtotime((string)$session['entry_time'] . ' UTC');
    if ($entry === false) throw new RuntimeException('Session entry time is invalid.');
    $now = time();
    $minutes = max(1, (int)round(($now - $entry) / 60));
    $manila = new DateTimeZone('Asia/Manila');
    $entryLocal = (new DateTimeImmutable('@' . $entry))->setTimezone($manila);
    $nowLocal = (new DateTimeImmutable('@' . $now))->setTimezone($manila);
    $overnight = $entryLocal->format('Y-m-d') !== $nowLocal->format('Y-m-d') || $minutes > 720;
    $grace = $minutes <= 15;
    $prepaid = $session['payment_status'] === 'PAID_RESERVATION';
    $alreadyPaid = in_array($session['payment_status'], ['PAID', 'PAID_OVERNIGHT', 'BYPASSED'], true);
    $vendorCredit = $session['payment_status'] === 'PAID_VENDOR' &&
        isExitPaymentCleared($session['payment_status'], $session['vendor_scan_time']);
    $baseFee = $grace ? 0.0 : 30.0;
    $overnightFee = $overnight ? 100.0 : 0.0;
    $parkingFee = ($prepaid || $alreadyPaid || $grace) ? 0.0 :
        ($vendorCredit ? $overnightFee : $baseFee + $overnightFee);

    $query = $db->prepare("SELECT v.violation_id, v.violation_type, v.penalty_amount, v.issued_at,
                                  v.session_id AS source_session_id
                           FROM violations v
                           JOIN parking_sessions source ON source.session_id = v.session_id
                           WHERE source.vehicle_id = ? AND v.status = 'UNPAID'
                             AND COALESCE(v.penalty_amount, 0) > 0
                           ORDER BY v.issued_at, v.violation_id");
    $query->execute([(int)$session['vehicle_id']]);
    $violations = $query->fetchAll(PDO::FETCH_ASSOC);
    $violationsCents = 0;
    foreach ($violations as $violation) {
        $violationsCents += (int)round((float)$violation['penalty_amount'] * 100);
    }

    return [
        'duration' => [
            'minutes' => $minutes,
            'hours' => round($minutes / 60, 1),
            'human' => $minutes < 60 ? "{$minutes} mins" : floor($minutes / 60) . 'h ' . ($minutes % 60) . 'm',
        ],
        'tariff' => [
            'base_fee' => $baseFee, 'is_grace' => $grace,
            'is_overnight' => $overnight, 'overnight_surcharge' => $overnightFee,
            'has_vendor_credit' => $vendorCredit, 'vendor_credit' => $vendorCredit ? 30.0 : 0.0,
            'is_prepaid' => $prepaid, 'parking_fee' => $parkingFee,
            'violations' => $violations, 'violations_total' => $violationsCents / 100,
            'total_due' => $parkingFee + $violationsCents / 100,
        ],
    ];
}

function hasOutstandingVehicleViolations(PDO $db, int $vehicleId): bool {
    $query = $db->prepare("SELECT 1 FROM violations v JOIN parking_sessions ps ON ps.session_id = v.session_id
                           WHERE ps.vehicle_id = ? AND v.status = 'UNPAID' AND COALESCE(v.penalty_amount, 0) > 0 LIMIT 1");
    $query->execute([$vehicleId]);
    return $query->fetchColumn() !== false;
}
