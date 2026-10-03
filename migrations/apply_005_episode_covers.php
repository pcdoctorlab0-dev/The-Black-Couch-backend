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
$column = $pdo->query("SHOW COLUMNS FROM episodes LIKE 'cover_image_url'")->fetch();
if (!$column) {
    $pdo->exec('ALTER TABLE episodes ADD COLUMN cover_image_url VARCHAR(500) NULL AFTER youtube_url');
    $column = $pdo->query("SHOW COLUMNS FROM episodes LIKE 'cover_image_url'")->fetch();
}

$pdo->exec("UPDATE episodes SET cover_image_url = '' WHERE cover_image_url IS NULL");
if (($column['Null'] ?? 'YES') === 'YES') {
    $pdo->exec('ALTER TABLE episodes MODIFY cover_image_url VARCHAR(500) NOT NULL');
}

echo "EPISODE_COVERS_MIGRATION_OK\n";