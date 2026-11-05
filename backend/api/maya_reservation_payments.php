<?php
// Maya Checkout sandbox adapter. Local amounts are centavos; Maya amounts are PHP.
require_once __DIR__ . '/reservation_payments.php';

function mayaConfigured(): bool {
    return str_starts_with(getenv('MAYA_PUBLIC_KEY') ?: '', 'pk-') &&
        str_starts_with(getenv('MAYA_SECRET_KEY') ?: '', 'sk-');
}

function mayaRequest(string $method, string $path, ?array $payload = null): array {
    if (!mayaConfigured()) throw new RuntimeException('Maya sandbox keys are not configured.');
    $key = str_starts_with($path, '/checkout/') ? getenv('MAYA_PUBLIC_KEY') : getenv('MAYA_SECRET_KEY');
    $ch = curl_init('https://pg-sandbox.paymaya.com' . $path);
    if ($ch === false) throw new RuntimeException('Unable to initialize Maya connection.');
    $headers = ['Accept: application/json', 'Authorization: Basic ' . base64_encode($key . ':')];
    if ($payload !== null) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20, CURLOPT_CONNECTTIMEOUT=>5]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    if ($body === false) throw new RuntimeException('Maya connection failed: ' . $error);
    $json = json_decode($body, true);
    if ($code < 200 || $code >= 300 || !is_array($json))
        throw new RuntimeException('Maya returned HTTP ' . $code . ': ' . substr($body, 0, 500), $code);
    return $json;
}

function mayaCheckoutUrlValid(string $url): bool {
    return parse_url($url, PHP_URL_SCHEME) === 'https' &&
        parse_url($url, PHP_URL_HOST) === 'payments-web-sandbox.paymaya.com';
}

function mayaPaymentStatus(array $remote): string {
    return (string)($remote['paymentStatus'] ?? '');
}

function mayaPaymentTime(array $remote): string {
    $value = $remote['paymentDetails']['paymentAt'] ?? $remote['updatedAt'] ?? null;
    if (!is_string($value) || strtotime($value) === false)
        throw new RuntimeException('Maya payment time is missing.');
    return gmdate('Y-m-d H:i:s', strtotime($value));
}

