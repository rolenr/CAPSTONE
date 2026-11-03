<?php
// Shared reservation-payment rules. Timestamps in this module are UTC.
function paymentDb(PDO $db): void {
    $db->exec('PRAGMA busy_timeout = 10000');
    $columns = array_column($db->query('PRAGMA table_info(reservations)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('payment_state', $columns, true)) {
        $db->exec("ALTER TABLE reservations ADD COLUMN payment_state TEXT NOT NULL DEFAULT 'UNVERIFIED'");
    }
    $db->exec("CREATE TABLE IF NOT EXISTS reservation_payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT, reservation_id INTEGER NOT NULL UNIQUE,
        provider TEXT NOT NULL DEFAULT 'PAYMONGO',
        checkout_session_id TEXT UNIQUE, checkout_url TEXT, payment_id TEXT UNIQUE,
        amount_centavos INTEGER NOT NULL, currency TEXT NOT NULL DEFAULT 'PHP',
        status TEXT NOT NULL DEFAULT 'CREATING', refund_status TEXT,
        refund_id TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        paid_at TEXT, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(reservation_id) REFERENCES reservations(reservation_id))");
    $db->exec("CREATE TABLE IF NOT EXISTS paymongo_webhook_events (
        event_id TEXT PRIMARY KEY, event_type TEXT NOT NULL,
        processed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $paymentColumns = array_column($db->query('PRAGMA table_info(reservation_payments)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('provider', $paymentColumns, true)) {
        $db->exec("ALTER TABLE reservation_payments ADD COLUMN provider TEXT NOT NULL DEFAULT 'PAYMONGO'");
    }
    $db->exec("CREATE TABLE IF NOT EXISTS xendit_webhook_events (
        event_id TEXT PRIMARY KEY, event_type TEXT NOT NULL,
        processed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_reservations_hold ON reservations(status, expiry_time)");
    // Old ACTIVE records have no verified payment. Expire only dated, unfulfilled ones.
    $legacy = $db->query("SELECT reservation_id FROM reservations WHERE status='ACTIVE'
        AND payment_state='UNVERIFIED' AND date(end_date) < date('now')")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($legacy as $id) {
        $db->prepare("UPDATE reservations SET status='EXPIRED', token_id=NULL WHERE reservation_id=?")->execute([$id]);
        paymentAudit($db, 'SYSTEM', 'LEGACY_UNPAID_EXPIRED', (int)$id, 'Past-dated ACTIVE reservation had no verified payment; pass revoked during payment migration.');
    }
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_one_open_booking_per_slot ON reservations(slot_id)
        WHERE status IN ('ACTIVE','PENDING_PAYMENT')");
}

function paymentRequest(string $method, string $path, ?array $payload = null): array {
    $key = getenv('PAYMONGO_SECRET_KEY') ?: '';
    if (!str_starts_with($key, 'sk_test_')) throw new RuntimeException('PayMongo sandbox key is not configured.');
    $url = 'https://api.paymongo.com' . $path;
    $headers = ['Accept: application/json', 'Authorization: Basic ' . base64_encode($key . ':')];
    if ($payload !== null) $headers[] = 'Content-Type: application/json';
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('Unable to initialize PayMongo connection.');
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POSTFIELDS => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR)]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    if ($body === false) throw new RuntimeException('PayMongo connection failed: ' . $error);
    $json = json_decode($body, true);
    if ($code < 200 || $code >= 300 || !is_array($json)) {
        throw new RuntimeException('PayMongo returned HTTP ' . $code . ': ' . substr($body, 0, 500), $code);
    }
    return $json;
}

function paymentSignatureValid(string $body, string $header): bool {
    $secret = getenv('PAYMONGO_WEBHOOK_SECRET') ?: '';
    if ($secret === '') return false;
    $parts = [];
    foreach (explode(',', $header) as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) === 2) $parts[$pair[0]] = $pair[1];
    }
    $timestamp = $parts['t'] ?? '';
    if (!ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300) return false;
    $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    return isset($parts['te']) && hash_equals($expected, $parts['te']);
}

