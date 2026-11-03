<?php
// Sandbox reconciliation: php backend/api/reconcile_reservation_payments.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/reservation_payments.php';
$db = new PDO('sqlite:' . __DIR__ . '/../database/parking.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
paymentDb($db);
$issues = 0;
$rows = $db->query("SELECT p.*,r.status AS reservation_status,r.payment_state FROM reservation_payments p
    JOIN reservations r ON r.reservation_id=p.reservation_id WHERE p.provider='PAYMONGO'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $p) {
    if (!$p['checkout_session_id']) {
        if (in_array($p['status'], ['CHECKOUT_UNKNOWN','CREATING'], true)) {
            $issues++; echo "UNKNOWN checkout for reservation #{$p['reservation_id']}; review PayMongo by reference RES-{$p['reservation_id']}\n";
        }
        continue;
    }
    try {
        $remote = paymentRequest('GET', '/v1/checkout_sessions/' . rawurlencode($p['checkout_session_id']));
        $attributes = $remote['data']['attributes'] ?? [];
        $paid = null;
        foreach (($attributes['payments'] ?? []) as $candidate) {
            if (($candidate['attributes']['status'] ?? '') === 'paid') { $paid = $candidate; break; }
        }
        $localPaid = in_array($p['status'], ['PAID','LATE_PAID'], true);
        if ((bool)$paid !== $localPaid || ($paid && ($paid['id'] ?? '') !== $p['payment_id'])) {
            $issues++;
            echo "MISMATCH reservation #{$p['reservation_id']}: local {$p['status']}, remote " . ($paid['id'] ?? 'unpaid') . "\n";
        }
        $remoteStatus = $attributes['status'] ?? '';
        if (($remoteStatus === 'expired' && $p['reservation_status'] === 'PENDING_PAYMENT') ||
            ($remoteStatus === 'active' && $p['reservation_status'] === 'EXPIRED' && !$paid)) {
            $issues++;
            echo "MISMATCH checkout state for reservation #{$p['reservation_id']}: local {$p['reservation_status']}, remote {$remoteStatus}\n";
        }
        if ($p['refund_id'] && in_array($p['refund_status'], ['REQUESTED','REVIEWING'], true)) {
            $refund = paymentRequest('GET', '/v1/refunds/' . rawurlencode($p['refund_id']));
            $r = $refund['data']['attributes'] ?? [];
            if (($r['payment_id'] ?? '') !== $p['payment_id'] || (int)($r['amount'] ?? 0) !== (int)$p['amount_centavos']) {
                $issues++; echo "MISMATCH refund for reservation #{$p['reservation_id']}\n";
                continue;
            }
            if (($r['status'] ?? '') === 'succeeded') {
                $db->prepare("UPDATE reservation_payments SET refund_status='SUCCEEDED',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$p['id']]);
            } elseif (($r['status'] ?? '') === 'failed') {
                $db->prepare("UPDATE reservation_payments SET refund_status='STAFF_ATTENTION',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$p['id']]);
                $issues++; echo "FAILED refund for reservation #{$p['reservation_id']}\n";
            }
        }
    } catch (Throwable $e) {
        $issues++; echo "ERROR reservation #{$p['reservation_id']}: {$e->getMessage()}\n";
    }
}
echo "Checked " . count($rows) . " sandbox checkouts; {$issues} issue(s).\n";
exit($issues ? 1 : 0);
