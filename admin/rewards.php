<?php
/**
 * Admin → Rewards: rewarded ads (Adsgram), daily check-in, referral bonus, payouts.
 */

require_once __DIR__ . '/includes/admin-bootstrap.php';
require_once __DIR__ . '/../includes/rewards.php';

$db = db();
$self = '/admin/rewards.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    requireSuperAdmin();
    $action = $_POST['action'] ?? '';
    $err = [];
    $money = fn($v) => preg_match('/^\d{1,6}(\.\d{1,2})?$/', (string)$v);

    if ($action === 'save') {
        foreach (['WATCH_AD', 'DAILY_CHECKIN'] as $type) {
            $in = is_array($_POST[$type] ?? null) ? $_POST[$type] : [];
            $amount = trim((string)($in['amount'] ?? ''));
            if (!$money($amount)) { $err[] = "$type amount"; continue; }
            $db->execute(
                "INSERT INTO reward_rules (reward_type, name, amount, daily_limit, cooldown_seconds, enabled) VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE amount = VALUES(amount), daily_limit = VALUES(daily_limit), cooldown_seconds = VALUES(cooldown_seconds), enabled = VALUES(enabled)",
                [$type, $type === 'WATCH_AD' ? 'Watch Rewarded Ad' : 'Daily Check-in', $amount,
                 max(0, min(1000, (int)($in['daily_limit'] ?? 0))), max(0, min(86400, (int)($in['cooldown_seconds'] ?? 0))), !empty($in['enabled']) ? 1 : 0]
            );
        }
        saveSetting('ads', 'rewarded_ads_enabled', !empty($_POST['rewarded_ads_enabled']), 'BOOLEAN');
        saveSetting('ads', 'rewarded_provider', !empty($_POST['rewarded_ads_enabled']) ? 'adsgram' : '');
        saveSetting('ads', 'reward_verification', ($_POST['reward_verification'] ?? '') === 'sdk' ? 'sdk' : 'server');
        saveSetting('ads', 'reward_min_seconds', max(5, min(120, (int)($_POST['reward_min_seconds'] ?? 15))), 'INTEGER');
        $block = trim($_POST['adsgram_block_id'] ?? '');
        if ($block !== '' && !preg_match('/^\d{1,12}$/', $block)) {
            $err[] = 'Adsgram Reward block ID must be digits (e.g. 12345)';
        } else {
            saveSetting('ads', 'adsgram_block_id', $block);
        }
        if ((string)getSetting('ads', 'reward_callback_key', '') === '') {
            saveSetting('ads', 'reward_callback_key', bin2hex(random_bytes(16)));
        }
        $ref = trim($_POST['referral_amount'] ?? '');
        if ($money($ref)) { saveSetting('referral', 'reward_amount', $ref); } else { $err[] = 'referral amount'; }
        saveSetting('referral', 'min_referred_watch_time', max(0, (int)($_POST['referral_minutes'] ?? 5)) * 60, 'INTEGER');
        logAudit('SETTINGS_CHANGED', 'Reward settings updated', 'settings');
        flash($err ? 'error' : 'success', $err ? 'Saved, but invalid: ' . implode(', ', $err) : 'Reward settings saved.');
    } elseif ($action === 'rotate_key') {
        saveSetting('ads', 'reward_callback_key', bin2hex(random_bytes(16)));
        flash('success', 'New callback key generated. Update the Reward URL in Adsgram!');
    } elseif ($action === 'disable_all') {
        saveSetting('ads', 'rewarded_ads_enabled', false, 'BOOLEAN');
        $db->execute("UPDATE reward_rules SET enabled = 0");
        logAudit('REWARDS_DISABLED', 'All rewards disabled', 'settings');
        flash('success', 'All rewards switched OFF.');
    }
    header('Location: ' . $self);
    exit;
}

