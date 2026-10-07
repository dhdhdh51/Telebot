<?php
/**
 * Server-to-server reward callback from the ad network.
 *
 * Adsgram → Ad unit → Reward URL (copy it from Admin → Rewards):
 *   https://<your-domain>/api/reward-callback.php?provider=adsgram&key=<secret>&userid=[userId]
 * Adsgram replaces [userId] with the Telegram user id after a fully watched ad.
 * The secret key keeps strangers from calling this URL; each call can only
 * complete an ad view the user started in the app (see includes/rewards.php).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rewards.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$key = (string)getSetting('ads', 'reward_callback_key', '');
if (($_GET['provider'] ?? '') !== 'adsgram' || $key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit('{"ok":false}');
}

$tgId = (string)($_GET['userid'] ?? '');
if (!preg_match('/^\d{1,20}$/', $tgId)) {
    http_response_code(400);
    exit('{"ok":false}');
}

saveSetting('ads', 'last_callback_at', time(), 'INTEGER'); // lets the admin see Adsgram is calling
$res = Rewards::completeAdIntent($tgId);
if (!$res['success']) {
    error_log('Reward callback not credited for tg ' . $tgId . ': ' . $res['error']);
}
// Always 200 for valid authenticated calls so the network does not retry endlessly.
echo json_encode(['ok' => $res['success']]);
