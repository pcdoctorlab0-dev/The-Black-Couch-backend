<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../includes/product_images.php';

require_method('GET');
require_admin();
require_csrf();

$stmt = db()->prepare('
    SELECT p.id, p.name, p.type, p.description, p.price_cents, p.image,
           v.id AS variant_id, v.size, v.stock
    FROM products p
    LEFT JOIN product_variants v ON v.product_id = p.id
    ORDER BY p.id DESC, FIELD(v.size, \'XS\',\'S\',\'M\',\'L\',\'XL\',\'XXL\',\'One size\')
');
$stmt->execute();
$rows = $stmt->fetchAll();

$products = [];
foreach ($rows as $row) {
    $productId = (int)$row['id'];
    if (!isset($products[$productId])) {
        $products[$productId] = [
            'id' => $productId,
            'name' => $row['name'],
            'type' => $row['type'],
            'description' => $row['description'],
            'price_cents' => (int)$row['price_cents'],
            'image' => public_product_image_url($row['image']),
            'variants' => [],
        ];
    }
    if ($row['variant_id'] !== null) {
        $products[$productId]['variants'][] = [
            'variant_id' => (int)$row['variant_id'],
            'size' => $row['size'],
            'stock' => (int)$row['stock'],
        ];
    }
}
respond(['products' => array_values($products)]);
