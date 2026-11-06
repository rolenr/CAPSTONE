<?php
require_once __DIR__ . '/../backend/api/maya_reservation_payments.php';
date_default_timezone_set('UTC');
function checkM(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function mFixture(string $deadline): PDO {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE reservations(reservation_id INTEGER PRIMARY KEY,slot_id INTEGER,expiry_time TEXT,status TEXT,payment_state TEXT,token_id TEXT,end_date TEXT);
        CREATE TABLE audit_logs(log_id INTEGER PRIMARY KEY,admin_email TEXT,action_type TEXT,target_entity TEXT,target_id TEXT,details TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    paymentDb($db);
    $db->prepare("INSERT INTO reservations VALUES(1,10,?,'PENDING_PAYMENT','PENDING',NULL,'2026-11-05')")->execute([$deadline]);
    $db->exec("INSERT INTO reservation_payments(reservation_id,provider,checkout_session_id,amount_centavos,status)
        VALUES(1,'MAYA','11111111-1111-1111-1111-111111111111',6000,'PENDING')");
    return $db;
}
function mPayment(int $at, float $amount = 60): array {
    return ['id'=>'11111111-1111-1111-1111-111111111111','paymentStatus'=>'PAYMENT_SUCCESS',
        'status'=>'COMPLETED','requestReferenceNumber'=>'RES-1',
        'totalAmount'=>['value'=>$amount,'currency'=>'PHP'],
        'paymentDetails'=>['paymentAt'=>gmdate('Y-m-d\TH:i:s\Z',$at)]];
}

checkM(mayaCheckoutUrlValid('https://payments-web-sandbox.paymaya.com/checkout/123'), 'Maya sandbox URL rejected');
checkM(!mayaCheckoutUrlValid('https://payments.maya.ph/checkout/123'), 'Maya live URL accepted');
$now = time();
$db = mFixture(gmdate('Y-m-d H:i:s',$now+600));
mayaProcessPayment($db,mPayment($now));
checkM($db->query('SELECT status||":"||payment_state FROM reservations')->fetchColumn()==='ACTIVE:PAID','paid booking not active');
checkM(str_starts_with($db->query('SELECT token_id FROM reservations')->fetchColumn(),'QR_'),'paid pass missing');
mayaProcessPayment($db,mPayment($now));
checkM((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action_type='RESERVATION_PAYMENT_PAID'")->fetchColumn()===1,'duplicate payment activated twice');

$late = mFixture(gmdate('Y-m-d H:i:s',$now-1));
mayaProcessPayment($late,mPayment($now));
checkM($late->query('SELECT token_id FROM reservations')->fetchColumn()===null,'late payment issued pass');
checkM($late->query('SELECT refund_status FROM reservation_payments')->fetchColumn()==='STAFF_REVIEW','late refund not queued');

$wrong = mFixture(gmdate('Y-m-d H:i:s',$now+600));
try { mayaProcessPayment($wrong,mPayment($now,1)); throw new RuntimeException('wrong amount accepted'); }
catch (RuntimeException $e) { checkM($e->getMessage()!=='wrong amount accepted','wrong amount accepted'); }
checkM((int)$wrong->query('SELECT COUNT(*) FROM maya_webhook_events')->fetchColumn()===0,'bad event recorded');

$abandoned = mFixture(gmdate('Y-m-d H:i:s',$now-1));
$cancelled = false;
checkM(mayaExpireHold($abandoned,1,'11111111-1111-1111-1111-111111111111',function($method,$path) use (&$cancelled) {
    if ($method==='POST') { $cancelled=true; return ['paymentStatus'=>'PAYMENT_CANCELLED']; }
    return ['id'=>'11111111-1111-1111-1111-111111111111','paymentStatus'=>$cancelled ? 'PAYMENT_CANCELLED' : 'PENDING_PAYMENT'];
}), 'canceled checkout not expired');
checkM($abandoned->query('SELECT status FROM reservations')->fetchColumn()==='EXPIRED','slot not released');

$processing = mFixture(gmdate('Y-m-d H:i:s',$now-1));
checkM(!mayaExpireHold($processing,1,'11111111-1111-1111-1111-111111111111',function($method) {
    if ($method==='POST') throw new RuntimeException('processing');
    return ['id'=>'11111111-1111-1111-1111-111111111111','paymentStatus'=>'PAYMENT_PROCESSING'];
}), 'processing checkout released slot');
checkM($processing->query('SELECT status FROM reservations')->fetchColumn()==='PENDING_PAYMENT','processing booking changed');

$mismatch = mFixture(gmdate('Y-m-d H:i:s',$now-1));
checkM(!mayaExpireHold($mismatch,1,'11111111-1111-1111-1111-111111111111',fn()=>['id'=>'other','paymentStatus'=>'PAYMENT_CANCELLED']),'mismatched checkout released slot');
echo "Maya reservation payment tests passed\n";
