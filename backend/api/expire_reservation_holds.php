<?php
// Run once a minute from cron: php backend/api/expire_reservation_holds.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/maya_reservation_payments.php';
require_once __DIR__ . '/xendit_reservation_payments.php'; // Existing historical holds.
require_once __DIR__ . '/local_reservation_payments.php';
date_default_timezone_set('UTC');
$db = new PDO('sqlite:' . __DIR__ . '/../database/parking.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
paymentDb($db);
localExpireHolds($db);
$holds = $db->query("SELECT r.reservation_id, p.checkout_session_id, p.provider
    FROM reservations r JOIN reservation_payments p ON p.reservation_id=r.reservation_id
    WHERE r.status='PENDING_PAYMENT' AND r.expiry_time <= CURRENT_TIMESTAMP
    AND p.status IN ('PENDING','EXPIRY_RETRY')")->fetchAll(PDO::FETCH_ASSOC);
foreach ($holds as $hold) {
    $id = (int)$hold['reservation_id'];
    $sessionId = $hold['checkout_session_id'];
    if ($hold['provider'] === 'MAYA') {
        if (mayaExpireHold($db, $id, $sessionId)) echo "Expired hold #{$id}\n";
    } elseif ($hold['provider'] === 'XENDIT') {
        if (xenditExpireHold($db, $id, $sessionId)) echo "Expired hold #{$id}\n";
    }
}
