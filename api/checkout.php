<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_method('POST');
require_csrf();
$user = require_login();

$body = json_body();
$items = $body['items'] ?? [];
$deliveryCents = 8000; // flat demo delivery fee; server-controlled, not client-supplied

if (!is_array($items) || count($items) === 0) {
    fail('Cart is empty.');
}

// Normalise & validate shape before touching the DB.
$wanted = [];
foreach ($items as $item) {
    $variantId = (int)($item['variant_id'] ?? 0);
    $qty = (int)($item['quantity'] ?? 0);
    if ($variantId <= 0 || $qty <= 0 || $qty > 20) {
        fail('Invalid item in cart.');
    }
    // Merge duplicate lines for the same variant.
    $wanted[$variantId] = ($wanted[$variantId] ?? 0) + $qty;
}

// Lock variant rows in a consistent order (ascending id) across all requests
// to avoid deadlocks when multiple checkouts race for overlapping variants.
ksort($wanted);

$pdo = db();
$pdo->beginTransaction();

try {
    $totalCents = 0;
    $lockedVariants = [];

    foreach ($wanted as $variantId => $qty) {
        // SELECT ... FOR UPDATE locks this row for the duration of the
        // transaction, so a second concurrent checkout for the same variant
        // has to wait — this is what stops two people buying the last item at once.
        $stmt = $pdo->prepare('
            SELECT v.id, v.stock, v.size, p.price_cents, p.name, p.is_active
            FROM product_variants v
            JOIN products p ON p.id = v.product_id
            WHERE v.id = ?
            FOR UPDATE
        ');
        $stmt->execute([$variantId]);
        $variant = $stmt->fetch();

        if (!$variant || !$variant['is_active']) {
            throw new RuntimeException("One of the items is no longer available.");
        }
        if ((int)$variant['stock'] < $qty) {
            throw new RuntimeException("Not enough stock for {$variant['name']} ({$variant['size']}).");
        }

        // Price ALWAYS comes from the products table here, never from the
        // request body, so nothing the browser sends can change the charge.
        $unitPrice = (int)$variant['price_cents'];
        $totalCents += $unitPrice * $qty;

        $lockedVariants[] = [
            'variant_id' => $variantId,
            'qty' => $qty,
            'unit_price_cents' => $unitPrice,
        ];
    }

    $totalCents += $deliveryCents;

    $orderRef = 'TBC' . strtoupper(bin2hex(random_bytes(3)));

    $orderStmt = $pdo->prepare('
        INSERT INTO orders (order_ref, user_id, total_cents, delivery_cents, status)
        VALUES (?, ?, ?, ?, \'pending_payment\')
    ');
    $orderStmt->execute([$orderRef, $user['id'], $totalCents, $deliveryCents]);
    $orderId = (int)$pdo->lastInsertId();

    $itemStmt = $pdo->prepare('
        INSERT INTO order_items (order_id, variant_id, quantity, unit_price_cents)
        VALUES (?, ?, ?, ?)
    ');
    $stockStmt = $pdo->prepare('UPDATE product_variants SET stock = stock - ? WHERE id = ?');

    foreach ($lockedVariants as $line) {
        $itemStmt->execute([$orderId, $line['variant_id'], $line['qty'], $line['unit_price_cents']]);

        // Reserve stock immediately at checkout. The DB CHECK constraint
        // (stock >= 0) is a second, unconditional guard even if this
        // application-level check above were somehow bypassed.
        $stockStmt->execute([$line['qty'], $line['variant_id']]);
    }

    $pdo->commit();

    respond([
        'order' => [
            'id' => $orderId,
            'order_ref' => $orderRef,
            'total_cents' => $totalCents,
            'status' => 'pending_payment',
        ],
    ], 201);
} catch (Throwable $e) {
    $pdo->rollBack();
    fail($e->getMessage(), 409);
}
