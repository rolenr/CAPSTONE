<?php
// Run from CLI after webhook interruptions and before considering production use.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/xendit_reservation_payments.php';
date_default_timezone_set('UTC');
$db = new PDO('sqlite:' . __DIR__ . '/../database/parking.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
paymentDb($db);
$rows = $db->query("SELECT p.*,r.status AS reservation_status,r.expiry_time FROM reservation_payments p
    JOIN reservations r ON r.reservation_id=p.reservation_id WHERE p.provider='XENDIT'")->fetchAll(PDO::FETCH_ASSOC);
$issues = 0;
foreach ($rows as $p) {
    $id = (int)$p['reservation_id'];
    if (!$p['checkout_session_id']) {
        if (in_array($p['status'], ['CREATING','CHECKOUT_UNKNOWN'], true)) {
            $issues++; echo "UNKNOWN Xendit checkout for reservation #{$id}; inspect reference RES-{$id}\n";
        }
        continue;
    }
    try {
        $session = xenditRequest('GET', '/sessions/' . rawurlencode($p['checkout_session_id']));
        if (($session['payment_session_id'] ?? '') !== $p['checkout_session_id'] ||
            ($session['reference_id'] ?? '') !== 'RES-' . $id) throw new RuntimeException('Session identity mismatch.');
        $state = $session['status'] ?? '';
        if ($state === 'COMPLETED') {
            $lateId = xenditVerifyAndProcess($db, $p['checkout_session_id']);
            if ($lateId) xenditRefundLate($db, $lateId);
            echo "Verified completed Xendit session for reservation #{$id}\n";
        } elseif (in_array($state, ['CANCELED','EXPIRED'], true) && $p['reservation_status'] === 'PENDING_PAYMENT') {
            xenditExpireHold($db, $id, $p['checkout_session_id']);
            echo "Released ended Xendit session for reservation #{$id}\n";
        } elseif ($state === 'ACTIVE' && $p['reservation_status'] === 'PENDING_PAYMENT' &&
            $p['expiry_time'] <= gmdate('Y-m-d H:i:s')) {
            $issues++; echo "OVERDUE Xendit hold #{$id}; run expiry worker\n";
        } elseif ($state === 'ACTIVE' && $p['reservation_status'] === 'EXPIRED') {
            $issues++; echo "MISMATCH reservation #{$id}: local EXPIRED, Xendit session ACTIVE\n";
        }
        if ($p['refund_id'] && in_array($p['refund_status'], ['REQUESTED','REVIEWING'], true)) {
            $refund = xenditRequest('GET', '/v3/refunds/' . rawurlencode($p['refund_id']));
            if (($refund['refund_id'] ?? '') !== $p['refund_id'] ||
                ($refund['payment_id'] ?? '') !== $p['payment_id'] ||
                (int)round((float)($refund['amount'] ?? -1) * 100) !== (int)$p['amount_centavos']) {
                throw new RuntimeException('Refund identity or amount mismatch.');
            }
            if (($refund['status'] ?? '') === 'SUCCEEDED') {
                $db->prepare("UPDATE reservation_payments SET refund_status='SUCCEEDED',updated_at=CURRENT_TIMESTAMP WHERE reservation_id=?")
                    ->execute([$id]);
            } elseif (($refund['status'] ?? '') === 'FAILED') {
                $db->prepare("UPDATE reservation_payments SET refund_status='STAFF_ATTENTION',updated_at=CURRENT_TIMESTAMP WHERE reservation_id=?")
                    ->execute([$id]);
                $issues++; echo "FAILED Xendit refund for reservation #{$id}\n";
            }
        }
    } catch (Throwable $e) {
        $issues++; echo "ERROR reservation #{$id}: {$e->getMessage()}\n";
    }
}
echo 'Checked ' . count($rows) . " Xendit sandbox checkouts; {$issues} issue(s).\n";
exit($issues ? 1 : 0);
