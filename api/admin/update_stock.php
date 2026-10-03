<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';

require_method('POST');
require_admin();
require_csrf();

$body = json_body();
$variantId = $body['variant_id'] ?? null;
$stock = $body['stock'] ?? null;
if (!is_int($variantId) || $variantId <= 0) {
    fail('variant_id must be a positive integer.');
}
if (!is_int($stock) || $stock < 0 || $stock > 2147483647) {
    fail('stock must be a non-negative integer.');
}

$pdo = db();
$exists = $pdo->prepare('SELECT id FROM product_variants WHERE id = ?');
$exists->execute([$variantId]);
if (!$exists->fetch()) {
    fail('Product variant not found.', 404);
}
$update = $pdo->prepare('UPDATE product_variants SET stock = ? WHERE id = ?');
$update->execute([$stock, $variantId]);
respond(['ok' => true]);
