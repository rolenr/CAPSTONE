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
        provider TEXT NOT NULL DEFAULT 'LOCAL',
        checkout_session_id TEXT UNIQUE, checkout_url TEXT, payment_id TEXT UNIQUE,
        amount_centavos INTEGER NOT NULL, currency TEXT NOT NULL DEFAULT 'PHP',
        status TEXT NOT NULL DEFAULT 'CREATING', refund_status TEXT,
        refund_id TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        paid_at TEXT, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(reservation_id) REFERENCES reservations(reservation_id))");
    $paymentColumns = array_column($db->query('PRAGMA table_info(reservation_payments)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('provider', $paymentColumns, true)) {
        $db->exec("ALTER TABLE reservation_payments ADD COLUMN provider TEXT NOT NULL DEFAULT 'PAYMONGO'");
    }
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

function paymentBegin(PDO $db): void { $db->exec('BEGIN IMMEDIATE'); }

function paymentAudit(PDO $db, string $actor, string $action, int $reservationId, string $details): void {
    $stmt = $db->prepare('INSERT INTO audit_logs(admin_email,action_type,target_entity,target_id,details) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$actor, $action, 'reservation', $reservationId, $details]);
}
