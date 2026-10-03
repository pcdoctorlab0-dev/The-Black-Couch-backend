<?php
declare(strict_types=1);

/**
 * Double-submit-ish CSRF token: a random token is stored server-side in the
 * (httponly, samesite) session, handed to the client once via /api/csrf_token.php,
 * and the client must echo it back on every mutating request via the
 * X-CSRF-Token header. An attacker's cross-site form can trigger a cookie-bearing
 * request but cannot read the token to put it in the header.
 */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function require_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';

    if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
        fail('Invalid or missing CSRF token', 403);
    }
}
