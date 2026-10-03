<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/emailjs.php';
require_once __DIR__ . '/../includes/email_verification.php';

require_method('POST');
require_csrf();

$body = json_body();
$email = strtolower(trim((string)($body['email'] ?? '')));
$password = (string)($body['password'] ?? '');
$fullName = trim((string)($body['full_name'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('A valid email is required.');
}
if (strlen($password) < 10) {
    fail('Password must be at least 10 characters.');
}
if ($fullName === '') {
    fail('Full name is required.');
}

$pdo = db();
record_email_verification_attempt($pdo, $email, (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

$check = $pdo->prepare('SELECT id, email, email_verified FROM users WHERE email = ?');
$check->execute([$email]);
if ($existing = $check->fetch()) {
    if (!(int)$existing['email_verified']) {
        try {
            issue_email_verification_code($pdo, (int)$existing['id'], $existing['email']);
        } catch (EmailDeliveryUnavailable $error) {
            fail('Email service provider is under maintenance. Please try again later.', 503);
        }
        respond(['verification_required' => true, 'email' => $existing['email']], 202);
    }
    fail('Could not create account with those details.');
}

$hash = password_hash($password, PASSWORD_BCRYPT);

$insert = $pdo->prepare('INSERT INTO users (email, password_hash, full_name, role, email_verified) VALUES (?, ?, ?, \'customer\', 0)');
$insert->execute([$email, $hash, $fullName]);

$userId = (int)$pdo->lastInsertId();
$createdUserStmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
$createdUserStmt->execute([$userId]);
$createdUser = $createdUserStmt->fetch();
if (!$createdUser) {
    fail('Could not create account with those details.', 500);
}
try {
    issue_email_verification_code($pdo, $userId, $createdUser['email']);
} catch (EmailDeliveryUnavailable $error) {
    fail('Email service provider is under maintenance. Please try again later.', 503);
}

respond(['verification_required' => true, 'email' => $email], 202);
