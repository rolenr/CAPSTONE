<?php
// Xendit Payment Sessions sandbox adapter. Local amounts remain centavos; Xendit PHP amounts are pesos.
require_once __DIR__ . '/reservation_payments.php';

function xenditConfigured(): bool {
    return str_starts_with(getenv('XENDIT_SECRET_KEY') ?: '', 'xnd_development_');
}

function xenditRequest(string $method, string $path, ?array $payload = null): array {
    $key = getenv('XENDIT_SECRET_KEY') ?: '';
    if (!xenditConfigured()) throw new RuntimeException('Xendit test secret key is not configured.');
    $headers = ['Accept: application/json', 'Authorization: Basic ' . base64_encode($key . ':')];
    if ($payload !== null) $headers[] = 'Content-Type: application/json';
    if (str_starts_with($path, '/v3/')) $headers[] = 'api-version: 2024-11-11';
    $ch = curl_init('https://api.xendit.co' . $path);
    if ($ch === false) throw new RuntimeException('Unable to initialize Xendit connection.');
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POSTFIELDS => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR)]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    if ($body === false) throw new RuntimeException('Xendit connection failed: ' . $error);
    $json = json_decode($body, true);
    if ($code < 200 || $code >= 300 || !is_array($json)) {
        throw new RuntimeException('Xendit returned HTTP ' . $code . ': ' . substr($body, 0, 500), $code);
    }
    return $json;
}

function xenditWebhookTokenValid(string $token): bool {
    $expected = getenv('XENDIT_CALLBACK_TOKEN') ?: '';
    return $expected !== '' && $token !== '' && hash_equals($expected, $token);
}

function xenditWebhookSessionId(array $payload): string {
    // Some dashboard test payloads use data.id; newer Payment Session callbacks
    // use data.payment_session_id. Remote retrieval still verifies the session.
    $id = $payload['data']['payment_session_id'] ?? $payload['data']['id'] ?? '';
    if (!is_string($id) || !preg_match('/^ps-[a-zA-Z0-9_-]+$/D', $id)) {
        throw new RuntimeException('Invalid session ID in callback.');
    }
    return $id;
}

function xenditCheckoutUrlValid(string $url): bool {
    $host = parse_url($url, PHP_URL_HOST);
    return parse_url($url, PHP_URL_SCHEME) === 'https' &&
        in_array($host, ['checkout-staging.xendit.co', 'dev.xen.to'], true);
}

function xenditPaymentTime(array $payment): string {
    $time = $payment['captures'][0]['capture_timestamp'] ?? $payment['updated'] ?? '';
    if (!is_string($time) || strtotime($time) === false) {
        throw new RuntimeException('Payment timestamp is missing.');
    }
    return gmdate('Y-m-d H:i:s', strtotime($time));
}

