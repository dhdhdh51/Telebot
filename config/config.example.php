<?php
/**
 * BharatPlay Configuration File (Example)
 * 
 * Copy this file to config.php and update with your actual values
 * NEVER commit config.php to version control
 */

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'bharatplay');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_CHARSET', 'utf8mb4');

// Application Configuration
define('APP_NAME', 'BharatPlay');
define('APP_URL', 'https://yourdomain.com');
define('APP_ENV', 'production'); // development or production
define('APP_TIMEZONE', 'Asia/Kolkata');

// Security
define('SECRET_KEY', 'CHANGE_THIS_TO_RANDOM_STRING_MIN_32_CHARS');
define('CSRF_TOKEN_NAME', 'bharatplay_csrf_token');
define('SESSION_NAME', 'bharatplay_session');

// Telegram Bot Configuration
define('TELEGRAM_BOT_TOKEN', 'your_bot_token_here');
define('TELEGRAM_BOT_USERNAME', 'your_bot_username');
define('TELEGRAM_CHANNEL_ID', 'your_channel_id'); // e.g., @yourchannel or -1001234567890
define('TELEGRAM_MINI_APP_URL', 'https://yourdomain.com/app');

// File Upload Configuration
define('UPLOAD_DIR', __DIR__ . '/../uploads');
define('THUMBNAIL_DIR', UPLOAD_DIR . '/thumbnails');
define('VIDEO_DIR', UPLOAD_DIR . '/videos');
define('MAX_UPLOAD_SIZE', 524288000); // 500MB in bytes
define('MAX_THUMBNAIL_SIZE', 2097152); // 2MB in bytes

// Payment Gateway Configuration
define('PAYMENT_GATEWAY', 'manual'); // manual, razorpay, cashfree, payu

// Razorpay Configuration (if using)
define('RAZORPAY_KEY_ID', '');
define('RAZORPAY_KEY_SECRET', '');

// Cashfree Configuration (if using)
define('CASHFREE_APP_ID', '');
define('CASHFREE_SECRET_KEY', '');

// PayU Configuration (if using)
define('PAYU_MERCHANT_KEY', '');
define('PAYU_MERCHANT_SALT', '');

// Error Reporting
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    // Report everything but never display it; errors go to the log file.
    // (error_reporting(0) would also silence the log.)
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/php-errors.log');
}

// Timezone
date_default_timezone_set(APP_TIMEZONE);

// Optional: short name of a Direct Link Mini App created with /newapp in @BotFather.
// Leave empty to use the bot's Main Mini App (BotFather → Bot Settings → Configure Mini App).
define('TELEGRAM_MINI_APP_SHORT_NAME', '');

// Secret sent by Telegram in X-Telegram-Bot-Api-Secret-Token on every webhook call.
// Generate a random string (A-Z, a-z, 0-9, _ and -) and pass it to setWebhook.
define('TELEGRAM_WEBHOOK_SECRET', 'CHANGE_THIS_RANDOM_WEBHOOK_SECRET');

// Session cookie flags are set in includes/bootstrap.php.
// upload_max_filesize / post_max_size cannot be changed with ini_set() at runtime;
// they are set in .user.ini (or cPanel → MultiPHP INI Editor).
