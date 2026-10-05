<?php
/**
 * Pays referral bonuses once the referred user meets the eligibility rule
 * (referral.min_referred_watch_time seconds of real, server-measured watch time).
 * Hourly cron:
 *   15 * * * * /usr/local/bin/php /home/USER/public_html/cron/referral-rewards.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wallet.php';

$minWatch = (int)getSetting('referral', 'min_referred_watch_time', 300);

$candidates = db()->fetchAll(
    "SELECT r.referrer_id, r.referred_id FROM referrals r
     JOIN users u ON u.id = r.referred_id
     WHERE r.reward_paid = 0 AND u.status = 'ACTIVE' AND u.total_watch_time >= ?
     LIMIT 500",
    [$minWatch]
);

$paid = 0;
foreach ($candidates as $c) {
    $res = Wallet::processReferralReward((int)$c['referrer_id'], (int)$c['referred_id']);
    if ($res['success']) {
        $paid++;
    }
}
echo "Paid $paid of " . count($candidates) . " eligible referrals\n";
