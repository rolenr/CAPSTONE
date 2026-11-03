<?php
// Public Xendit Payment Session callback. A callback token authenticates the sender;
// the session and payment are retrieved server-to-server before a pass is issued.
require_once __DIR__ . '/xendit_reservation_payments.php';
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{}'; exit; }
if (!xenditWebhookTokenValid($_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? '')) {
    http_response_code(401); echo json_encode(['error'=>'Invalid callback token']); exit;
}
try {
    $payload = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    if (($payload['event'] ?? '') !== 'payment_session.completed') {
        echo json_encode(['received'=>true]); exit;
    }
    $sessionId = xenditWebhookSessionId($payload);
    $db = new PDO('sqlite:' . __DIR__ . '/../database/parking.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    paymentDb($db);
    try {
        $lateId = xenditVerifyAndProcess($db, $sessionId);
    } catch (RuntimeException $e) {
        // Dashboard "Test and save" can send a fabricated sample session. A
        // definitive remote 404 cannot confirm payment, so acknowledge only it.
        if ($e->getCode() !== 404) throw $e;
        error_log('Xendit webhook ignored unknown remote session: ' . $sessionId);
        echo json_encode(['received'=>true,'ignored'=>'unknown_session']);
        exit;
    }
    if ($lateId) xenditRefundLate($db, $lateId);
    echo json_encode(['received'=>true]);
} catch (Throwable $e) {
    error_log('Xendit webhook: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error'=>'Webhook processing failed']);
}
