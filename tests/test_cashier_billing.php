<?php
declare(strict_types=1);
require_once __DIR__ . '/../backend/api/cashier_billing.php';
function isExitPaymentCleared($status, $vendorScanTime = null): bool {
    return in_array($status, ['PAID', 'PAID_OVERNIGHT', 'BYPASSED', 'PAID_RESERVATION'], true);
}
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE parking_sessions(session_id INTEGER PRIMARY KEY, vehicle_id INTEGER);
           CREATE TABLE violations(violation_id INTEGER PRIMARY KEY, session_id INTEGER, violation_type TEXT, penalty_amount REAL, status TEXT, issued_at TEXT);
           INSERT INTO parking_sessions VALUES (1, 1), (2, 1), (3, 2);
           INSERT INTO violations VALUES
             (1, 1, 'Driveway Obstruction / Double Parking', 300, 'UNPAID', '2026-11-01 08:00:00'),
             (2, 2, 'Unauthorized Zone A (PWD) Parking', 500, 'UNPAID', '2026-11-02 08:00:00'),
             (3, 1, 'Paid old fine', 250, 'PAID', '2026-11-01 09:00:00'),
             (4, 3, 'Another vehicle', 400, 'UNPAID', '2026-11-02 09:00:00');");
$session = ['vehicle_id' => 1, 'entry_time' => gmdate('Y-m-d H:i:s', time() - 3600),
            'payment_status' => 'UNPAID', 'vendor_scan_time' => null];
$bill = cashierBill($db, $session);
if (count($bill['tariff']['violations']) !== 2 || (float)$bill['tariff']['violations_total'] !== 800.0 ||
    (float)$bill['tariff']['total_due'] !== 830.0 || !hasOutstandingVehicleViolations($db, 1)) {
    throw new RuntimeException('Cashier bill omitted or miscalculated vehicle citations.');
}
$session['payment_status'] = 'PAID';
$bill = cashierBill($db, $session);
if ((float)$bill['tariff']['parking_fee'] !== 0.0 || (float)$bill['tariff']['total_due'] !== 800.0) {
    throw new RuntimeException('Previously paid parking was charged again.');
}
$db->exec("UPDATE violations SET status = 'PAID' WHERE violation_id IN (1, 2)");
if (hasOutstandingVehicleViolations($db, 1)) throw new RuntimeException('Paid citations still block exit.');
echo "Cashier billing tests passed\n";
