<?php
/**
 * Telegram Bot API integration + WebApp authentication.
 * All configuration comes from tgConf() (admin panel → Telegram, config.php fallback).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/functions.php';

class Telegram {

    private $botToken;
    private $apiBase;

    public function __construct($botToken = null) {
        $this->botToken = $botToken ?? tgConf('bot_token');
        // Overridable for local testing only.
        $this->apiBase = defined('TELEGRAM_API_BASE') ? TELEGRAM_API_BASE : 'https://api.telegram.org';
    }

    public function isConfigured() {
        return (bool)preg_match('/^\d+:[A-Za-z0-9_-]{30,}$/', (string)$this->botToken);
    }

    /**
     * Call a Bot API method. Always returns an array with 'ok'; on failure
     * 'description' holds a human-readable reason (shown to the admin).
     */
    public function apiRequest($method, $params = []) {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'description' => 'Bot token is missing or invalid. Set it in Admin → Telegram.'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'description' => 'PHP cURL extension is disabled on this server.'];
        }
        $ch = curl_init($this->apiBase . '/bot' . $this->botToken . '/' . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error) {
            error_log("Telegram API network error [$method]: $error");
            return ['ok' => false, 'description' => 'Could not reach api.telegram.org: ' . $error];
        }
        $result = json_decode($response, true);
        if (!is_array($result)) {
            error_log("Telegram API Error: non-JSON response (HTTP $httpCode) for $method");
            return ['ok' => false, 'description' => "Unexpected response from Telegram (HTTP $httpCode)"];
        }
        if (empty($result['ok'])) {
            // Never log the URL (it contains the bot token).
            error_log("Telegram API Error [$method]: " . ($result['description'] ?? 'unknown'));
            $result['description'] = self::explainError($result['description'] ?? 'Unknown error');
        }
        return $result;
    }

    /** Add a plain-language hint to the most common Telegram errors. */
    public static function explainError($desc) {
        $hints = [
            'Unauthorized' => 'Bot token is wrong. Copy it again from @BotFather.',
            'chat not found' => 'Channel/group ID is wrong, or the bot was never added there. Use @channelusername or the -100… ID.',
            'not enough rights' => 'Make the bot an ADMIN of the channel with "Post messages" permission.',
            'need administrator rights' => 'Make the bot an ADMIN of the channel with "Post messages" permission.',
            'bot is not a member' => 'Add the bot to the channel/group as an admin.',
            'bot was kicked' => 'The bot was removed from the channel. Add it back as admin.',
            'BUTTON_URL_INVALID' => 'Bot username is wrong (check Admin → Telegram; no @, no spaces).',
            'wrong file identifier' => 'Thumbnail could not be read. Re-upload the thumbnail.',
            'IMAGE_PROCESS_FAILED' => 'Telegram could not process the thumbnail. Use a normal JPG/PNG.',
            'bot can\'t initiate conversation' => 'The user has not started the bot yet (press START in the bot first).',
            'Forbidden: bot was blocked' => 'This user has blocked the bot.',
        ];
        foreach ($hints as $needle => $hint) {
            if (stripos($desc, $needle) !== false) {
                return $desc . ' → ' . $hint;
            }
        }
        return $desc;
    }

    public function sendMessage($chatId, $text, $replyMarkup = null) {
        $params = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        return $this->apiRequest('sendMessage', $params);
    }

    public function sendPhoto($chatId, $photo, $caption = '', $replyMarkup = null) {
        $params = ['chat_id' => $chatId, 'caption' => $caption, 'parse_mode' => 'HTML'];
        if (filter_var($photo, FILTER_VALIDATE_URL)) {
            $params['photo'] = $photo;
        } elseif (is_file($photo)) {
            $params['photo'] = new CURLFile(realpath($photo));
        } else {
            return ['ok' => false, 'description' => 'Thumbnail file not found on server'];
        }
        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        return $this->apiRequest('sendPhoto', $params);
    }

    public function sendVideo($chatId, $video, $caption = '', $thumb = null) {
        $params = ['chat_id' => $chatId, 'caption' => $caption, 'parse_mode' => 'HTML'];
        if (filter_var($video, FILTER_VALIDATE_URL)) {
            $params['video'] = $video;
        } elseif (is_file($video)) {
            $params['video'] = new CURLFile(realpath($video));
        } else {
            return ['ok' => false, 'description' => 'Video file not found'];
        }
        if ($thumb && is_file($thumb)) {
            $params['thumbnail'] = new CURLFile(realpath($thumb));
        }
        return $this->apiRequest('sendVideo', $params);
    }

    public function editMessageText($chatId, $messageId, $text, $replyMarkup = null) {
        $params = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        return $this->apiRequest('editMessageText', $params);
    }

    public function deleteMessage($chatId, $messageId) {
        return $this->apiRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
    }

    public function getMe() {
        return $this->apiRequest('getMe');
    }

    public function getChat($chatId) {
        return $this->apiRequest('getChat', ['chat_id' => $chatId]);
    }

    public function getChatMember($chatId, $userId) {
        return $this->apiRequest('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
    }

    /** Register the webhook with a secret token (Telegram echoes it in a header). */
    public function setWebhook($url, $secretToken) {
        return $this->apiRequest('setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
            'allowed_updates' => json_encode(['message']),
            'drop_pending_updates' => 'true',
        ]);
    }

    public function deleteWebhook() {
        return $this->apiRequest('deleteWebhook');
    }

    public function getWebhookInfo() {
        return $this->apiRequest('getWebhookInfo');
    }

    /**
     * Whether the bot has a Main Mini App configured in @BotFather.
     * Cached for 1 hour (refreshed by the Telegram admin page).
     */
    public function hasMainWebApp($refresh = false) {
        $checkedAt = (int)getSetting('telegram', 'main_app_checked_at', 0);
        if (!$refresh && $checkedAt > time() - 3600) {
            return (bool)getSetting('telegram', 'has_main_web_app', false);
        }
        $me = $this->getMe();
        if (empty($me['ok'])) {
            return (bool)getSetting('telegram', 'has_main_web_app', false);
        }
        $has = !empty($me['result']['has_main_web_app']);
        saveSetting('telegram', 'has_main_web_app', $has, 'BOOLEAN');
        saveSetting('telegram', 'main_app_checked_at', time(), 'INTEGER');
        if (!empty($me['result']['username']) && tgConf('bot_username') === '') {
            saveSetting('telegram', 'bot_username', $me['result']['username']);
        }
        return $has;
    }

    /**
     * Link for the "WATCH VIDEO" button in channel posts.
     *  - Main Mini App configured → t.me/<bot>?startapp=v<id> (opens the app directly)
     *  - otherwise → t.me/<bot>?start=v<id>: the bot (webhook) replies with an
     *    "Open video" web_app button. Works without any BotFather Mini App setup.
     */
    public function watchLink($videoId) {
        $username = tgConf('bot_username');
        if (tgConf('mini_app_short_name') !== '' || $this->hasMainWebApp()) {
            return self::miniAppLink('v' . (int)$videoId);
        }
        return 'https://t.me/' . rawurlencode($username) . '?start=v' . (int)$videoId;
    }

    /**
     * Post a video (thumbnail + caption + ▶ WATCH VIDEO) to the channel/group.
     */
    public function publishVideo($video, $chatId = null) {
        $chatId = $chatId ?: tgConf('channel_id');
        if ($chatId === '') {
            return ['ok' => false, 'description' => 'Channel/group ID is not set. Set it in Admin → Telegram.'];
        }
        if (tgConf('bot_username') === '') {
            return ['ok' => false, 'description' => 'Bot username is not set. Set it in Admin → Telegram.'];
        }

        $caption = "🎬 <b>" . htmlspecialchars($video['title']) . "</b>\n\n";
        if (!empty($video['description'])) {
            $caption .= htmlspecialchars(mb_substr($video['description'], 0, 150));
            if (mb_strlen($video['description']) > 150) {
                $caption .= "...";
            }
            $caption .= "\n\n";
        }
        $caption .= "📁 Category: " . htmlspecialchars($video['category_name'] ?? '') . "\n";
        if ((int)$video['duration'] > 0) {
            $caption .= "⏱ Duration: " . $this->formatDuration((int)$video['duration']) . "\n";
        }
        if ($video['access_type'] === 'PREMIUM') {
            $caption .= "💎 Premium\n";
        }

        // url button: web_app buttons are rejected by Telegram outside private chats.
        $keyboard = ['inline_keyboard' => [[['text' => '▶ WATCH VIDEO', 'url' => $this->watchLink($video['id'])]]]];

        $thumbnailPath = THUMBNAIL_DIR . '/' . basename($video['thumbnail']);
        $result = $this->sendPhoto($chatId, $thumbnailPath, $caption, $keyboard);

        $db = db();
        if (!empty($result['ok'])) {
            $db->execute(
                "INSERT INTO telegram_posts (video_id, message_id, chat_id, post_type, caption, inline_keyboard, status, sent_at)
                 VALUES (?, ?, ?, 'PHOTO', ?, ?, 'SENT', NOW())",
                [$video['id'], $result['result']['message_id'] ?? null, $chatId, $caption, json_encode($keyboard)]
            );
        } else {
            $db->execute(
                "INSERT INTO telegram_posts (video_id, chat_id, post_type, caption, inline_keyboard, status, error_message)
                 VALUES (?, ?, 'PHOTO', ?, ?, 'FAILED', ?)",
                [$video['id'], $chatId, $caption, json_encode($keyboard), $result['description'] ?? 'Unknown error']
            );
        }
        return $result;
    }

    /**
     * t.me link that opens the Mini App with a start parameter
     * (allowed chars: A-Z a-z 0-9 _ -).
     */
    public static function miniAppLink($startParam = '') {
        $startParam = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$startParam);
        $base = 'https://t.me/' . rawurlencode(tgConf('bot_username'));
        $short = tgConf('mini_app_short_name');
        if ($short !== '') {
            $base .= '/' . rawurlencode($short); // Direct Link Mini App (/newapp)
        }
        return $base . '?startapp=' . $startParam;
    }

    /**
     * Validate Telegram WebApp initData
     * According to official Telegram WebApp authentication
     */
    public static function validateWebAppData($initData, $botToken = null) {
        if (!$botToken) {
            $botToken = tgConf('bot_token');
        }
        
        // Parse initData
        parse_str($initData, $data);
        
        if (!isset($data['hash'])) {
            return ['valid' => false, 'error' => 'Hash not provided'];
        }
        
        $hash = $data['hash'];
        unset($data['hash']);
        
        // Check auth_date (should be within last 5 minutes)
        if (!isset($data['auth_date'])) {
            return ['valid' => false, 'error' => 'auth_date not provided'];
        }
        
        // Replay protection. initData keeps the auth_date of when the Mini App was
        // opened, so the window must cover a normal viewing session; it's only used
        // once to create a server session.
        $authDate = (int)$data['auth_date'];
        $currentTime = time();
        $maxAge = function_exists('getSetting') ? (int)getSetting('security', 'telegram_auth_timeout', 3600) : 3600;
        
        if ($authDate > $currentTime + 60) {
            return ['valid' => false, 'error' => 'auth_date is in the future'];
        }
        if ($currentTime - $authDate > $maxAge) {
            return ['valid' => false, 'error' => 'Session expired, please reopen the Mini App'];
        }
        
        // Build data check string
        $dataCheckArr = [];
        foreach ($data as $key => $value) {
            $dataCheckArr[] = $key . '=' . $value;
        }
        sort($dataCheckArr);
        $dataCheckString = implode("\n", $dataCheckArr);
        
        // Calculate secret key
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        
        // Calculate hash
        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);
        
        // Verify hash
        if (!hash_equals($calculatedHash, $hash)) {
            return ['valid' => false, 'error' => 'Invalid hash'];
        }
        
        // Parse user data
        $user = isset($data['user']) ? json_decode($data['user'], true) : null;
        
        if (!$user || !isset($user['id'])) {
            return ['valid' => false, 'error' => 'User data not found'];
        }
        
        return [
            'valid' => true,
            'user' => $user,
            'auth_date' => $authDate,
            // start_param is covered by the hash, so it can be trusted (unlike a
            // value the frontend sends separately).
            'start_param' => $data['start_param'] ?? null
        ];
    }
    
    /**
     * Format duration to human readable
     */
    private function formatDuration($seconds) {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;
        
        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        } else {
            return sprintf('%d:%02d', $minutes, $seconds);
        }
    }
    
    /**
     * Create or update user from Telegram data
     */
    public static function createOrUpdateUser($telegramUser) {
        $db = db();
        
        // Check if user exists
        $user = $db->fetchOne(
            "SELECT * FROM users WHERE telegram_user_id = ?",
            [$telegramUser['id']]
        );
        
        if ($user) {
            // Update existing user
            $db->execute(
                "UPDATE users SET 
                    username = ?,
                    first_name = ?,
                    last_name = ?,
                    language_code = ?,
                    photo_url = ?,
                    last_active = NOW(),
                    updated_at = NOW()
                 WHERE telegram_user_id = ?",
                [
                    $telegramUser['username'] ?? null,
                    $telegramUser['first_name'],
                    $telegramUser['last_name'] ?? null,
                    $telegramUser['language_code'] ?? 'en',
                    $telegramUser['photo_url'] ?? null,
                    $telegramUser['id']
                ]
            );
            
            return ['id' => (int)$user['id'], 'is_new' => false];
        } else {
            // Create new user (random, non-guessable referral code)
            $referralCode = 'R' . strtoupper(bin2hex(random_bytes(5)));
            
            $db->execute(
                "INSERT INTO users (telegram_user_id, username, first_name, last_name, language_code, photo_url, referral_code, last_active) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $telegramUser['id'],
                    $telegramUser['username'] ?? null,
                    $telegramUser['first_name'],
                    $telegramUser['last_name'] ?? null,
                    $telegramUser['language_code'] ?? 'en',
                    $telegramUser['photo_url'] ?? null,
                    $referralCode
                ]
            );
            
            $userId = $db->lastInsertId();
            
            // Create wallet
            $db->execute("INSERT IGNORE INTO wallets (user_id) VALUES (?)", [$userId]);
            
            return ['id' => (int)$userId, 'is_new' => true];
        }
    }
}
