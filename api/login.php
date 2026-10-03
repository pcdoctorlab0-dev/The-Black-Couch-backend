<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_method('POST');
require_csrf();

$body = json_body();
$email = trim((string)($body['email'] ?? ''));
$password = (string)($body['password'] ?? '');

// Same generic message and same code path whether the email exists or not,
// so a timing/response difference can't be used to enumerate accounts.
$GENERIC_ERROR = 'Incorrect email or password.';

$stmt = db()->prepare('SELECT id, password_hash, email_verified FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

// Always run password_verify against *some* hash, even when the user doesn't
// exist, so the response time doesn't leak whether the email is registered.
$hashToCheck = $user['password_hash'] ?? '$2y$10$invalidsaltinvalidsaltinvalidsaltinvalidsal.';
$valid = password_verify($password, $hashToCheck);

if (!$user || !$valid) {
    fail($GENERIC_ERROR, 401);
}
if (!(int)$user['email_verified']) {
    fail('Please verify your email before logging in.', 403);
}

log_in_user((int)$user['id']);

respond(['user' => current_user(), 'csrf_token' => csrf_token()]);
