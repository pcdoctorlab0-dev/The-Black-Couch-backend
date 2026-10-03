<?php
declare(strict_types=1);

/**
 * Central DB connection. Everything downstream uses PDO prepared statements
 * with bound parameters — never string-concatenate user input into SQL.
 *
 * Works against a local MySQL for dev, and against a hosted MySQL
 * (PlanetScale, AWS RDS, DigitalOcean Managed MySQL, Railway, etc.) in
 * production — hosted providers require TLS, which is opt-in here via
 * DB_SSL so nothing changes for local dev unless you turn it on.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'black_couch';
    $user = getenv('DB_USER') ?: 'black_couch_app';
    $pass = getenv('DB_PASS') ?: '';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements, not client-side emulation
    ];

    // --- TLS for hosted databases ---
    // Set DB_SSL=true in .env once you point this at the online DB.
    if ((getenv('DB_SSL') ?: '') === 'true') {
        // These constants live on the base PDO class with a MYSQL_ prefix.
        // There is no \Pdo\Mysql::ATTR_SSL_* form — that was never a real
        // PHP symbol, and referencing it fataled with "Class Pdo\Mysql not
        // found" on every single request that called db(), since PHP
        // fatals the moment it tries to resolve a class that doesn't exist.
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;

        // Most providers (PlanetScale, RDS, DO) give you a CA bundle to
        // download once and reference here. If your provider terminates
        // TLS with a well-known public CA already trusted by the system's
        // CA store, you can omit DB_SSL_CA entirely and this block is a no-op.
        $caPath = getenv('DB_SSL_CA') ?: '';
        if ($caPath !== '') {
            if (!is_file($caPath)) {
                throw new RuntimeException("DB_SSL_CA is set but the file doesn't exist: {$caPath}");
            }
            $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
        }
    }

    $pdo = new PDO($dsn, $user, $pass, $options);

    return $pdo;
}