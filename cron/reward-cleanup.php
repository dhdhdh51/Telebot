<?php
/**
 * Daily cleanup (cPanel cron, e.g. 3 AM):
 *   0 3 * * * /usr/local/bin/php /home/USER/public_html/cron/reward-cleanup.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = db();

$n = $db->execute("DELETE FROM admin_sessions WHERE expires_at < NOW()");
echo "Deleted $n expired admin sessions\n";

// Reward rows that never got verified are dead; mark them so they can't be confused with pending ones.
$n = $db->execute("UPDATE reward_transactions SET status = 'FAILED' WHERE status = 'PENDING' AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
echo "Expired $n unverified reward transactions\n";

$n = $db->execute("DELETE FROM notifications WHERE read_status = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
echo "Deleted $n old read notifications\n";

// File-based rate-limit counters
$removed = 0;
foreach (glob(sys_get_temp_dir() . '/bharatplay_rate_*.tmp') ?: [] as $f) {
    if (filemtime($f) < time() - 86400 && @unlink($f)) {
        $removed++;
    }
}
echo "Removed $removed stale rate-limit files\n";
