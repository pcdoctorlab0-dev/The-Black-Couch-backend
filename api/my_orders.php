<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_method('GET');
$user = require_login();

$stmt = db()->prepare('
    SELECT o.id, o.order_ref, o.total_cents, o.status, o.created_at,
           EXISTS (
               SELECT 1
               FROM payment_proofs pp
               WHERE pp.order_id = o.id
           ) AS has_proof,
           oa.recipient_name, oa.phone, oa.address_line_1, oa.address_line_2,
           oa.suburb, oa.city, oa.province, oa.postal_code, oa.country
    FROM orders o
    LEFT JOIN order_addresses oa ON oa.order_id = o.id
    WHERE o.user_id = ?
    ORDER BY o.created_at DESC
');
$stmt->execute([$user['id']]);
$orders = $stmt->fetchAll();

foreach ($orders as &$order) {
    $order['shipping_address'] = $order['recipient_name'] === null ? null : [
        'recipient_name' => $order['recipient_name'],
        'phone' => $order['phone'],
        'address_line_1' => $order['address_line_1'],
        'address_line_2' => $order['address_line_2'],
        'suburb' => $order['suburb'],
        'city' => $order['city'],
        'province' => $order['province'],
        'postal_code' => $order['postal_code'],
        'country' => $order['country'],
    ];
    unset(
        $order['recipient_name'], $order['phone'], $order['address_line_1'],
        $order['address_line_2'], $order['suburb'], $order['city'],
        $order['province'], $order['postal_code'], $order['country']
    );
}
unset($order);

respond(['orders' => $orders]);
