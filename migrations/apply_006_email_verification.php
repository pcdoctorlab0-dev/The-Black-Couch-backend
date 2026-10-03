<?php
declare(strict_types=1);

$envPath = dirname(__DIR__) . '/.env';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        [$name, $value] = array_map('trim', $parts);
        putenv($name . '=' . trim($value, " \t\r\n\"'"));
    }
}

require_once dirname(__DIR__) . '/config/db.php';
$pdo = db();
$column = $pdo->query("SHOW COLUMNS FROM users LIKE 'email_verified'")->fetch();
if (!$column) {
    $pdo->exec('ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 1');
}

$sql = file_get_contents(__DIR__ . '/006_email_verification.sql');
if ($sql === false) {
    throw new RuntimeException('Could not read email-verification migration.');
}
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    $pdo->exec($statement);
}

echo "EMAIL_VERIFICATION_MIGRATION_OK\n";