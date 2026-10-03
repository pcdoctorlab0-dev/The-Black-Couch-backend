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
$sql = file_get_contents(__DIR__ . '/007_emailjs_quota.sql');
if ($sql === false) {
    throw new RuntimeException('Could not read EmailJS quota migration.');
}
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    $pdo->exec($statement);
}

$cycleLimit = filter_var(getenv('EMAILJS_CYCLE_LIMIT') ?: '200', FILTER_VALIDATE_INT);
$initialRemaining = filter_var(getenv('EMAILJS_INITIAL_REMAINING') ?: (string)$cycleLimit, FILTER_VALIDATE_INT);
$resetValue = trim((string)(getenv('EMAILJS_RESET_AT') ?: ''));
if ($cycleLimit === false || $cycleLimit < 1 || $cycleLimit > 65535 || $initialRemaining === false || $initialRemaining < 0 || $initialRemaining > $cycleLimit || $resetValue === '') {
    throw new RuntimeException('Set valid EMAILJS_CYCLE_LIMIT, EMAILJS_INITIAL_REMAINING, and EMAILJS_RESET_AT values.');
}

try {
    $resetAtUtc = (new DateTimeImmutable($resetValue))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
} catch (Throwable $error) {
    throw new RuntimeException('EMAILJS_RESET_AT must be a valid date/time.', 0, $error);
}

$insert = $pdo->prepare('
    INSERT IGNORE INTO emailjs_quota (id, remaining_requests, cycle_limit, reset_at_utc)
    VALUES (1, ?, ?, ?)
');
$insert->execute([$initialRemaining, $cycleLimit, $resetAtUtc]);

echo "EMAILJS_QUOTA_MIGRATION_OK\n";