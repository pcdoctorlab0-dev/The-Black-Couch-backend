<?php
declare(strict_types=1);

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, email, full_name, role, auth_version FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }
    if (!isset($_SESSION['auth_version']) || (int)$_SESSION['auth_version'] !== (int)$user['auth_version']) {
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }
    unset($user['auth_version']);
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        fail('Authentication required', 401);
    }
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        fail('Admin access required', 403);
    }
    return $user;
}

/**
 * Regenerates the session id on privilege change (login) to block
 * session-fixation / hijacking, and rotates the CSRF token with it.
 */
function log_in_user(int $userId): void
{
    $stmt = db()->prepare('SELECT auth_version FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $authVersion = $stmt->fetchColumn();

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['auth_version'] = (int)$authVersion;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function log_out_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
