<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/api/exit_departure.php';
date_default_timezone_set('UTC');

function isExitPaymentCleared($status, $vendorScanTime = null): bool {
    return $status === 'PAID';
}
function expectExit(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function exitFixture(string $paymentStatus, int $occupied = 1): PDO {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE vehicles(vehicle_id INTEGER PRIMARY KEY,plate_number TEXT);
        CREATE TABLE parking_slots(slot_id INTEGER PRIMARY KEY,slot_number TEXT,zone TEXT,is_occupied INTEGER);
        CREATE TABLE spatial_allocation(zone_id TEXT PRIMARY KEY,active_parked_count INTEGER);
        CREATE TABLE parking_sessions(session_id INTEGER PRIMARY KEY,vehicle_id INTEGER,slot_id INTEGER,payment_status TEXT,vendor_scan_time TEXT,exit_time TEXT,location_status TEXT,exit_method TEXT,staff_id TEXT);
        CREATE TABLE violations(violation_id INTEGER PRIMARY KEY,session_id INTEGER,penalty_amount REAL,status TEXT);
        CREATE TABLE audit_logs(admin_email TEXT,action_type TEXT,target_entity TEXT,target_id TEXT,details TEXT);
        INSERT INTO vehicles VALUES(1,\'TST1234\');
        INSERT INTO parking_slots VALUES(1,\'A-01\',\'A\',' . $occupied . ');
        INSERT INTO spatial_allocation VALUES(\'A\',1);');
    $insert = $db->prepare("INSERT INTO parking_sessions VALUES(1,1,1,?,NULL,NULL,'PARKED',NULL,NULL)");
    $insert->execute([$paymentStatus]);
    return $db;
}

$db = exitFixture('PAID');
$result = recordExitDeparture($db, 1, 'ALPR', 'EXIT_CAMERA_CHANNEL_13');
expectExit($result['departure_recorded'] === true, 'Paid session did not depart');
expectExit($db->query('SELECT location_status FROM parking_sessions')->fetchColumn() === 'EXITED', 'Session remained parked');
expectExit((int)$db->query('SELECT is_occupied FROM parking_slots')->fetchColumn() === 0, 'Slot remained occupied');
expectExit((int)$db->query('SELECT active_parked_count FROM spatial_allocation')->fetchColumn() === 0, 'Zone count did not decrease');
expectExit((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action_type='EXIT_DEPARTURE_CONFIRMED'")->fetchColumn() === 1, 'Departure audit missing');
try {
    recordExitDeparture($db, 1, 'ALPR', 'EXIT_CAMERA_CHANNEL_13');
    throw new LogicException('Duplicate exit was accepted');
} catch (RuntimeException $error) {
    expectExit($error->getCode() === 404, 'Duplicate exit returned wrong result');
}
expectExit((int)$db->query('SELECT active_parked_count FROM spatial_allocation')->fetchColumn() === 0, 'Duplicate exit decremented zone');

$db = exitFixture('UNPAID');
try {
    recordExitDeparture($db, 1, 'ALPR', 'EXIT_CAMERA_CHANNEL_13');
    throw new LogicException('Unpaid exit was accepted');
} catch (RuntimeException $error) {
    expectExit($error->getCode() === 409, 'Unpaid exit returned wrong result');
}
expectExit((int)$db->query('SELECT is_occupied FROM parking_slots')->fetchColumn() === 1, 'Unpaid exit released slot');

$db = exitFixture('PAID');
$db->exec("INSERT INTO violations VALUES(1,1,300,'UNPAID')");
try {
    recordExitDeparture($db, 1, 'ALPR', 'EXIT_CAMERA_CHANNEL_13');
    throw new LogicException('Exit with unpaid citation was accepted');
} catch (RuntimeException $error) {
    expectExit($error->getCode() === 409, 'Unpaid citation returned wrong result');
}
expectExit((int)$db->query('SELECT is_occupied FROM parking_slots')->fetchColumn() === 1, 'Unpaid citation released slot');

$db = exitFixture('PAID', 0);
try {
    recordExitDeparture($db, 1, 'QR_FALLBACK', 'cashier@parking.test');
    throw new LogicException('Inconsistent slot exit was accepted');
} catch (RuntimeException $error) {
    expectExit($error->getCode() === 409, 'Inconsistent slot returned wrong result');
}
expectExit($db->query('SELECT exit_time FROM parking_sessions')->fetchColumn() === null, 'Failed exit wrote departure time');

echo "Automatic exit departure tests passed\n";
