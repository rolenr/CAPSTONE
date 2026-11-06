<?php
// Maya callback is a notification only. Payment is independently retrieved
// using the server's sandbox secret before a pass can be issued.
require_once __DIR__ . '/maya_reservation_payments.php';
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{}'; exit; }
try {
    $payload = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    $id = $payload['id'] ?? '';
    if (!is_string($id) || !preg_match('/^[a-f0-9-]{36}$/iD', $id)) {
        http_response_code(400); echo json_encode(['error'=>'Invalid payment ID']); exit;
    }
    $db = new PDO('sqlite:' . __DIR__ . '/../database/parking.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    paymentDb($db);
    $known = $db->prepare("SELECT 1 FROM reservation_payments WHERE provider='MAYA' AND checkout_session_id=?");
    $known->execute([$id]);
    if (!$known->fetchColumn()) { http_response_code(404); echo json_encode(['error'=>'Unknown payment']); exit; }
    mayaVerifyAndProcess($db, $id);
    echo json_encode(['received'=>true]);
} catch (Throwable $e) {
    error_log('Maya webhook: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error'=>'Webhook processing failed']);
}
