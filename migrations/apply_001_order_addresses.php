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
        $value = trim($value, " \t\r\n\"'");
        putenv("{$name}={$value}");
    }
}

require_once dirname(__DIR__) . '/config/db.php';
$sql = file_get_contents(__DIR__ . '/001_order_addresses.sql');
if ($sql === false) {
    throw new RuntimeException('Could not read order-address migration.');
}

db()->exec($sql);
echo "ORDER_ADDRESS_MIGRATION_OK\n";
