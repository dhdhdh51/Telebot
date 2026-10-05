<?php
/**
 * Telegram Bot API Integration
 * Handle Telegram Bot API calls and WebApp authentication
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/security.php';

class Telegram {
    
    private $botToken;
    private $botUsername;
    private $apiUrl;
    
    public function __construct() {
        $this->botToken = TELEGRAM_BOT_TOKEN;
        $this->botUsername = TELEGRAM_BOT_USERNAME;
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}/";
    }
    
    /**
     * Send API request to Telegram
     */
    private function apiRequest($method, $params = []) {
        $url = $this->apiUrl . $method;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("Telegram API Error: " . $error);
            return ['ok' => false, 'error' => $error];
        }
        
        $result = json_decode($response, true);
        
        if (!is_array($result)) {
            error_log("Telegram API Error: non-JSON response (HTTP $httpCode) for $method");
            return ['ok' => false, 'description' => "Unexpected response from Telegram (HTTP $httpCode)"];
        }
        
        if (empty($result['ok'])) {
            // Log method + description only; never log the URL (it contains the bot token).
            error_log("Telegram API Error [$method]: " . ($result['description'] ?? 'unknown'));
        }
        
        return $result;
    }
    
    /**
     * Send message
     */
    public function sendMessage($chatId, $text, $replyMarkup = null) {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        
        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        
        return $this->apiRequest('sendMessage', $params);
    }
    
    /**
     * Send photo
     */
    public function sendPhoto($chatId, $photo, $caption = '', $replyMarkup = null) {
        $params = [
            'chat_id' => $chatId,
            'caption' => $caption,
            'parse_mode' => 'HTML'
        ];
        
        // Check if photo is URL or file path
        if (filter_var($photo, FILTER_VALIDATE_URL)) {
            $params['photo'] = $photo;
        } elseif (file_exists($photo)) {
            $params['photo'] = new CURLFile(realpath($photo));
        } else {
            return ['ok' => false, 'error' => 'Invalid photo'];
        }
        
        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        
        return $this->apiRequest('sendPhoto', $params);
    }
    
    /**
     * Send video
     */
    public function sendVideo($chatId, $video, $caption = '', $thumb = null) {
        $params = [
            'chat_id' => $chatId,
            'caption' => $caption,
            'parse_mode' => 'HTML'
        ];
        
        if (filter_var($video, FILTER_VALIDATE_URL)) {
            $params['video'] = $video;
        } elseif (file_exists($video)) {
            $params['video'] = new CURLFile(realpath($video));
        } else {
            return ['ok' => false, 'error' => 'Invalid video'];
        }
        
        if ($thumb && file_exists($thumb)) {
            $params['thumbnail'] = new CURLFile(realpath($thumb)); // 'thumb' was renamed in Bot API 6.6
        }
        
        return $this->apiRequest('sendVideo', $params);
    }
    
    /**
     * Edit message
     */
    public function editMessageText($chatId, $messageId, $text, $replyMarkup = null) {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        
        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        
        return $this->apiRequest('editMessageText', $params);
    }
    
    /**
     * Delete message
     */
    public function deleteMessage($chatId, $messageId) {
        return $this->apiRequest('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId
        ]);
    }
    
    /**
     * Set webhook
     */
    public function setWebhook($url) {
        return $this->apiRequest('setWebhook', ['url' => $url]);
    }
    
    /**
     * Delete webhook
     */
    public function deleteWebhook() {
        return $this->apiRequest('deleteWebhook');
    }
    
    /**
     * Get webhook info
     */
    public function getWebhookInfo() {
        return $this->apiRequest('getWebhookInfo');
    }
    
    /**
     * Publish video to channel/group
     */
    public function publishVideo($video, $chatId = null) {
        if (!$chatId) {
            $chatId = TELEGRAM_CHANNEL_ID;
        }
        
        // Build caption
        $caption = "🎬 <b>" . htmlspecialchars($video['title']) . "</b>\n\n";
        
        if ($video['description']) {
            $description = mb_substr($video['description'], 0, 150);
            $caption .= htmlspecialchars($description);
            if (mb_strlen($video['description']) > 150) {
                $caption .= "...";
            }
            $caption .= "\n\n";
        }
        
        $caption .= "📁 Category: " . htmlspecialchars($video['category_name']) . "\n";
        $caption .= "⏱ Duration: " . $this->formatDuration($video['duration']) . "\n";
        $caption .= "👁 Views: " . number_format($video['views']);
        
        // NOTE: `web_app` inline buttons are only allowed in private chats with the bot;
        // Telegram rejects them in channels/groups. For channel posts we use a `url`
        // button pointing to a Mini App deep link. Telegram opens the Mini App and passes
        // "v<id>" as start_param (signed inside initData), which app/index.php routes on.
        $keyboard = [
            'inline_keyboard' => [
                [
                    [
                        'text' => '▶ WATCH VIDEO',
                        'url' => self::miniAppLink('v' . (int)$video['id'])
                    ]
                ]
            ]
        ];
        
        // Send photo with caption
        $thumbnailPath = __DIR__ . '/../uploads/thumbnails/' . $video['thumbnail'];
        
        if (!file_exists($thumbnailPath)) {
            return ['ok' => false, 'error' => 'Thumbnail not found'];
        }
        
        $result = $this->sendPhoto($chatId, $thumbnailPath, $caption, $keyboard);
        
        // Log the post
        if ($result['ok']) {
            $db = db();
            $messageId = $result['result']['message_id'] ?? null;
            
            $db->execute(
                "INSERT INTO telegram_posts (video_id, message_id, chat_id, post_type, caption, inline_keyboard, status, sent_at) 
                 VALUES (?, ?, ?, 'PHOTO', ?, ?, 'SENT', NOW())",
                [
                    $video['id'],
                    $messageId,
                    $chatId,
                    $caption,
                    json_encode($keyboard)
                ]
            );
        } else {
            // Log error
            $db = db();
            $db->execute(
                "INSERT INTO telegram_posts (video_id, chat_id, post_type, caption, status, error_message) 
                 VALUES (?, ?, 'PHOTO', ?, 'FAILED', ?)",
                [
                    $video['id'],
                    $chatId,
                    $caption,
                    $result['description'] ?? 'Unknown error'
                ]
            );
        }
        
        return $result;
    }
    
    /**
     * Build a t.me link that opens the Mini App with a start parameter.
     * start_param allows only A-Z a-z 0-9 _ - (max 512 chars).
     */
    public static function miniAppLink($startParam = '') {
        $startParam = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$startParam);
        $base = 'https://t.me/' . rawurlencode(TELEGRAM_BOT_USERNAME);
        if (defined('TELEGRAM_MINI_APP_SHORT_NAME') && TELEGRAM_MINI_APP_SHORT_NAME !== '') {
            $base .= '/' . rawurlencode(TELEGRAM_MINI_APP_SHORT_NAME); // Direct Link Mini App
        }
        return $base . '?startapp=' . $startParam; // otherwise the bot's Main Mini App
    }

    /**
     * Validate Telegram WebApp initData
     * According to official Telegram WebApp authentication
     */
    public static function validateWebAppData($initData, $botToken = null) {
        if (!$botToken) {
            $botToken = TELEGRAM_BOT_TOKEN;
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
