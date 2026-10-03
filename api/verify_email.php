<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_method('POST');
require_csrf();

$body = json_body();
$email = strtolower(trim((string)($body['email'] ?? '')));
$passcode = trim((string)($body['passcode'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/\A[0-9]{6}\z/', $passcode)) {
    fail('Enter the email address and six-digit code.');
}

$pdo = db();
$pdo->beginTransaction();
try {
    $userStmt = $pdo->prepare('SELECT id, email_verified FROM users WHERE email = ? FOR UPDATE');
    $userStmt->execute([$email]);
    $user = $userStmt->fetch();
    if (!$user || (int)$user['email_verified'] === 1) {
        $pdo->rollBack();
        fail('The verification code is invalid or has expired.', 400);
    }

    $codeStmt = $pdo->prepare('
        SELECT code_hash, attempts
        FROM email_verification_codes
        WHERE user_id = ? AND expires_at > CURRENT_TIMESTAMP
        FOR UPDATE
    ');
    $codeStmt->execute([$user['id']]);
    $verification = $codeStmt->fetch();
    if (!$verification) {
        $delete = $pdo->prepare('DELETE FROM email_verification_codes WHERE user_id = ?');
        $delete->execute([$user['id']]);
        $pdo->commit();
        fail('The verification code is invalid or has expired.', 400);
    }

    if ((int)$verification['attempts'] >= 5) {
        $delete = $pdo->prepare('DELETE FROM email_verification_codes WHERE user_id = ?');
        $delete->execute([$user['id']]);
        $pdo->commit();
        fail('Too many incorrect codes. Request a new verification code.', 429);
    }

    if (!password_verify($passcode, $verification['code_hash'])) {
        $increment = $pdo->prepare('UPDATE email_verification_codes SET attempts = attempts + 1 WHERE user_id = ?');
        $increment->execute([$user['id']]);
        $pdo->commit();
        fail('The verification code is invalid or has expired.', 400);
    }

    $verify = $pdo->prepare('UPDATE users SET email_verified = 1 WHERE id = ? AND email_verified = 0');
    $verify->execute([$user['id']]);
    $delete = $pdo->prepare('DELETE FROM email_verification_codes WHERE user_id = ?');
    $delete->execute([$user['id']]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

log_in_user((int)$user['id']);
respond(['user' => current_user(), 'csrf_token' => csrf_token()]);