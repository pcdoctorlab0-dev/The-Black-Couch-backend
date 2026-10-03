<?php
declare(strict_types=1);

function loadEnvironmentFile(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $parts = explode('=', $trimmed, 2);
        if (count($parts) !== 2) {
            continue;
        }

        [$name, $value] = array_map('trim', $parts);
        $value = trim($value, " \t\r\n\"'");

        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

loadEnvironmentFile(__DIR__ . '/../.env');

/**
 * Loaded at the top of every api/*.php entry point.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0'); // never leak stack traces / paths to the client
ini_set('log_errors', '1');

// --- Hardened session cookie config (must run before session_start) ---
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => getenv('APP_ENV') === 'production', // HTTPS-only in prod
    'httponly' => true,   // JS can never read the session cookie
    'samesite' => 'Lax',  // CSRF-hardening at the cookie level too
]);
session_name('bc_session');
session_start();

// --- CORS for the separately-hosted React dev server. Tighten origin in prod. ---
$allowedOrigins = array_filter(array_map('trim', explode(',', (string) (getenv('FRONTEND_ORIGIN') ?: 'http://localhost:5173,http://localhost:5174'))));
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigin = $requestOrigin !== '' && in_array($requestOrigin, $allowedOrigins, true)
    ? $requestOrigin
    : ($allowedOrigins[0] ?? 'http://localhost:5173');
header("Access-Control-Allow-Origin: {$allowedOrigin}");
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Vary: Origin');
header('Content-Type: application/json; charset=utf-8');

// A couple of defense-in-depth response headers.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

if (($serverMethod = $_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/http.php';