// Call only after retrieving both objects from Xendit server-to-server.
// Returns a payment ID when a late payment requires a refund.
function xenditProcessCompleted(PDO $db, array $session, array $payment): ?string {
    $sessionId = $session['payment_session_id'] ?? '';
    $paymentId = $session['payment_id'] ?? '';
    if (!is_string($sessionId) || !str_starts_with($sessionId, 'ps-') ||
        !is_string($paymentId) || !str_starts_with($paymentId, 'py-') ||
        ($session['status'] ?? '') !== 'COMPLETED' || ($session['session_type'] ?? '') !== 'PAY' ||
        ($session['mode'] ?? '') !== 'PAYMENT_LINK' || ($session['country'] ?? '') !== 'PH' ||
        ($session['currency'] ?? '') !== 'PHP' || ($payment['payment_id'] ?? '') !== $paymentId ||
        ($payment['status'] ?? '') !== 'SUCCEEDED' || ($payment['currency'] ?? '') !== 'PHP' ||
        ($payment['country'] ?? '') !== 'PH' || ($payment['type'] ?? '') !== 'PAY' ||
        ($session['business_id'] ?? '') !== ($payment['business_id'] ?? '')) {
        throw new RuntimeException('Xendit completed session/payment verification failed.');
    }
    $paidAt = xenditPaymentTime($payment);
    paymentBegin($db);
    try {
        $find = $db->prepare("SELECT p.*,r.status AS reservation_status,r.expiry_time FROM reservation_payments p
            JOIN reservations r ON r.reservation_id=p.reservation_id
            WHERE p.provider='XENDIT' AND p.checkout_session_id=?");
        $find->execute([$sessionId]);
        $p = $find->fetch(PDO::FETCH_ASSOC);
        if (!$p || ($session['reference_id'] ?? '') !== 'RES-' . $p['reservation_id'] ||
            (int)round((float)($session['amount'] ?? -1) * 100) !== (int)$p['amount_centavos'] ||
            (int)round((float)($payment['request_amount'] ?? -1) * 100) !== (int)$p['amount_centavos'] ||
            ($session['payment_request_id'] ?? '') !== ($payment['payment_request_id'] ?? '')) {
            throw new RuntimeException('Xendit session, amount, or payment mismatch.');
        }
        $eventId = $sessionId . ':' . $paymentId;
        $db->prepare("INSERT OR IGNORE INTO xendit_webhook_events(event_id,event_type) VALUES (?,'payment_session.completed')")
            ->execute([$eventId]);
        if (in_array($p['status'], ['PAID', 'LATE_PAID'], true)) {
            $db->commit();
            return $p['status'] === 'LATE_PAID' && !in_array($p['refund_status'], ['REQUESTED','SUCCEEDED','STAFF_ATTENTION'], true) ? $paymentId : null;
        }
        $late = $p['reservation_status'] !== 'PENDING_PAYMENT' || $paidAt > $p['expiry_time'];
        $db->prepare('UPDATE reservation_payments SET payment_id=?,status=?,paid_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$paymentId, $late ? 'LATE_PAID' : 'PAID', $paidAt, $p['id']]);
        if ($late) {
            $db->prepare("UPDATE reservations SET status='EXPIRED',payment_state='LATE_PAID',token_id=NULL WHERE reservation_id=? AND status='PENDING_PAYMENT'")
                ->execute([$p['reservation_id']]);
            paymentAudit($db, 'XENDIT', 'LATE_PAYMENT', (int)$p['reservation_id'], 'Payment completed after hold deadline; no pass issued; refund required.');
        } else {
            $token = 'QR_' . bin2hex(random_bytes(16));
            $db->prepare("UPDATE reservations SET status='ACTIVE',payment_state='PAID',token_id=? WHERE reservation_id=? AND status='PENDING_PAYMENT'")
                ->execute([$token, $p['reservation_id']]);
            paymentAudit($db, 'XENDIT', 'RESERVATION_PAYMENT_PAID', (int)$p['reservation_id'], 'Verified Xendit Payment Session and Payment succeeded.');
        }
        $db->commit();
        return $late ? $paymentId : null;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function xenditVerifyAndProcess(PDO $db, string $sessionId, ?callable $request = null): ?string {
    $request ??= 'xenditRequest';
    $session = $request('GET', '/sessions/' . rawurlencode($sessionId), null);
    if (($session['payment_session_id'] ?? '') !== $sessionId || ($session['status'] ?? '') !== 'COMPLETED' ||
        !is_string($session['payment_id'] ?? null)) throw new RuntimeException('Xendit session is not completed.');
    $payment = $request('GET', '/v3/payments/' . rawurlencode($session['payment_id']), null);
    return xenditProcessCompleted($db, $session, $payment);
}

function xenditRequestRefund(PDO $db, int $reservationId, string $paymentId, string $reason,
    ?callable $request = null): void {
    $request ??= 'xenditRequest';
    $find = $db->prepare("SELECT * FROM reservation_payments WHERE provider='XENDIT' AND reservation_id=? AND payment_id=?");
    $find->execute([$reservationId, $paymentId]);
    $p = $find->fetch(PDO::FETCH_ASSOC);
    if (!$p || in_array($p['refund_status'], ['REQUESTED','SUCCEEDED','STAFF_ATTENTION'], true)) return;
    $result = $request('POST', '/v3/refunds', [
        'reference_id' => 'RES-' . $reservationId . '-REFUND',
        'payment_id' => $paymentId,
        'amount' => (int)$p['amount_centavos'] / 100,
        'reason' => $reason
    ]);
    $refundId = $result['refund_id'] ?? '';
    $status = $result['status'] ?? '';
    if (!is_string($refundId) || !str_starts_with($refundId, 'rfd-') ||
        ($result['payment_id'] ?? '') !== $paymentId ||
        (int)round((float)($result['amount'] ?? -1) * 100) !== (int)$p['amount_centavos'] ||
        !in_array($status, ['PENDING','SUCCEEDED'], true)) throw new RuntimeException('Xendit refund result is incomplete.');
    $db->prepare('UPDATE reservation_payments SET refund_id=?,refund_status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
        ->execute([$refundId, $status === 'SUCCEEDED' ? 'SUCCEEDED' : 'REQUESTED', $p['id']]);
}

function xenditRefundLate(PDO $db, string $paymentId, ?callable $request = null): void {
    $request ??= 'xenditRequest';
    $find = $db->prepare("SELECT reservation_id,refund_status FROM reservation_payments WHERE provider='XENDIT' AND payment_id=? AND status='LATE_PAID'");
    $find->execute([$paymentId]);
    $p = $find->fetch(PDO::FETCH_ASSOC);
    if (!$p || in_array($p['refund_status'], ['REQUESTED','SUCCEEDED','STAFF_ATTENTION'], true)) return;
    try {
        xenditRequestRefund($db, (int)$p['reservation_id'], $paymentId, 'CANCELLATION', $request);
    } catch (Throwable $e) {
        $db->prepare("UPDATE reservation_payments SET refund_status='STAFF_ATTENTION',updated_at=CURRENT_TIMESTAMP WHERE reservation_id=?")
            ->execute([$p['reservation_id']]);
        paymentAudit($db, 'XENDIT', 'REFUND_FAILED', (int)$p['reservation_id'], substr($e->getMessage(), 0, 300));
    }
}

// Release only after Xendit confirms CANCELED/EXPIRED. A completed race is verified and processed.
function xenditExpireHold(PDO $db, int $id, string $sessionId, ?callable $request = null): bool {
    $request ??= 'xenditRequest';
    $path = '/sessions/' . rawurlencode($sessionId);
    try {
        $remote = $request('GET', $path, null);
        if (($remote['payment_session_id'] ?? '') !== $sessionId) throw new RuntimeException('Session ID mismatch.');
        if (($remote['status'] ?? '') === 'ACTIVE') {
            try { $remote = $request('POST', $path . '/cancel', []); }
            catch (Throwable $e) { $remote = $request('GET', $path, null); }
        }
        if (($remote['payment_session_id'] ?? '') !== $sessionId) throw new RuntimeException('Session ID mismatch after cancellation.');
        $state = $remote['status'] ?? '';
        if ($state === 'COMPLETED') {
            $lateId = xenditVerifyAndProcess($db, $sessionId, $request);
            if ($lateId) xenditRefundLate($db, $lateId, $request);
            return false;
        }
        if (!in_array($state, ['CANCELED','EXPIRED'], true)) throw new RuntimeException('Session remains ' . $state);
    } catch (Throwable $e) {
        error_log('Xendit hold ' . $id . ' remains held: ' . $e->getMessage());
        $db->prepare("UPDATE reservation_payments SET status='EXPIRY_RETRY',updated_at=CURRENT_TIMESTAMP WHERE reservation_id=? AND status IN ('PENDING','EXPIRY_RETRY')")
            ->execute([$id]);
        return false;
    }
    paymentBegin($db);
    try {
        $update = $db->prepare("UPDATE reservations SET status='EXPIRED',payment_state='EXPIRED',token_id=NULL WHERE reservation_id=? AND status='PENDING_PAYMENT'");
        $update->execute([$id]);
        if ($update->rowCount()) {
            $db->prepare("UPDATE reservation_payments SET status='EXPIRED',updated_at=CURRENT_TIMESTAMP WHERE reservation_id=? AND status IN ('PENDING','EXPIRY_RETRY')")
                ->execute([$id]);
            paymentAudit($db, 'SYSTEM', 'RESERVATION_HOLD_EXPIRED', $id, 'Xendit session canceled or expired before slot release.');
        }
        $db->commit();
        return $update->rowCount() > 0;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
