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
foreach (['type' => "VARCHAR(60) NOT NULL DEFAULT 'Merch'", 'image' => 'VARCHAR(255) NULL'] as $column => $definition) {
    $stmt = $pdo->prepare('SHOW COLUMNS FROM products LIKE ?');
    $stmt->execute([$column]);
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE products ADD COLUMN {$column} {$definition}");
    }
}

$sql = file_get_contents(__DIR__ . '/004_admin_content.sql');
if ($sql === false) {
    throw new RuntimeException('Could not read admin content migration.');
}
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    $pdo->exec($statement);
}
echo "ADMIN_CONTENT_MIGRATION_OK\n";
