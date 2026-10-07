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
if (!is_array($update)) {
    exit;
}

$tg = new Telegram();
$appName = htmlspecialchars((string)getSetting('general', 'app_name', APP_NAME), ENT_QUOTES, 'UTF-8');
$appUrl = tgConf('mini_app_url');
$channelLink = Telegram::channelLink();
$forceJoin = (bool)getSetting('telegram', 'force_join', false) && $channelLink !== '';
$showChannel = (bool)getSetting('telegram', 'start_show_channel', true) && $channelLink !== '';

/** Keyboard + text for the main menu, optionally targeting one video. */
function mainMenu($name, $video = null) {
    global $appName, $appUrl, $channelLink, $showChannel;
    $rows = [];
    if ($video) {
        $text = "🎬 <b>" . htmlspecialchars($video['title'], ENT_QUOTES, 'UTF-8') . "</b>\n\nTap below to watch.";
        $rows[] = [['text' => '▶ WATCH VIDEO', 'web_app' => ['url' => $appUrl . '/video.php?id=' . (int)$video['id']]]];
    } else {
        $text = "👋 Hi {$name}! Welcome to <b>{$appName}</b>.\n\nTap below to start watching.";
        $rows[] = [['text' => '▶ Open ' . html_entity_decode($appName), 'web_app' => ['url' => $appUrl . '/']]];
        $rows[] = [['text' => '💎 Get Premium', 'web_app' => ['url' => $appUrl . '/subscription.php']]];
    }
    if ($showChannel) {
        $rows[] = [['text' => '📢 Join our channel', 'url' => $channelLink]];
    }
    return [$text, ['inline_keyboard' => $rows]];
}

/** Message asking the user to join first. Payload (e.g. v123 / ref_X) is carried in the button. */
function joinPrompt($chatId, $payload) {
    global $tg, $appName, $channelLink;
    $cb = 'joined:' . substr(preg_replace('/[^A-Za-z0-9_]/', '', $payload), 0, 50);
    $tg->sendMessage($chatId,
        "📢 To use <b>{$appName}</b>, please join our channel first.\n\n1️⃣ Tap <b>Join channel</b>\n2️⃣ Come back and tap <b>✅ I've joined</b>",
        ['inline_keyboard' => [
            [['text' => '📢 Join channel', 'url' => $channelLink]],
            [['text' => "✅ I've joined", 'callback_data' => $cb]],
        ]]);
}

function videoFromPayload($payload) {
    if (!preg_match('/^v(\d+)$/', (string)$payload, $vm)) {
        return null;
    }
    return db()->fetchOne("SELECT id, title FROM videos WHERE id = ? AND status = 'PUBLISHED'", [(int)$vm[1]]) ?: null;
}

try {
    // ---- "I've joined" button ----
    if (!empty($update['callback_query'])) {
        $cq = $update['callback_query'];
        $from = $cq['from'] ?? [];
        $chatId = $cq['message']['chat']['id'] ?? ($from['id'] ?? null);
        if (strpos((string)($cq['data'] ?? ''), 'joined:') === 0 && $chatId) {
            $payload = substr($cq['data'], 7);
            if ($forceJoin && $tg->isChannelMember($from['id']) === false) {
                $tg->answerCallbackQuery($cq['id'], "You haven't joined yet. Tap \"Join channel\" first.", true);
            } else {
                $tg->answerCallbackQuery($cq['id'], 'Thanks for joining! 🎉');
                if (!empty($cq['message']['message_id'])) {
                    $tg->deleteMessage($chatId, $cq['message']['message_id']);
                }
                [$text, $kb] = mainMenu(htmlspecialchars($from['first_name'] ?? 'there', ENT_QUOTES, 'UTF-8'), videoFromPayload($payload));
                $tg->sendMessage($chatId, $text, $kb);
            }
        } else {
            $tg->answerCallbackQuery($cq['id']);
        }
        exit;
    }

    if (empty($update['message']['from']) || !empty($update['message']['from']['is_bot'])) {
        exit;
    }
    $message = $update['message'];
    $chat = $message['chat'] ?? [];
    $text = trim($message['text'] ?? '');

    // Only talk in private chats (the bot is also an admin in your channel).
    if (($chat['type'] ?? '') !== 'private') {
        exit;
    }

    if (preg_match('/^\/start(?:@\w+)?(?:\s+(\S+))?$/', $text, $m)) {
        $payload = $m[1] ?? '';
        $result = Telegram::createOrUpdateUser($message['from']);

        // Referral is recorded on the first /start, even before the user joins the channel.
        if (strpos($payload, 'ref_') === 0) {
            applyReferral($result['id'], substr($payload, 4), $result['is_new']);
        }

        if ($forceJoin && $tg->isChannelMember($message['from']['id']) === false) {
            joinPrompt($chat['id'], $payload);
            exit;
        }

        [$reply, $kb] = mainMenu(htmlspecialchars($message['from']['first_name'] ?? 'there', ENT_QUOTES, 'UTF-8'), videoFromPayload($payload));
        $tg->sendMessage($chat['id'], $reply, $kb);
    } elseif ($text === '/premium') {
        $tg->sendMessage($chat['id'], "💎 Go ad-free and unlock premium videos:",
            ['inline_keyboard' => [[['text' => '💎 Get Premium', 'web_app' => ['url' => $appUrl . '/subscription.php']]]]]);
    } elseif ($text === '/channel' && $channelLink !== '') {
        $tg->sendMessage($chat['id'], "📢 Our channel:", ['inline_keyboard' => [[['text' => '📢 Join channel', 'url' => $channelLink]]]]);
    } elseif ($text === '/help') {
        $tg->sendMessage($chat['id'], "/start – open {$appName}\n/premium – buy a subscription" . ($channelLink !== '' ? "\n/channel – our channel" : ''));
    }
} catch (Throwable $e) {
    error_log('Webhook error: ' . $e->getMessage());
}
