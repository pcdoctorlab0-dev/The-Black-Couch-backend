<?php
declare(strict_types=1);

function record_email_verification_attempt(PDO $pdo, string $email, string $ipAddress): void
{
    $pdo->exec('DELETE FROM email_verification_attempts WHERE created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY)');
    $emailHash = hash('sha256', strtolower($email));
    $ipHash = hash('sha256', $ipAddress);
    $count = $pdo->prepare('
        SELECT
            COALESCE(SUM(email_hash = ?), 0) AS email_attempts,
            COALESCE(SUM(ip_hash = ?), 0) AS ip_attempts
        FROM email_verification_attempts
        WHERE created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)
    ');
    $count->execute([$emailHash, $ipHash]);
    $attempts = $count->fetch();

    if ((int)$attempts['email_attempts'] >= 3 || (int)$attempts['ip_attempts'] >= 10) {
        fail('Too many verification requests. Please wait and try again.', 429);
    }

    $record = $pdo->prepare('INSERT INTO email_verification_attempts (email_hash, ip_hash) VALUES (?, ?)');
    $record->execute([$emailHash, $ipHash]);
}

function issue_email_verification_code(PDO $pdo, int $userId, string $email): void
{
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);
    $save = $pdo->prepare('
        INSERT INTO email_verification_codes (user_id, code_hash, expires_at, attempts)
        VALUES (?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE), 0)
        ON DUPLICATE KEY UPDATE
            code_hash = VALUES(code_hash),
            expires_at = VALUES(expires_at),
            attempts = 0,
            created_at = CURRENT_TIMESTAMP
    ');
    $save->execute([$userId, $codeHash]);

    emailjs_send((string)(getenv('EMAILJS_OTP_TEMPLATE_ID') ?: ''), [
        'email' => $email,
        'passcode' => $code,
        'time' => date('H:i T', time() + 15 * 60),
    ]);
}