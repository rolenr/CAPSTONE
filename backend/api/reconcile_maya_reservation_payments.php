<?php
// Compare local Maya records with the authoritative sandbox payment API.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/maya_reservation_payments.php';
date_default_timezone_set('UTC');
$db = new PDO('sqlite:' . __DIR__ . '/../database/parking.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
paymentDb($db);
$rows = $db->query("SELECT p.*,r.status AS reservation_status,r.expiry_time FROM reservation_payments p
    JOIN reservations r ON r.reservation_id=p.reservation_id WHERE p.provider='MAYA'")->fetchAll(PDO::FETCH_ASSOC);
$issues = 0;
foreach ($rows as $p) {
    $id = (int)$p['reservation_id'];
    if (!$p['checkout_session_id']) {
        $issues++;
        echo "UNKNOWN Maya checkout for reservation #{$id}; inspect reference RES-{$id}\n";
        continue;
    }
    try {
        $remote = mayaRequest('GET', '/payments/v1/payments/' . rawurlencode($p['checkout_session_id']));
        if (($remote['id'] ?? '') !== $p['checkout_session_id'] ||
            ($remote['requestReferenceNumber'] ?? '') !== 'RES-' . $id)
            throw new RuntimeException('Payment identity mismatch.');
        $status = mayaPaymentStatus($remote);
        if ($status === 'PAYMENT_SUCCESS') {
            mayaProcessPayment($db, $remote);
            echo "Verified Maya payment for reservation #{$id}\n";
        } elseif (in_array($status, ['PAYMENT_CANCELLED','PAYMENT_EXPIRED'], true) && $p['reservation_status'] === 'PENDING_PAYMENT') {
            mayaExpireHold($db, $id, $p['checkout_session_id']);
            echo "Released ended Maya checkout for reservation #{$id}\n";
        } elseif ($p['reservation_status'] === 'PENDING_PAYMENT' && $p['expiry_time'] <= gmdate('Y-m-d H:i:s')) {
            $issues++; echo "OVERDUE Maya hold #{$id}; run expiry worker\n";
        }
        if ($p['refund_status'] === 'STAFF_REVIEW' || $p['refund_status'] === 'STAFF_ATTENTION') {
            $issues++; echo "REVIEW Maya void/refund for reservation #{$id} in Maya Manager\n";
        }
    } catch (Throwable $e) {
        $issues++; echo "ERROR reservation #{$id}: {$e->getMessage()}\n";
    }
}
echo 'Checked ' . count($rows) . " Maya sandbox checkouts; {$issues} issue(s).\n";
exit($issues ? 1 : 0);
