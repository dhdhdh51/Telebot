<?php
/**
 * Telegram bot webhook. Register it from Admin → Telegram → "Set webhook".
 *
 * Telegram sends the secret in X-Telegram-Bot-Api-Secret-Token on every call;
 * requests without it are rejected, so nobody can forge updates (e.g. fake
 * referrals) by POSTing here directly.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/telegram.php';

$expected = tgConf('webhook_secret');
$secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($expected === '' || !hash_equals($expected, $secret)) {
    http_response_code(403);
    exit;
}

$update = json_decode(file_get_contents('php://input'), true);
http_response_code(200); // Telegram retries non-2xx responses
if (!is_array($update) || empty($update['message']['from']) || !empty($update['message']['from']['is_bot'])) {
    exit;
}

$message = $update['message'];
$chat = $message['chat'] ?? [];
$text = trim($message['text'] ?? '');

// Only talk in private chats (the bot is also an admin in your channel).
if (($chat['type'] ?? '') !== 'private') {
    exit;
}

$tg = new Telegram();
$appName = htmlspecialchars((string)getSetting('general', 'app_name', APP_NAME), ENT_QUOTES, 'UTF-8');
$appUrl = tgConf('mini_app_url');

try {
    if (preg_match('/^\/start(?:@\w+)?(?:\s+(\S+))?$/', $text, $m)) {
        $payload = $m[1] ?? '';
        $result = Telegram::createOrUpdateUser($message['from']);

        if (strpos($payload, 'ref_') === 0) {
            applyReferral($result['id'], substr($payload, 4), $result['is_new']);
        }

        // Deep link from a channel post: t.me/<bot>?start=v123
        if (preg_match('/^v(\d+)$/', $payload, $vm)) {
            $video = db()->fetchOne(
                "SELECT id, title FROM videos WHERE id = ? AND status = 'PUBLISHED'",
                [(int)$vm[1]]
            );
            if ($video) {
                $tg->sendMessage($chat['id'],
                    "🎬 <b>" . htmlspecialchars($video['title'], ENT_QUOTES, 'UTF-8') . "</b>\n\nTap below to watch.",
                    ['inline_keyboard' => [[['text' => '▶ WATCH VIDEO', 'web_app' => ['url' => $appUrl . '/video.php?id=' . (int)$video['id']]]]]]
                );
                exit;
            }
        }

        $name = htmlspecialchars($message['from']['first_name'] ?? 'there', ENT_QUOTES, 'UTF-8');
        // web_app buttons ARE allowed here because this is a private chat with the bot.
        $tg->sendMessage($chat['id'],
            "👋 Hi {$name}! Welcome to <b>{$appName}</b>.\n\nTap below to start watching.",
            ['inline_keyboard' => [
                [['text' => '▶ Open ' . html_entity_decode($appName), 'web_app' => ['url' => $appUrl . '/']]],
                [['text' => '💎 Get Premium', 'web_app' => ['url' => $appUrl . '/subscription.php']]],
            ]]
        );
    } elseif ($text === '/premium') {
        $tg->sendMessage($chat['id'], "💎 Go ad-free and unlock premium videos:",
            ['inline_keyboard' => [[['text' => '💎 Get Premium', 'web_app' => ['url' => $appUrl . '/subscription.php']]]]]);
    } elseif ($text === '/help') {
        $tg->sendMessage($chat['id'], "/start – open {$appName}\n/premium – buy a subscription");
    }
} catch (Throwable $e) {
    error_log('Webhook error: ' . $e->getMessage());
}
