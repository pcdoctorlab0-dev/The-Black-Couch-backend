<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/emailjs.php';

require_method('POST');
require_csrf();

$body = json_body();
$email = strtolower(trim((string)($body['email'] ?? '')));
$remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$emailHash = hash('sha256', $email);
$ipHash = hash('sha256', $remoteAddress);
$pdo = db();

$pdo->exec('DELETE FROM password_reset_attempts WHERE created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY)');
$attemptStmt = $pdo->prepare('
    SELECT
        COALESCE(SUM(email_hash = ?), 0) AS email_attempts,
        COALESCE(SUM(ip_hash = ?), 0) AS ip_attempts
    FROM password_reset_attempts
    WHERE created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)
');
$attemptStmt->execute([$emailHash, $ipHash]);
$attempts = $attemptStmt->fetch();
$recordAttempt = $pdo->prepare('INSERT INTO password_reset_attempts (email_hash, ip_hash) VALUES (?, ?)');
$recordAttempt->execute([$emailHash, $ipHash]);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('Enter a valid email address.');
}
if ((int)$attempts['email_attempts'] >= 3 || (int)$attempts['ip_attempts'] >= 10) {
    fail('Too many reset requests. Please try again later.', 429);
}

$userStmt = $pdo->prepare('SELECT id, email, email_verified FROM users WHERE email = ? LIMIT 1');
$userStmt->execute([$email]);
$user = $userStmt->fetch();
if (!$user) {
    fail('This email address is not recognized.', 404);
}
if ((int)$user['email_verified'] !== 1) {
    fail('Please verify your email before requesting a password reset.', 403);
}

$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$pdo->beginTransaction();
try {
    $invalidate = $pdo->prepare('UPDATE password_resets SET used_at = CURRENT_TIMESTAMP WHERE user_id = ? AND used_at IS NULL');
    $invalidate->execute([$user['id']]);
    $insert = $pdo->prepare('
        INSERT INTO password_resets (user_id, token_hash, expires_at)
        VALUES (?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 HOUR))
    ');
    $insert->execute([$user['id'], $tokenHash]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

$frontendUrl = rtrim(getenv('FRONTEND_URL') ?: 'http://localhost:5174', '/');
$resetUrl = $frontendUrl . '/reset-password?token=' . rawurlencode($token);
try {
    emailjs_send((string)(getenv('EMAILJS_RESET_TEMPLATE_ID') ?: ''), [
        'email' => $user['email'],
        'link' => $resetUrl,
    ]);
} catch (EmailDeliveryUnavailable $error) {
    $invalidate = $pdo->prepare('UPDATE password_resets SET used_at = CURRENT_TIMESTAMP WHERE token_hash = ? AND used_at IS NULL');
    $invalidate->execute([$tokenHash]);
    fail('Email service provider is under maintenance. Please try again later.', 503);
}

respond(['message' => 'Reset email sent. Check your inbox.']);
