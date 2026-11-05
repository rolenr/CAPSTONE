<?php
require_once __DIR__.'/../backend/api/local_reservation_payments.php';
date_default_timezone_set('UTC');
function assertLocal($ok,$message){if(!$ok)throw new RuntimeException($message);}
function localFixture($seconds=600){
 $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 $db->exec("CREATE TABLE reservations(reservation_id INTEGER PRIMARY KEY,slot_id INTEGER,expiry_time TEXT,status TEXT,payment_state TEXT,token_id TEXT,end_date TEXT);CREATE TABLE audit_logs(admin_email TEXT,action_type TEXT,target_entity TEXT,target_id TEXT,details TEXT)");paymentDb($db);
 $db->prepare("INSERT INTO reservations VALUES(1,1,?,'PENDING_PAYMENT','PENDING',NULL,'2099-01-01')")->execute([gmdate('Y-m-d H:i:s',time()+$seconds)]);
 $db->exec("INSERT INTO reservation_payments(reservation_id,provider,checkout_session_id,amount_centavos,status) VALUES(1,'LOCAL','local_test',6000,'PENDING')");return $db;
}
putenv('LOCAL_PAYMENTS_ENABLED=1');
$db=localFixture();assertLocal(localSettle($db,'local_test','decline','123456')==='DECLINED','decline failed');assertLocal($db->query('SELECT token_id FROM reservations')->fetchColumn()===null,'decline issued token');
try{localSettle($db,'local_test','success','000000');throw new RuntimeException('bad OTP accepted');}catch(InvalidArgumentException $e){}
assertLocal(localSettle($db,'local_test','success','123456')==='PAID','payment failed');$token=$db->query('SELECT token_id FROM reservations')->fetchColumn();assertLocal(str_starts_with($token,'QR_'),'missing pass');localSettle($db,'local_test','success','123456');assertLocal($db->query('SELECT token_id FROM reservations')->fetchColumn()===$token,'duplicate replaced pass');
assertLocal((int)$db->query("SELECT count(*) FROM audit_logs WHERE action_type='SIMULATED_PAYMENT_PAID'")->fetchColumn()===1,'duplicate charge audit');
$db=localFixture(-1);assertLocal(localSettle($db,'local_test','success','123456')==='EXPIRED','late payment accepted');assertLocal($db->query('SELECT token_id FROM reservations')->fetchColumn()===null,'late pass');
$db=localFixture();assertLocal(localSettle($db,'local_test','cancel','')==='CANCELLED','cancel failed');assertLocal(localSettle($db,'local_test','success','123456')==='CANCELLED','cancel replay issued pass');
$db=localFixture(-1);localExpireHolds($db);assertLocal($db->query('SELECT status FROM reservations')->fetchColumn()==='EXPIRED','worker expiry failed');
putenv('LOCAL_PAYMENTS_ENABLED=0');try{localSettle(localFixture(),'local_test','success','123456');throw new LogicException('disabled accepted');}catch(RuntimeException $e){}
echo "Local simulated payment tests passed\n";