// Only a server-to-server GET payment response may activate a reservation.
function mayaProcessPayment(PDO $db, array $remote): ?string {
    $id = $remote['id'] ?? '';
    if (!is_string($id) || $id === '') throw new RuntimeException('Maya payment ID missing.');
    $status = mayaPaymentStatus($remote);
    if ($status !== 'PAYMENT_SUCCESS') return null;
    $paidAt = mayaPaymentTime($remote);
    paymentBegin($db);
    try {
        $find = $db->prepare("SELECT p.*,r.status AS reservation_status,r.expiry_time FROM reservation_payments p
            JOIN reservations r ON r.reservation_id=p.reservation_id WHERE p.provider='MAYA' AND p.checkout_session_id=?");
        $find->execute([$id]);
        $p = $find->fetch(PDO::FETCH_ASSOC);
        $amount = $remote['totalAmount']['value'] ?? null;
        if (!$p || ($remote['requestReferenceNumber'] ?? '') !== 'RES-' . $p['reservation_id'] ||
            ($remote['totalAmount']['currency'] ?? '') !== 'PHP' || !is_numeric($amount) ||
            (int)round((float)$amount * 100) !== (int)$p['amount_centavos'] ||
            ($remote['status'] ?? '') !== 'COMPLETED')
            throw new RuntimeException('Maya payment identity or amount mismatch.');
        $db->prepare("INSERT OR IGNORE INTO maya_webhook_events(event_id,event_type) VALUES (?,?)")
            ->execute([$id . ':PAYMENT_SUCCESS', 'PAYMENT_SUCCESS']);
        if (in_array($p['status'], ['PAID','LATE_PAID'], true)) {
            $db->commit();
            return $p['status'] === 'LATE_PAID' && $p['refund_status'] === null ? $id : null;
        }
        $late = $p['reservation_status'] !== 'PENDING_PAYMENT' || $paidAt > $p['expiry_time'];
        $db->prepare('UPDATE reservation_payments SET payment_id=?,status=?,paid_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$id, $late ? 'LATE_PAID' : 'PAID', $paidAt, $p['id']]);
        if ($late) {
            $db->prepare("UPDATE reservations SET status='EXPIRED',payment_state='LATE_PAID',token_id=NULL WHERE reservation_id=? AND status='PENDING_PAYMENT'")
                ->execute([$p['reservation_id']]);
            $db->prepare("UPDATE reservation_payments SET refund_status='STAFF_REVIEW' WHERE id=?")->execute([$p['id']]);
            paymentAudit($db, 'MAYA', 'LATE_PAYMENT', (int)$p['reservation_id'], 'Payment after hold deadline; no pass; staff must void/refund in Maya.');
        } else {
            $token = 'QR_' . bin2hex(random_bytes(16));
            $db->prepare("UPDATE reservations SET status='ACTIVE',payment_state='PAID',token_id=? WHERE reservation_id=? AND status='PENDING_PAYMENT'")
                ->execute([$token, $p['reservation_id']]);
            paymentAudit($db, 'MAYA', 'RESERVATION_PAYMENT_PAID', (int)$p['reservation_id'], 'Maya sandbox payment independently verified.');
        }
        $db->commit();
        return $late ? $id : null;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function mayaVerifyAndProcess(PDO $db, string $id, ?callable $request = null): ?string {
    $request ??= 'mayaRequest';
    $remote = $request('GET', '/payments/v1/payments/' . rawurlencode($id), null);
    if (($remote['id'] ?? '') !== $id) throw new RuntimeException('Maya payment ID mismatch.');
    return mayaProcessPayment($db, $remote);
}

// A pending Maya checkout is valid for one hour. Never release a local hold until
// Maya confirms cancellation/expiry; processing payments keep their slot held.
function mayaExpireHold(PDO $db, int $reservationId, string $id, ?callable $request = null): bool {
    $request ??= 'mayaRequest';
    $path = '/payments/v1/payments/' . rawurlencode($id);
    try {
        $remote = $request('GET', $path, null);
        if (($remote['id'] ?? '') !== $id) throw new RuntimeException('Maya payment ID mismatch.');
        $status = mayaPaymentStatus($remote);
        if ($status === 'PAYMENT_SUCCESS') { mayaProcessPayment($db, $remote); return false; }
        if (!in_array($status, ['PAYMENT_CANCELLED','PAYMENT_EXPIRED'], true)) {
            try { $request('POST', $path . '/cancel', []); }
            catch (Throwable $ignored) { /* Re-read authoritative status below. */ }
            $remote = $request('GET', $path, null);
            if (($remote['id'] ?? '') !== $id) throw new RuntimeException('Maya payment ID changed after cancel.');
            $status = mayaPaymentStatus($remote);
            if ($status === 'PAYMENT_SUCCESS') { mayaProcessPayment($db, $remote); return false; }
        }
        if (!in_array($status, ['PAYMENT_CANCELLED','PAYMENT_EXPIRED'], true))
            throw new RuntimeException('Maya payment remains ' . $status);
    } catch (Throwable $e) {
        error_log('Maya hold ' . $reservationId . ' remains held: ' . $e->getMessage());
        $db->prepare("UPDATE reservation_payments SET status='EXPIRY_RETRY',updated_at=CURRENT_TIMESTAMP WHERE reservation_id=? AND status IN ('PENDING','EXPIRY_RETRY')")
            ->execute([$reservationId]);
        return false;
    }
    paymentBegin($db);
    try {
        $update = $db->prepare("UPDATE reservations SET status='EXPIRED',payment_state='EXPIRED',token_id=NULL WHERE reservation_id=? AND status='PENDING_PAYMENT'");
        $update->execute([$reservationId]);
        if ($update->rowCount()) {
            $db->prepare("UPDATE reservation_payments SET status='EXPIRED',updated_at=CURRENT_TIMESTAMP WHERE reservation_id=? AND status IN ('PENDING','EXPIRY_RETRY')")
                ->execute([$reservationId]);
            paymentAudit($db, 'SYSTEM', 'RESERVATION_HOLD_EXPIRED', $reservationId, 'Maya checkout canceled or expired before slot release.');
        }
        $db->commit();
        return $update->rowCount() > 0;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
