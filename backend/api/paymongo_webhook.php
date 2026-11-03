<?php
// Public endpoint: never use a browser session or CSRF token here.
require_once __DIR__ . '/reservation_payments.php';
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{}'; exit; }
$raw = file_get_contents('php://input');
$signature = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';
if (!paymentSignatureValid($raw, $signature)) {
    http_response_code(401); echo json_encode(['error' => 'Invalid signature']); exit;
}
try {
    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $db = new PDO('sqlite:' . __DIR__ . '/../database/parking.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    paymentDb($db);
    $latePayment = paymentProcessPaid($db, $payload);
    if ($latePayment) paymentRefundLate($db, $latePayment);
    echo json_encode(['received' => true]);
} catch (Throwable $e) {
    error_log('PayMongo webhook: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'Webhook processing failed']);
}
