<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';

require_method('POST');
require_csrf();
$admin = require_admin();

$body = json_body();
$orderId = (int)($body['order_id'] ?? 0);
$decision = (string)($body['decision'] ?? ''); // 'approve' | 'reject'

if ($orderId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
    fail('order_id and a valid decision are required.');
}

$newStatus = $decision === 'approve' ? 'approved' : 'rejected';

$pdo = db();
$pdo->beginTransaction();

try {
    // Lock the order row so two admin clicks (or a double-click / refresh)
    // can't both succeed.
    $stmt = $pdo->prepare('SELECT id, status, stock_released FROM orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        throw new RuntimeException('Order not found.');
    }

    // Idempotency guard: the UPDATE itself only succeeds if the order is
    // still pending, so a second approve/reject call — from a double-click,
    // a refresh, or a replayed request — is a no-op, not a double-process.
    $update = $pdo->prepare('
        UPDATE orders
        SET status = ?, verified_by = ?, verified_at = NOW()
        WHERE id = ? AND status = \'pending_payment\'
    ');
    $update->execute([$newStatus, $admin['id'], $orderId]);

    if ($update->rowCount() === 0) {
        throw new RuntimeException('This order was already ' . $order['status'] . ' — no action taken.');
    }

    // Automatic stock release on rejection: the moment Kingsley rejects,
    // every reserved item on this order goes straight back to inventory,
    // with zero manual follow-up. Guarded by stock_released so it can only
    // ever run once per order even under a race.
    if ($newStatus === 'rejected') {
        $release = $pdo->prepare('
            UPDATE orders o
            JOIN order_items oi ON oi.order_id = o.id
            JOIN product_variants v ON v.id = oi.variant_id
            SET v.stock = v.stock + oi.quantity, o.stock_released = 1
            WHERE o.id = ? AND o.stock_released = 0
        ');
        $release->execute([$orderId]);
    }

    $pdo->commit();

    respond(['ok' => true, 'order_id' => $orderId, 'status' => $newStatus]);
} catch (Throwable $e) {
    $pdo->rollBack();
    fail($e->getMessage(), 409);
}
