<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_method('POST');
require_csrf();

$body = json_body();
$token = trim((string)($body['token'] ?? ''));
$password = (string)($body['password'] ?? '');
if (strlen($password) < 10) {
    fail('Password must be at least 10 characters.');
}
if (!preg_match('/\A[a-f0-9]{64}\z/i', $token)) {
    fail('This reset link is invalid or has expired.');
}

$pdo = db();
$pdo->beginTransaction();
try {
    $resetStmt = $pdo->prepare('
        SELECT id, user_id
        FROM password_resets
        WHERE token_hash = ? AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP
        FOR UPDATE
    ');
    $resetStmt->execute([hash('sha256', $token)]);
    $reset = $resetStmt->fetch();
    if (!$reset) {
        $pdo->rollBack();
        fail('This reset link is invalid or has expired.');
    }

    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    $updateUser = $pdo->prepare('UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ?');
    $updateUser->execute([$passwordHash, $reset['user_id']]);

    $consumeTokens = $pdo->prepare('UPDATE password_resets SET used_at = CURRENT_TIMESTAMP WHERE user_id = ? AND used_at IS NULL');
    $consumeTokens->execute([$reset['user_id']]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

log_out_user();
respond(['message' => 'Password updated. Please sign in with your new password.']);
