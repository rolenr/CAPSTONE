<?php
require_once __DIR__ . '/../backend/api/xendit_reservation_payments.php';
date_default_timezone_set('UTC');
function checkX(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function xFixture(string $deadline): PDO {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE reservations(reservation_id INTEGER PRIMARY KEY,slot_id INTEGER,expiry_time TEXT,status TEXT,payment_state TEXT,token_id TEXT,end_date TEXT);
        CREATE TABLE audit_logs(log_id INTEGER PRIMARY KEY,admin_email TEXT,action_type TEXT,target_entity TEXT,target_id TEXT,details TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    paymentDb($db);
    $db->prepare("INSERT INTO reservations VALUES(1,10,?,'PENDING_PAYMENT','PENDING',NULL,'2026-11-04')")->execute([$deadline]);
    $db->exec("INSERT INTO reservation_payments(reservation_id,provider,checkout_session_id,amount_centavos,status)
        VALUES(1,'XENDIT','ps-test',6000,'PENDING')");
    return $db;
}
function xObjects(int $paidAt, int $amount = 60): array {
    $time = gmdate('Y-m-d\TH:i:s\Z', $paidAt);
    return [[
        'payment_session_id'=>'ps-test','reference_id'=>'RES-1','status'=>'COMPLETED',
        'session_type'=>'PAY','mode'=>'PAYMENT_LINK','country'=>'PH','currency'=>'PHP',
        'amount'=>$amount,'payment_id'=>'py-test','payment_request_id'=>'pr-test','business_id'=>'biz-test'
    ],[
        'payment_id'=>'py-test','payment_request_id'=>'pr-test','business_id'=>'biz-test',
        'status'=>'SUCCEEDED','country'=>'PH','currency'=>'PHP','type'=>'PAY',
        'request_amount'=>$amount,'captures'=>[['capture_timestamp'=>$time]]
    ]];
}

putenv('XENDIT_CALLBACK_TOKEN=callback-test');
checkX(xenditWebhookTokenValid('callback-test'), 'valid callback token rejected');
checkX(!xenditWebhookTokenValid('wrong'), 'forged callback accepted');
checkX(xenditWebhookSessionId(['data'=>['payment_session_id'=>'ps-test']]) === 'ps-test', 'current callback session ID rejected');
checkX(xenditWebhookSessionId(['data'=>['id'=>'ps-test']]) === 'ps-test', 'dashboard callback session ID rejected');
checkX(xenditCheckoutUrlValid('https://checkout-staging.xendit.co/sessions/ps-test'), 'sandbox URL rejected');
checkX(!xenditCheckoutUrlValid('https://checkout.xendit.co/sessions/ps-test'), 'live checkout URL accepted');

$now = time();
$db = xFixture(gmdate('Y-m-d H:i:s', $now+600));
[$session,$payment] = xObjects($now);
checkX(xenditProcessCompleted($db,$session,$payment) === null, 'timely payment reported as late');
checkX($db->query("SELECT status||':'||payment_state FROM reservations WHERE reservation_id=1")->fetchColumn() === 'ACTIVE:PAID', 'paid booking not activated');
checkX(str_starts_with($db->query('SELECT token_id FROM reservations WHERE reservation_id=1')->fetchColumn(), 'QR_'), 'paid pass missing');
xenditProcessCompleted($db,$session,$payment);
checkX((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action_type='RESERVATION_PAYMENT_PAID'")->fetchColumn() === 1, 'duplicate payment issued pass twice');

$delayed = xFixture(gmdate('Y-m-d H:i:s', $now-1));
[$s,$p] = xObjects($now-60);
xenditProcessCompleted($delayed,$s,$p);
checkX($delayed->query('SELECT payment_state FROM reservations')->fetchColumn() === 'PAID', 'delayed callback rejected timely capture');

$late = xFixture(gmdate('Y-m-d H:i:s', $now-1));
checkX(xenditProcessCompleted($late,$session,$payment) === 'py-test', 'late payment not identified');
checkX($late->query('SELECT token_id FROM reservations')->fetchColumn() === null, 'late payment issued pass');
$refundRequest = null;
xenditRequestRefund($late,1,'py-test','CANCELLATION',function($method,$path,$body) use (&$refundRequest) {
    $refundRequest = $body;
    return ['refund_id'=>'rfd-test','payment_id'=>'py-test','amount'=>60,'status'=>'PENDING'];
});
checkX($refundRequest['amount'] === 60, 'refund sent centavos rather than PHP');
checkX($late->query('SELECT refund_status FROM reservation_payments')->fetchColumn() === 'REQUESTED', 'refund request not saved');

$wrong = xFixture(gmdate('Y-m-d H:i:s', $now+600));
[$s,$p] = xObjects($now,1);
try { xenditProcessCompleted($wrong,$s,$p); throw new RuntimeException('wrong amount accepted'); }
catch (RuntimeException $e) { checkX($e->getMessage() !== 'wrong amount accepted','amount mismatch accepted'); }
checkX((int)$wrong->query('SELECT COUNT(*) FROM xendit_webhook_events')->fetchColumn() === 0,'bad event recorded');

$abandoned = xFixture(gmdate('Y-m-d H:i:s', $now-1));
$request = function($method,$path,$body) {
    if ($method === 'POST') return ['payment_session_id'=>'ps-test','status'=>'CANCELED'];
    return ['payment_session_id'=>'ps-test','status'=>'ACTIVE'];
};
checkX(xenditExpireHold($abandoned,1,'ps-test',$request), 'canceled session did not release hold');
checkX($abandoned->query('SELECT status FROM reservations')->fetchColumn() === 'EXPIRED', 'slot retained after cancellation');

$processing = xFixture(gmdate('Y-m-d H:i:s', $now-1));
$request = function($method,$path,$body) {
    if ($method === 'POST') throw new RuntimeException('payment processing');
    return ['payment_session_id'=>'ps-test','status'=>'ACTIVE'];
};
checkX(!xenditExpireHold($processing,1,'ps-test',$request), 'processing session released slot');
checkX($processing->query('SELECT status FROM reservations')->fetchColumn() === 'PENDING_PAYMENT', 'processing hold changed');

$lateRefundFailure = xFixture(gmdate('Y-m-d H:i:s', $now-1));
xenditProcessCompleted($lateRefundFailure,$session,$payment);
xenditRefundLate($lateRefundFailure,'py-test',fn() => throw new RuntimeException('refund unavailable'));
checkX($lateRefundFailure->query('SELECT refund_status FROM reservation_payments')->fetchColumn() === 'STAFF_ATTENTION','refund failure not escalated');
$retryCount = 0;
xenditRefundLate($lateRefundFailure,'py-test',function() use (&$retryCount) { $retryCount++; throw new RuntimeException('unexpected automatic retry'); });
checkX($retryCount === 0, 'staff-attention refund retried automatically');
checkX(xenditProcessCompleted($lateRefundFailure,$session,$payment) === null, 'duplicate late webhook retried staff-attention refund');

$wrongCancel = xFixture(gmdate('Y-m-d H:i:s', $now-1));
checkX(!xenditExpireHold($wrongCancel,1,'ps-test',function($method) {
    return $method === 'POST' ? ['payment_session_id'=>'ps-other','status'=>'CANCELED'] : ['payment_session_id'=>'ps-test','status'=>'ACTIVE'];
}), 'mismatched cancellation released hold');
checkX($wrongCancel->query('SELECT status FROM reservations')->fetchColumn() === 'PENDING_PAYMENT', 'mismatched session changed reservation');
echo "Xendit reservation payment tests passed\n";