if ((string)getSetting('ads', 'reward_callback_key', '') === '') {
    saveSetting('ads', 'reward_callback_key', bin2hex(random_bytes(16)));
}
$rules = [];
foreach ($db->fetchAll("SELECT * FROM reward_rules") as $r) {
    $rules[$r['reward_type']] = $r;
}
$rule = fn($t) => $rules[$t] ?? ['amount' => '0.00', 'daily_limit' => 0, 'cooldown_seconds' => 0, 'enabled' => 0];
$rewardUrl = appUrl() . '/api/reward-callback.php?provider=adsgram&key=' . getSetting('ads', 'reward_callback_key', '') . '&userid=[userId]';
$stats = $db->fetchAll(
    "SELECT reward_type, COUNT(*) n, COALESCE(SUM(amount),0) total FROM reward_transactions
     WHERE status = 'COMPLETED' AND created_at >= CURDATE() GROUP BY reward_type"
);
$stats30 = $db->fetchOne("SELECT COALESCE(SUM(amount),0) t FROM reward_transactions WHERE status='COMPLETED' AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
$adsOn = Rewards::adsAvailable();
$isSuper = isSuperAdmin();

$pageTitle = 'Rewards';
include __DIR__ . '/includes/header.php';
?>

<?php renderFlash(); ?>

<div class="stats-grid">
    <?php foreach ($stats as $s): ?>
        <div class="stat-card"><h3><?= e($s['reward_type']) ?> today</h3><div class="value">₹<?= e($s['total']) ?></div><small><?= (int)$s['n'] ?> rewards</small></div>
    <?php endforeach; ?>
    <div class="stat-card"><h3>Rewards paid (30 days)</h3><div class="value">₹<?= e($stats30['t']) ?></div></div>
</div>

<form method="POST" style="max-width:860px">
    <?= CSRF::getInputField() ?><input type="hidden" name="action" value="save">

    <div class="content-box">
        <h3 style="margin-bottom:8px">📺 Watch ad &amp; earn (Adsgram) <span class="badge badge-<?= $adsOn ? 'success' : 'danger' ?>"><?= $adsOn ? 'LIVE' : 'OFF' ?></span></h3>
        <p style="color:#555;font-size:14px;margin-bottom:12px">Users are paid only when <b>Adsgram's server</b> confirms a fully watched ad — the app itself can't add money.</p>
        <ol style="font-size:14px;line-height:1.8;padding-left:20px;margin-bottom:14px">
            <li>Sign up at <b>partner.adsgram.ai</b> → Create <b>Ad platform</b>: <b>Web app url</b> must be EXACTLY the URL in @BotFather (<code><?= e(tgConf('mini_app_url')) ?>/</code>), <b>Telegram direct link</b> = <code>https://t.me/<?= e(tgConf('bot_username')) ?></code>, <b>Bot ID</b> = numbers before ":" in your bot token. Wait until the platform is <b>Active</b> (moderation).</li>
            <li>Create <b>Ad unit</b> → type <b>Reward</b> → in <b>Reward URL</b> paste:<br>
                <input class="form-control" readonly value="<?= e($rewardUrl) ?>" onclick="this.select()" style="font-family:monospace;font-size:12px"></li>
            <li>After moderation copy the <b>Block ID</b> (digits) here and switch it on. Open the Mini App <b>from Telegram</b> (not a browser) to test – Adsgram shows nothing outside Telegram.</li>
        </ol>
        <div style="background:#f8f9fa;border-radius:6px;padding:10px 14px;margin-bottom:14px">
            <b>Status check</b>
            <?php foreach (Rewards::diagnose() as [$ok, $txt]): ?>
                <div style="color:<?= $ok ? '#1e7e34' : '#c0392b' ?>;font-size:14px;margin-top:4px"><?= $ok ? '✔' : '✘' ?> <?= e($txt) ?></div>
            <?php endforeach; ?>
        </div>
        <div class="form-group">
            <label>How is a watched ad confirmed?</label>
            <select class="form-control" name="reward_verification">
                <option value="server" <?= Rewards::mode() === 'server' ? 'selected' : '' ?>>Adsgram server callback (Reward URL) – safest; Adsgram often enables it only for 50k+ daily users</option>
                <option value="sdk" <?= Rewards::mode() === 'sdk' ? 'selected' : '' ?>>Adsgram SDK result – works for every app; protected by min watch time, daily limit, cooldown</option>
            </select>
        </div>
        <div class="form-group" style="max-width:320px"><label>Minimum seconds between "Watch" and reward (SDK mode)</label>
            <input class="form-control" type="number" min="5" max="120" name="reward_min_seconds" value="<?= (int)Rewards::minWatchSeconds() ?>"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="form-group"><label>Adsgram Reward block ID</label><input class="form-control" name="adsgram_block_id" value="<?= e(getSetting('ads', 'adsgram_block_id', '')) ?>" placeholder="12345"></div>
            <div class="form-group"><label>&nbsp;</label><label style="font-weight:normal"><input type="checkbox" name="rewarded_ads_enabled" value="1" <?= getSetting('ads', 'rewarded_ads_enabled', false) ? 'checked' : '' ?>> Rewarded ads ON</label></div>
        </div>
        <?php $r = $rule('WATCH_AD'); ?>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px">
            <div class="form-group"><label>₹ per ad</label><input class="form-control" name="WATCH_AD[amount]" value="<?= e($r['amount']) ?>"></div>
            <div class="form-group"><label>Max ads / day</label><input class="form-control" type="number" name="WATCH_AD[daily_limit]" value="<?= (int)$r['daily_limit'] ?>"></div>
            <div class="form-group"><label>Wait between ads (s)</label><input class="form-control" type="number" name="WATCH_AD[cooldown_seconds]" value="<?= (int)$r['cooldown_seconds'] ?>"></div>
            <div class="form-group"><label>&nbsp;</label><label style="font-weight:normal"><input type="checkbox" name="WATCH_AD[enabled]" value="1" <?= $r['enabled'] ? 'checked' : '' ?>> Rule enabled</label></div>
        </div>
        <small style="color:#c0392b">Tip: keep ₹ per ad well below what Adsgram pays you (often only ₹0.05–0.10 per view), or you will lose money.</small>
    </div>

    <div class="content-box">
        <h3 style="margin-bottom:12px">📅 Daily check-in</h3>
        <?php $r = $rule('DAILY_CHECKIN'); ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="form-group"><label>₹ per day</label><input class="form-control" name="DAILY_CHECKIN[amount]" value="<?= e($r['amount']) ?>"></div>
            <div class="form-group"><label>&nbsp;</label><label style="font-weight:normal"><input type="checkbox" name="DAILY_CHECKIN[enabled]" value="1" <?= $r['enabled'] ? 'checked' : '' ?>> Enabled</label></div>
            <input type="hidden" name="DAILY_CHECKIN[daily_limit]" value="1"><input type="hidden" name="DAILY_CHECKIN[cooldown_seconds]" value="0">
        </div>
    </div>

    <div class="content-box">
        <h3 style="margin-bottom:12px">🤝 Referral bonus</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="form-group"><label>₹ to referrer</label><input class="form-control" name="referral_amount" value="<?= e(getSetting('referral', 'reward_amount', '10.00')) ?>"></div>
            <div class="form-group"><label>Paid after new user watched (minutes)</label><input class="form-control" type="number" name="referral_minutes" value="<?= (int)ceil(getSetting('referral', 'min_referred_watch_time', 300) / 60) ?>"></div>
        </div>
        <small style="color:#7f8c8d">Set ₹0 to turn referral bonuses off. Bonuses are paid by the hourly cron job.</small>
    </div>

    <?php if ($isSuper): ?><button class="btn btn-primary" style="padding:12px 28px">Save rewards</button><?php else: ?><p>Only a SUPER_ADMIN can change rewards.</p><?php endif; ?>
</form>

<?php if ($isSuper): ?>
<div class="content-box" style="max-width:860px;margin-top:20px;display:flex;gap:10px">
    <form method="POST"><?= CSRF::getInputField() ?><input type="hidden" name="action" value="rotate_key">
        <button class="btn btn-primary" style="background:#7f8c8d" onclick="return confirm('Generate a new key? You must update Adsgram.')">🔑 New callback key</button></form>
    <form method="POST"><?= CSRF::getInputField() ?><input type="hidden" name="action" value="disable_all">
        <button class="btn btn-danger" onclick="return confirm('Switch off ALL rewards now?')">⛔ Disable all rewards</button></form>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
