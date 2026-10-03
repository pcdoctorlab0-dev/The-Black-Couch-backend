<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_method('POST');
require_csrf();
$user = require_login();

$body = json_body();
$orderId = (int)($body['order_id'] ?? 0);
$input = $body['address'] ?? null;
if ($orderId <= 0 || !is_array($input)) {
    fail('A valid order and delivery address are required.');
}

$fields = [
    'recipient_name' => 150,
    'phone' => 30,
    'address_line_1' => 190,
    'address_line_2' => 190,
    'suburb' => 120,
    'city' => 120,
    'province' => 120,
    'postal_code' => 20,
    'country' => 100,
];
$required = ['recipient_name', 'phone', 'address_line_1', 'suburb', 'city', 'province', 'postal_code', 'country'];
$address = [];

foreach ($fields as $field => $maxLength) {
    $value = trim((string)($input[$field] ?? ''));
    if (in_array($field, $required, true) && $value === '') {
        fail("{$field} is required.");
    }
    if (strlen($value) > $maxLength) {
        fail("{$field} is too long.");
    }
    $address[$field] = $value === '' ? null : $value;
}

$pdo = db();
$orderStmt = $pdo->prepare('SELECT status FROM orders WHERE id = ? AND user_id = ?');
$orderStmt->execute([$orderId, $user['id']]);
$order = $orderStmt->fetch();
if (!$order) {
    fail('Order not found.', 404);
}
if ($order['status'] !== 'pending_payment') {
    fail('The delivery address can no longer be changed because this order has been reviewed.', 409);
}

$saveStmt = $pdo->prepare('
    INSERT INTO order_addresses (
        order_id, recipient_name, phone, address_line_1, address_line_2,
        suburb, city, province, postal_code, country
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        recipient_name = VALUES(recipient_name),
        phone = VALUES(phone),
        address_line_1 = VALUES(address_line_1),
        address_line_2 = VALUES(address_line_2),
        suburb = VALUES(suburb),
        city = VALUES(city),
        province = VALUES(province),
        postal_code = VALUES(postal_code),
        country = VALUES(country)
');
$saveStmt->execute([
    $orderId,
    $address['recipient_name'],
    $address['phone'],
    $address['address_line_1'],
    $address['address_line_2'],
    $address['suburb'],
    $address['city'],
    $address['province'],
    $address['postal_code'],
    $address['country'],
]);

respond(['ok' => true, 'address' => $address]);
