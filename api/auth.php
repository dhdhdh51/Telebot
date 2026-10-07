<?php
/**
 * Authentication API
 * Exchanges Telegram WebApp initData for a server session.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/telegram.php';
require_once __DIR__ . '/../includes/subscription.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, ['code' => 'METHOD_NOT_ALLOWED'], 'Method not allowed', 405);
}

$input = jsonInput();
$action = $input['action'] ?? '';

switch ($action) {
    case 'telegram_auth':
        authenticateTelegramUser($input);
        break;
    case 'check_auth':
        checkAuthentication();
        break;
    case 'logout':
        logout();
        break;
    default:
        jsonResponse(false, ['code' => 'INVALID_ACTION'], 'Invalid action');
}

function userPayload($userId) {
    $db = db();
    $user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
    return [
        'id' => (int)$user['id'],
        'telegram_id' => $user['telegram_user_id'],
        'username' => $user['username'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'photo_url' => $user['photo_url'],
        'is_premium' => ($sub = Subscription::active($userId)) !== null,
        'premium_plan' => $sub['plan_name'] ?? null,
        'premium_until' => $sub['end_date'] ?? null,
        'referral_link' => 'https://t.me/' . tgConf('bot_username') . '?start=ref_' . $user['referral_code'],
        'wallet_balance' => getWalletBalance($userId)
    ];
}

function authenticateTelegramUser($input) {
    $initData = $input['initData'] ?? '';
    if (!is_string($initData) || $initData === '') {
        jsonResponse(false, ['code' => 'MISSING_INIT_DATA'], 'Missing initData');
    }

    $rate = Security::checkRateLimit('tg_auth_' . Security::getClientIP(), 30, 60);
    if (!$rate['allowed']) {
        jsonResponse(false, ['code' => 'RATE_LIMITED'], 'Too many requests', 429);
    }

    $validation = Telegram::validateWebAppData($initData);
    if (!$validation['valid']) {
        jsonResponse(false, ['code' => 'INVALID_AUTH'], $validation['error'], 401);
    }

    $result = Telegram::createOrUpdateUser($validation['user']);
    $userId = $result['id'];

    $status = db()->fetchOne("SELECT status FROM users WHERE id = ?", [$userId])['status'];
    if ($status !== 'ACTIVE') {
        jsonResponse(false, ['code' => 'USER_INACTIVE'], 'User account is not active', 403);
    }

    // Referral via Mini App link (?startapp=ref_CODE). start_param is part of the
    // signed initData, so it cannot be forged by the client.
    $startParam = $validation['start_param'];
    if (is_string($startParam) && strpos($startParam, 'ref_') === 0) {
        applyReferral($userId, substr($startParam, 4), $result['is_new']);
    }

    session_regenerate_id(true); // prevent session fixation
    $_SESSION['user_id'] = $userId;
    $_SESSION['telegram_user_id'] = $validation['user']['id'];
    $_SESSION['authenticated'] = true;
    $_SESSION['auth_time'] = time();

    jsonResponse(true, [
        'user' => userPayload($userId),
        'start_param' => $startParam
    ], 'Authentication successful');
}

function checkAuthentication() {
    $userId = requireAppUser();
    jsonResponse(true, ['user' => userPayload($userId)]);
}

function logout() {
    $_SESSION = [];
    session_destroy();
    jsonResponse(true, null, 'Logged out successfully');
}