function paymentBegin(PDO $db): void { $db->exec('BEGIN IMMEDIATE'); }

function paymentAudit(PDO $db, string $actor, string $action, int $reservationId, string $details): void {
    $stmt = $db->prepare('INSERT INTO audit_logs(admin_email,action_type,target_entity,target_id,details) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$actor, $action, 'reservation', $reservationId, $details]);
}

// Must be called after a signed webhook. Returns a payment ID for a late refund, if needed.
function paymentProcessPaid(PDO $db, array $event): ?string {
    $envelope = $event['data'] ?? [];
    $attributes = $envelope['attributes'] ?? [];
    if (($attributes['type'] ?? '') !== 'checkout_session.payment.paid' || ($attributes['livemode'] ?? true) !== false) return null;
    $session = $attributes['data'] ?? [];
    $sessionId = $session['id'] ?? '';
    $eventId = $envelope['id'] ?? '';
    $paid = null;
    foreach (($session['attributes']['payments'] ?? []) as $attempt) {
        if (($attempt['attributes']['status'] ?? '') === 'paid') { $paid = $attempt; break; }
    }
    if (!$eventId || !$sessionId || !$paid || !isset($paid['id'])) throw new RuntimeException('Incomplete paid checkout event.');
    paymentBegin($db);
    try {
        $exists = $db->prepare('SELECT 1 FROM paymongo_webhook_events WHERE event_id = ?');
        $exists->execute([$eventId]);
        if ($exists->fetchColumn()) { $db->commit(); return null; }
        $find = $db->prepare("SELECT p.*, r.status AS reservation_status, r.expiry_time FROM reservation_payments p JOIN reservations r ON r.reservation_id = p.reservation_id WHERE p.provider='PAYMONGO' AND p.checkout_session_id = ?");
        $find->execute([$sessionId]);
        $p = $find->fetch(PDO::FETCH_ASSOC);
        if (!$p || ($session['attributes']['reference_number'] ?? '') !== 'RES-' . $p['reservation_id'] ||
            (int)$paid['attributes']['amount'] !== (int)$p['amount_centavos'] ||
            ($paid['attributes']['currency'] ?? '') !== 'PHP') throw new RuntimeException('Checkout/payment mismatch.');
        $db->prepare('INSERT INTO paymongo_webhook_events(event_id,event_type) VALUES (?, ?)')->execute([$eventId, 'checkout_session.payment.paid']);
        if (in_array($p['status'], ['PAID', 'LATE_PAID'], true)) { $db->commit(); return null; }
        $now = gmdate('Y-m-d H:i:s');
        $eventTime = $attributes['created_at'] ?? null;
        $paidAt = is_numeric($eventTime) ? gmdate('Y-m-d H:i:s', (int)$eventTime)
            : (is_string($eventTime) && strtotime($eventTime) !== false ? gmdate('Y-m-d H:i:s', strtotime($eventTime)) : $now);
        $late = $p['reservation_status'] !== 'PENDING_PAYMENT' || $paidAt > $p['expiry_time'];
        $db->prepare("UPDATE reservation_payments SET payment_id=?, status=?, paid_at=?, updated_at=? WHERE id=?")
            ->execute([$paid['id'], $late ? 'LATE_PAID' : 'PAID', $paidAt, $now, $p['id']]);
        if ($late) {
            $db->prepare("UPDATE reservations SET status='EXPIRED', payment_state='LATE_PAID', token_id=NULL WHERE reservation_id=? AND status='PENDING_PAYMENT'")->execute([$p['reservation_id']]);
            paymentAudit($db, 'PAYMONGO', 'LATE_PAYMENT', (int)$p['reservation_id'], 'Late payment; no pass issued; full refund required.');
        } else {
            $token = 'QR_' . bin2hex(random_bytes(16));
            $db->prepare("UPDATE reservations SET status='ACTIVE', payment_state='PAID', token_id=? WHERE reservation_id=? AND status='PENDING_PAYMENT'")->execute([$token, $p['reservation_id']]);
            paymentAudit($db, 'PAYMONGO', 'RESERVATION_PAYMENT_PAID', (int)$p['reservation_id'], 'Signed checkout payment confirmed.');
        }
        $db->commit();
        return $late ? $paid['id'] : null;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function paymentRefundLate(PDO $db, string $paymentId): void {
    $get = $db->prepare("SELECT * FROM reservation_payments WHERE provider='PAYMONGO' AND payment_id=? AND status=?");
    $get->execute([$paymentId, 'LATE_PAID']);
    $p = $get->fetch(PDO::FETCH_ASSOC);
    if (!$p || in_array($p['refund_status'], ['REQUESTED', 'SUCCEEDED'], true)) return;
    try {
        $result = paymentRequest('POST', '/v1/refunds', ['data' => ['attributes' => [
            'amount' => (int)$p['amount_centavos'], 'payment_id' => $paymentId,
            'reason' => 'others', 'notes' => 'Reservation hold expired before payment confirmation'
        ]]]);
        $refundId = $result['data']['id'] ?? '';
        $refundState = $result['data']['attributes']['status'] ?? '';
        if (!str_starts_with($refundId, 'ref_') || $refundState === 'failed') throw new RuntimeException('Refund was not accepted.');
        $db->prepare("UPDATE reservation_payments SET refund_status=?, refund_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$refundState === 'succeeded' ? 'SUCCEEDED' : 'REQUESTED', $refundId, $p['id']]);
    } catch (Throwable $e) {
        $db->prepare("UPDATE reservation_payments SET refund_status='STAFF_ATTENTION', updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$p['id']]);
        paymentAudit($db, 'PAYMONGO', 'REFUND_FAILED', (int)$p['reservation_id'], substr($e->getMessage(), 0, 300));
    }
}

// Returns true only when PayMongo confirms expiry. The callback permits a
// deterministic sandbox test without sending network requests.
function paymentExpireHold(PDO $db, int $id, string $sessionId, callable $request): bool {
    $path = '/v1/checkout_sessions/' . rawurlencode($sessionId);
    try {
        $result = $request('POST', $path . '/expire', []);
        $remoteExpired = ($result['data']['attributes']['status'] ?? '') === 'expired';
    } catch (Throwable $e) {
        $remoteExpired = false;
        if ($e->getCode() === 400) {
            try {
                $remote = $request('GET', $path, null);
                $remoteExpired = ($remote['data']['attributes']['status'] ?? '') === 'expired';
            } catch (Throwable $lookupError) { error_log('Checkout lookup failed for hold ' . $id . ': ' . $lookupError->getMessage()); }
        }
        if (!$remoteExpired) error_log('Hold ' . $id . ' remains held: ' . $e->getMessage());
    }
    if (!$remoteExpired) {
        $db->prepare("UPDATE reservation_payments SET status='EXPIRY_RETRY',updated_at=CURRENT_TIMESTAMP WHERE reservation_id=? AND status IN ('PENDING','EXPIRY_RETRY')")->execute([$id]);
        return false;
    }
    paymentBegin($db);
    try {
        $update = $db->prepare("UPDATE reservations SET status='EXPIRED',payment_state='EXPIRED',token_id=NULL WHERE reservation_id=? AND status='PENDING_PAYMENT'");
        $update->execute([$id]);
        if ($update->rowCount() > 0) {
            $db->prepare("UPDATE reservation_payments SET status='EXPIRED',updated_at=CURRENT_TIMESTAMP WHERE reservation_id=? AND status IN ('PENDING','EXPIRY_RETRY')")->execute([$id]);
            paymentAudit($db, 'SYSTEM', 'RESERVATION_HOLD_EXPIRED', $id, 'PayMongo checkout explicitly expired before slot release.');
        }
        $db->commit();
        return true;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
