<?php
require_once __DIR__ . '/reservation_payments.php';
function localPaymentsEnabled(): bool { return getenv('LOCAL_PAYMENTS_ENABLED') === '1'; }
function localExpireHolds(PDO $db): void {
    paymentBegin($db);
    try {
        $ids = $db->query("SELECT r.reservation_id FROM reservations r JOIN reservation_payments p ON p.reservation_id=r.reservation_id WHERE p.provider='LOCAL' AND r.status='PENDING_PAYMENT' AND r.expiry_time<=CURRENT_TIMESTAMP")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $db->prepare("UPDATE reservations SET status='EXPIRED',payment_state='EXPIRED',token_id=NULL WHERE reservation_id=?")->execute([$id]);
            $db->prepare("UPDATE reservation_payments SET status='EXPIRED',updated_at=CURRENT_TIMESTAMP WHERE reservation_id=?")->execute([$id]);
            paymentAudit($db,'LOCAL_SIMULATOR','RESERVATION_HOLD_EXPIRED',(int)$id,'Local demo checkout expired; no money moved.');
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
function localSettle(PDO $db, string $checkout, string $outcome, string $otp): string {
    if (!localPaymentsEnabled()) throw new RuntimeException('Local payment simulation is disabled.');
    if (!in_array($outcome,['success','decline','cancel'],true)) throw new InvalidArgumentException('Invalid scenario.');
    if ($outcome !== 'cancel' && $otp !== '123456') throw new InvalidArgumentException('Enter the demo verification code 123456.');
    paymentBegin($db);
    try {
        $q=$db->prepare("SELECT p.*,r.status AS booking_status,r.expiry_time FROM reservation_payments p JOIN reservations r ON r.reservation_id=p.reservation_id WHERE p.provider='LOCAL' AND p.checkout_session_id=?");
        $q->execute([$checkout]); $p=$q->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new RuntimeException('Checkout not found.');
        if ($p['status']==='PAID') { $db->commit(); return 'PAID'; }
        if ($p['booking_status']!=='PENDING_PAYMENT') { $db->commit(); return $p['status']; }
        $expired=$p['expiry_time']<=gmdate('Y-m-d H:i:s');
        $state=$expired ? 'EXPIRED' : ($outcome==='success' ? 'PAID' : ($outcome==='cancel' ? 'CANCELLED' : 'DECLINED'));
        if ($state==='PAID') {
            $db->prepare("UPDATE reservation_payments SET status='PAID',payment_id=?,paid_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute(['SIM_'.bin2hex(random_bytes(12)),$p['id']]);
            $db->prepare("UPDATE reservations SET status='ACTIVE',payment_state='PAID',token_id=? WHERE reservation_id=?")->execute(['QR_'.bin2hex(random_bytes(16)),$p['reservation_id']]);
        } elseif ($state!=='DECLINED') {
            $db->prepare('UPDATE reservation_payments SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$state,$p['id']]);
            $db->prepare('UPDATE reservations SET status=?,payment_state=?,token_id=NULL WHERE reservation_id=?')->execute([$state,$state,$p['reservation_id']]);
        }
        paymentAudit($db,'LOCAL_SIMULATOR','SIMULATED_PAYMENT_'.$state,(int)$p['reservation_id'],'Demo payment only; no real funds or card details processed.');
        $db->commit(); return $state;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
