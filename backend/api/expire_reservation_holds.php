<?php
// Run once a minute from cron: php backend/api/expire_reservation_holds.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/local_reservation_payments.php';
date_default_timezone_set('UTC');
$db = new PDO('sqlite:' . __DIR__ . '/../database/parking.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
paymentDb($db);
localExpireHolds($db);
