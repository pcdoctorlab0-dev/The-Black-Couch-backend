<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_method('POST');
require_csrf();
$user = require_login();

$orderId = (int)($_POST['order_id'] ?? 0);
if ($orderId <= 0) {
    fail('order_id is required.');
}

$pdo = db();

// Ownership check via prepared statement — a customer can only attach proof
// to their own order, and only while it's still pending.
$stmt = $pdo->prepare('SELECT id, user_id, status FROM orders WHERE id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order || (int)$order['user_id'] !== (int)$user['id']) {
    fail('Order not found.', 404);
}
if ($order['status'] !== 'pending_payment') {
    fail('This order is no longer awaiting payment proof.', 409);
}

if (empty($_FILES['proof']) || $_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
    fail('A proof-of-payment file is required.');
}

$file = $_FILES['proof'];

// 1. Size limit, enforced server-side regardless of what the form claims.
$maxBytes = 8 * 1024 * 1024; // 8MB
if ($file['size'] > $maxBytes) {
    fail('File is too large (max 8MB).');
}

// 2. Detect the real file type from its actual bytes (magic numbers), never
// trust $file['type'] which is just an echo of the browser-supplied header
// and is trivially spoofable.
$finfo = new finfo(FILEINFO_MIME_TYPE);
$detectedMime = $finfo->file($file['tmp_name']);

$allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
];

if (!isset($allowed[$detectedMime])) {
    fail('Only JPG, PNG, WEBP or PDF proof files are accepted.');
}
$extension = $allowed[$detectedMime];

// 3. Generate the stored filename ourselves. The customer's original
// filename is discarded entirely — it never touches the filesystem path or
// the DB — which closes off tricks like "receipt.jpg.php".
$storedFilename = bin2hex(random_bytes(24)) . '.' . $extension;

// 4. Store outside the web root, in a directory Apache/Nginx/PHP-FPM is
// never configured to execute from, as a second layer even if a bypass
// were somehow found upstream. See storage/proofs/.htaccess for the
// Apache-side belt-and-braces "deny everything" rule.
$storageDir = __DIR__ . '/../storage/proofs';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0750, true);
}
$destination = $storageDir . '/' . $storedFilename;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    fail('Could not save the uploaded file.', 500);
}
chmod($destination, 0640); // not executable, not world-readable

$insert = $pdo->prepare('
    INSERT INTO payment_proofs (order_id, stored_filename, original_mime)
    VALUES (?, ?, ?)
');
$insert->execute([$orderId, $storedFilename, $detectedMime]);

respond(['ok' => true, 'order_ref' => $orderId], 201);
