<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/product_images.php';

require_method('GET');

$stmt = db()->query('
    SELECT p.id, p.name, p.type, p.description, p.price_cents, p.image,
           v.id AS variant_id, v.size, v.stock
    FROM products p
    JOIN product_variants v ON v.product_id = p.id
    WHERE p.is_active = 1
    ORDER BY p.id, FIELD(v.size, \'S\',\'M\',\'L\',\'XL\',\'XXL\',\'One Size\')
');

$rows = $stmt->fetchAll();

$products = [];
foreach ($rows as $row) {
    $pid = (int)$row['id'];
    if (!isset($products[$pid])) {
        $products[$pid] = [
            'id' => $pid,
            'name' => $row['name'],
            'type' => $row['type'],
            'description' => $row['description'],
            'price_cents' => (int)$row['price_cents'],
            'image' => public_product_image_url($row['image']),
            'variants' => [],
        ];
    }
    $products[$pid]['variants'][] = [
        'variant_id' => (int)$row['variant_id'],
        'size' => $row['size'],
        'stock' => (int)$row['stock'],
    ];
}

respond(['products' => array_values($products)]);
