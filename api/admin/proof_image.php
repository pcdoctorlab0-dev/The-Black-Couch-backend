<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';

require_method('GET');
require_admin(); // proof files are never reachable except through an authenticated admin session

$proofId = (int)($_GET['proof_id'] ?? 0);
if ($proofId <= 0) {
    fail('proof_id is required.');
}

$stmt = db()->prepare('SELECT stored_filename, original_mime FROM payment_proofs WHERE id = ?');
$stmt->execute([$proofId]);
$proof = $stmt->fetch();

if (!$proof) {
    fail('Not found.', 404);
}

// Filename is our own server-generated value from the DB, never derived
// from user input at request time, so there's no path traversal surface here.
$path = __DIR__ . '/../../storage/proofs/' . $proof['stored_filename'];
if (!is_file($path)) {
    fail('File missing on disk.', 404);
}

header('Content-Type: ' . $proof['original_mime']);
header('Content-Disposition: inline; filename="proof-' . $proofId . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
