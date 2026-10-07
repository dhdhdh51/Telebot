<?php
/**
 * Common bootstrap for every web entry point.
 *
 * Loads config BEFORE starting the session so cookie security settings
 * actually apply (calling session_start() first silently ignores them).
 */

if (!file_exists(__DIR__ . '/../config/config.php')) {
    http_response_code(500);
    exit('Application is not configured. Copy config/config.example.php to config/config.php.');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/functions.php';

/**
 * Start the session with hardened cookie flags.
 *
 * SameSite=None is required because Telegram Web / Desktop load the Mini App
 * inside a cross-site iframe; with Lax/Strict the browser drops the cookie and
 * every API call returns 401. Secure is mandatory with SameSite=None (HTTPS only).
 * Admin forms are still protected by CSRF tokens.
 */
function startAppSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (defined('APP_ENV') && APP_ENV === 'production');

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => $secure ? 'None' : 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

/**
 * Require an authenticated Mini App user for JSON APIs. Returns user id.
 */
function requireAppUser(): int {
    if (empty($_SESSION['authenticated']) || empty($_SESSION['user_id'])) {
        jsonResponse(false, ['code' => 'NOT_AUTHENTICATED'], 'Authentication required', 401);
    }
    $user = db()->fetchOne("SELECT id, status FROM users WHERE id = ?", [$_SESSION['user_id']]);
    if (!$user || $user['status'] !== 'ACTIVE') {
        jsonResponse(false, ['code' => 'USER_INACTIVE'], 'User account is not active', 403);
    }
    return (int)$user['id'];
}

/**
 * Read a JSON request body once (php://input can only be relied on once).
 */
function jsonInput(): array {
    static $data = null;
    if ($data === null) {
        $decoded = json_decode(file_get_contents('php://input'), true);
        $data = is_array($decoded) ? $decoded : [];
    }
    return $data;
}

require_once __DIR__ . '/csp.php';

setCsp();
startAppSession();
