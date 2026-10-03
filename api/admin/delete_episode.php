<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';

require_method('POST');
require_admin();
require_csrf();

$body = json_body();
$id = $body['id'] ?? null;
if (!is_int($id) || $id <= 0) {
    fail('A valid episode id is required.');
}

$stmt = db()->prepare('DELETE FROM episodes WHERE id = ?');
$stmt->execute([$id]);
if ($stmt->rowCount() === 0) {
    fail('Episode not found.', 404);
}
respond(['ok' => true]);
