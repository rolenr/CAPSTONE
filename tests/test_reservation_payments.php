<?php
require_once __DIR__ . '/../backend/api/reservation_payments.php';
date_default_timezone_set('UTC');
function ensure(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function fixture(string $deadline): PDO {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE reservations(reservation_id INTEGER PRIMARY KEY, slot_id INTEGER, expiry_time TEXT, status TEXT, payment_state TEXT, token_id TEXT, end_date TEXT);
        CREATE TABLE audit_logs(log_id INTEGER PRIMARY KEY,admin_email TEXT,action_type TEXT,target_entity TEXT,target_id TEXT,details TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    paymentDb($db);
    $db->prepare("INSERT INTO reservations VALUES(1,10,?,'PENDING_PAYMENT','PENDING',NULL,'2026-11-04')")->execute([$deadline]);
    $db->exec("INSERT INTO reservation_payments(reservation_id,checkout_session_id,amount_centavos,status) VALUES(1,'cs_test',6000,'PENDING')");
    return $db;
}
function paidEvent(string $eventId, int $time, int $amount = 6000): array {
    return ['data'=>['id'=>$eventId,'type'=>'event','attributes'=>[
        'type'=>'checkout_session.payment.paid','livemode'=>false,'created_at'=>$time,
        'data'=>['id'=>'cs_test','type'=>'checkout_session','attributes'=>[
            'reference_number'=>'RES-1','payments'=>[['id'=>'pay_test','attributes'=>[
                'status'=>'paid','amount'=>$amount,'currency'=>'PHP']]]]]]]];
}
putenv('PAYMONGO_WEBHOOK_SECRET=test_secret');
$raw = '{"data":{"id":"evt_test"}}';
$stamp = time();
$sig = hash_hmac('sha256', $stamp . '.' . $raw, 'test_secret');
ensure(paymentSignatureValid($raw, "t=$stamp,te=$sig,li="), 'valid signature rejected');
ensure(!paymentSignatureValid($raw . ' ', "t=$stamp,te=$sig,li="), 'forged body accepted');
ensure(!paymentSignatureValid($raw, 't=' . ($stamp-600) . ",te=$sig,li="), 'old signature accepted');

$now = time();
$db = fixture(gmdate('Y-m-d H:i:s', $now+600));
try { $db->exec("INSERT INTO reservations VALUES(2,10,'2026-11-03 08:00:00','PENDING_PAYMENT','PENDING',NULL,'2026-11-04')"); throw new RuntimeException('duplicate slot accepted'); }
catch (PDOException $e) { /* UNIQUE slot hold works. */ }
ensure(paymentProcessPaid($db, paidEvent('evt_1', $now)) === null, 'timely payment returned refund');
ensure($db->query("SELECT status||':'||payment_state FROM reservations WHERE reservation_id=1")->fetchColumn() === 'ACTIVE:PAID', 'timely payment not activated');
ensure(str_starts_with($db->query('SELECT token_id FROM reservations WHERE reservation_id=1')->fetchColumn(), 'QR_'), 'paid pass missing');
paymentProcessPaid($db, paidEvent('evt_1', $now));
paymentProcessPaid($db, paidEvent('evt_2', $now));
ensure((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action_type='RESERVATION_PAYMENT_PAID'")->fetchColumn() === 1, 'duplicate payment activated twice');

$delayedWebhook = fixture(gmdate('Y-m-d H:i:s', $now-1));
paymentProcessPaid($delayedWebhook, paidEvent('evt_delayed', $now-60));
ensure($delayedWebhook->query('SELECT payment_state FROM reservations WHERE reservation_id=1')->fetchColumn() === 'PAID', 'timely payment with delayed webhook was rejected');

$late = fixture(gmdate('Y-m-d H:i:s', $now-1));
ensure(paymentProcessPaid($late, paidEvent('evt_late', $now)) === 'pay_test', 'late payment not identified');
ensure($late->query('SELECT token_id FROM reservations WHERE reservation_id=1')->fetchColumn() === null, 'late payment issued pass');
paymentRefundLate($late, 'pay_test'); // no sandbox key: refund failure must reach staff.
ensure($late->query('SELECT refund_status FROM reservation_payments WHERE reservation_id=1')->fetchColumn() === 'STAFF_ATTENTION', 'failed refund not flagged');

$mismatch = fixture(gmdate('Y-m-d H:i:s', $now+600));
try { paymentProcessPaid($mismatch, paidEvent('evt_wrong', $now, 1)); throw new RuntimeException('amount mismatch accepted'); }
catch (RuntimeException $e) { ensure($e->getMessage() !== 'amount mismatch accepted', 'amount mismatch accepted'); }
ensure((int)$mismatch->query('SELECT COUNT(*) FROM paymongo_webhook_events')->fetchColumn() === 0, 'rejected event was recorded');
$mismatch->exec("UPDATE reservations SET status='ACTIVE',payment_state='UNVERIFIED',token_id='QR_old' WHERE reservation_id=1");
ensure((int)$mismatch->query("SELECT COUNT(*) FROM reservations WHERE status='ACTIVE' AND payment_state IN ('PAID','ADMIN_EXEMPT') AND token_id='QR_old'")->fetchColumn() === 0, 'legacy unpaid pass accepted by gate predicate');
$mismatch->exec("UPDATE reservations SET payment_state='ADMIN_EXEMPT' WHERE reservation_id=1");
ensure((int)$mismatch->query("SELECT COUNT(*) FROM reservations WHERE status='ACTIVE' AND payment_state IN ('PAID','ADMIN_EXEMPT') AND token_id='QR_old'")->fetchColumn() === 1, 'audited admin exemption predicate rejected');

$abandoned = fixture(gmdate('Y-m-d H:i:s', $now-1));
ensure(paymentExpireHold($abandoned, 1, 'cs_test', fn($method,$path,$body) => ['data'=>['attributes'=>['status'=>'expired']]]), 'confirmed expiry did not release hold');
ensure($abandoned->query('SELECT status FROM reservations WHERE reservation_id=1')->fetchColumn() === 'EXPIRED', 'abandoned checkout retained slot');
$processing = fixture(gmdate('Y-m-d H:i:s', $now-1));
$processingRemote = function($method,$path,$body) {
    if ($method === 'POST') throw new RuntimeException('payment processing', 400);
    return ['data'=>['attributes'=>['status'=>'active']]];
};
ensure(!paymentExpireHold($processing, 1, 'cs_test', $processingRemote), 'processing payment released slot');
ensure($processing->query('SELECT status FROM reservations WHERE reservation_id=1')->fetchColumn() === 'PENDING_PAYMENT', 'processing hold changed');
$crashRecovery = fixture(gmdate('Y-m-d H:i:s', $now-1));
$alreadyExpiredRemote = function($method,$path,$body) {
    if ($method === 'POST') throw new RuntimeException('already expired', 400);
    return ['data'=>['attributes'=>['status'=>'expired']]];
};
ensure(paymentExpireHold($crashRecovery, 1, 'cs_test', $alreadyExpiredRemote), 'previous remote expiry not reconciled');
echo "Reservation payment tests passed\n";
