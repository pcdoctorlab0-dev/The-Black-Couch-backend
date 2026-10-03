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
        putenv("{$name}=" . trim($value, " \t\r\n\"'"));
    }
}

require_once dirname(__DIR__) . '/config/db.php';
$pdo = db();
$authVersionColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'auth_version'")->fetch();
if (!$authVersionColumn) {
    $pdo->exec('ALTER TABLE users ADD COLUMN auth_version INT NOT NULL DEFAULT 0');
}

$sql = file_get_contents(__DIR__ . '/002_password_resets.sql');
if ($sql === false) {
    throw new RuntimeException('Could not read password-reset migration.');
}
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    $pdo->exec($statement);
}
echo "PASSWORD_RESET_MIGRATION_OK\n";
