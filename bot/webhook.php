<?php
/**
 * Telegram bot webhook.
 *
 * Register with (once, from a browser or curl):
 *   https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://yourdomain.com/bot/webhook.php&secret_token=<TELEGRAM_WEBHOOK_SECRET>
 *
 * Telegram sends the secret in X-Telegram-Bot-Api-Secret-Token on every call;
 * requests without it are rejected, so nobody can forge updates (e.g. fake /start
 * referrals) by POSTing to this URL directly.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/telegram.php';

$secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!defined('TELEGRAM_WEBHOOK_SECRET') || TELEGRAM_WEBHOOK_SECRET === '' || strpos(TELEGRAM_WEBHOOK_SECRET, 'CHANGE_THIS') === 0
    || !hash_equals(TELEGRAM_WEBHOOK_SECRET, $secret)) {
    http_response_code(403);
    exit;
}

$update = json_decode(file_get_contents('php://input'), true);
// Always answer 200 quickly; Telegram retries non-2xx responses.
http_response_code(200);
if (!is_array($update) || empty($update['message']['from']) || !empty($update['message']['from']['is_bot'])) {
    exit;
}

$message = $update['message'];
$chat = $message['chat'] ?? [];
$text = trim($message['text'] ?? '');

// Only talk to users in private chats (the bot is also an admin in your channel).
if (($chat['type'] ?? '') !== 'private') {
    exit;
}

$tg = new Telegram();

try {
    if (preg_match('/^\/start(?:@\w+)?(?:\s+(\S+))?$/', $text, $m)) {
        $payload = $m[1] ?? '';
        $result = Telegram::createOrUpdateUser($message['from']);

        if (strpos($payload, 'ref_') === 0) {
            applyReferral($result['id'], substr($payload, 4), $result['is_new']);
        }

        $name = htmlspecialchars($message['from']['first_name'] ?? 'there', ENT_QUOTES, 'UTF-8');
        // web_app buttons ARE allowed here because this is a private chat with the bot.
        $tg->sendMessage($chat['id'],
            "👋 Hi {$name}! Welcome to <b>" . htmlspecialchars(APP_NAME) . "</b>.\n\nTap below to start watching.",
            ['inline_keyboard' => [[['text' => '▶ Open ' . APP_NAME, 'web_app' => ['url' => TELEGRAM_MINI_APP_URL . '/']]]]]
        );
    } elseif ($text === '/help') {
        $tg->sendMessage($chat['id'], "Use /start to open " . htmlspecialchars(APP_NAME) . ".");
    }
} catch (Throwable $e) {
    error_log('Webhook error: ' . $e->getMessage());
}
