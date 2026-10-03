<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/emailjs.php';
require_once __DIR__ . '/../includes/email_verification.php';

require_method('POST');
require_csrf();

$body = json_body();
$email = strtolower(trim((string)($body['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('Enter a valid email address.');
}

$pdo = db();
record_email_verification_attempt($pdo, $email, (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
$userStmt = $pdo->prepare('SELECT id, email, email_verified FROM users WHERE email = ? LIMIT 1');
$userStmt->execute([$email]);
$user = $userStmt->fetch();

if ($user && (int)$user['email_verified'] === 0) {
    try {
        issue_email_verification_code($pdo, (int)$user['id'], $user['email']);
    } catch (EmailDeliveryUnavailable $error) {
        fail('Email service provider is under maintenance. Please try again later.', 503);
    }
}

respond(['message' => 'If this address needs verification, a code has been sent.']);