<?php
/**
 * Admin → Telegram: bot settings, one-click diagnostics, webhook setup, test posts.
 */

require_once __DIR__ . '/includes/admin-bootstrap.php';
require_once __DIR__ . '/../includes/telegram.php';
require_once __DIR__ . '/../includes/video.php';

requireSuperAdmin();

$self = '/admin/telegram.php';

function runDiagnostics() {
    $tg = new Telegram();
    $out = [];
    $add = function ($ok, $label, $detail = '') use (&$out) { $out[] = [$ok, $label, $detail]; };

    $add(strpos(appUrl(), 'https://') === 0, 'Site uses HTTPS (' . appUrl() . ')', 'Telegram Mini Apps and webhooks require HTTPS. Enable SSL in cPanel → SSL/TLS Status.');

    $me = $tg->getMe();
    if (empty($me['ok'])) {
        $add(false, 'Bot token', $me['description'] ?? 'Invalid token');
        return $out;
    }
    $bot = $me['result'];
    $add(true, 'Bot token works: @' . $bot['username'] . ' (' . $bot['first_name'] . ')');
    if (strcasecmp(tgConf('bot_username'), $bot['username']) !== 0) {
        saveSetting('telegram', 'bot_username', $bot['username']);
        $add(true, 'Bot username corrected to ' . $bot['username']);
    }

    $hasMain = $tg->hasMainWebApp(true);
    $add($hasMain, 'Main Mini App configured in @BotFather',
        $hasMain ? '' : 'Optional but recommended: @BotFather → /mybots → @' . $bot['username'] . ' → Bot Settings → Configure Mini App → Enable → URL: '
            . tgConf('mini_app_url') . '/ . Until then the WATCH button opens the bot chat and the bot sends a "▶ WATCH VIDEO" button.');

    $channel = tgConf('channel_id');
    if ($channel === '') {
        $add(false, 'Channel/group ID', 'Not set. Enter @yourchannel or the numeric -100… ID above.');
    } else {
        $chat = $tg->getChat($channel);
        if (empty($chat['ok'])) {
            $add(false, 'Channel ' . $channel, $chat['description'] ?? 'Not found');
        } else {
            $c = $chat['result'];
            $add(true, 'Channel found: ' . ($c['title'] ?? $channel) . ' (' . $c['type'] . ', id ' . $c['id'] . ')');
            $member = $tg->getChatMember($channel, $bot['id']);
            $m = $member['result'] ?? [];
            $isAdmin = ($m['status'] ?? '') === 'administrator' || ($m['status'] ?? '') === 'creator';
            $canPost = $c['type'] !== 'channel' || !empty($m['can_post_messages']) || ($m['status'] ?? '') === 'creator';
            $add($isAdmin && $canPost, 'Bot is admin with permission to post',
                $isAdmin ? ($canPost ? '' : 'Bot is admin but "Post messages" permission is OFF. Channel → Administrators → your bot → enable Post Messages.')
                         : 'Channel → Administrators → Add Admin → search @' . $bot['username'] . ' → enable "Post Messages".');
        }
    }

    $wh = $tg->getWebhookInfo();
    $expected = appUrl() . '/bot/webhook.php';
    $info = $wh['result'] ?? [];
    $whOk = ($info['url'] ?? '') === $expected && tgConf('webhook_secret') !== '';
    $add($whOk, 'Webhook set to ' . $expected,
        $whOk ? (!empty($info['last_error_message']) ? 'Last error from Telegram: ' . $info['last_error_message'] : '')
              : 'Current: ' . (($info['url'] ?? '') ?: 'none') . '. Click "Set webhook" below (needed for /start, referrals and WATCH links).');
    if ($whOk && !empty($info['last_error_message'])) {
        $out[count($out) - 1][0] = false;
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $token = trim($_POST['bot_token'] ?? '');
        if ($token !== '') {
            if (!preg_match('/^\d+:[A-Za-z0-9_-]{30,}$/', $token)) {
                flash('error', 'That does not look like a bot token (format 123456789:ABC…).');
                header('Location: ' . $self);
                exit;
            }
            saveSetting('telegram', 'bot_token', $token, 'STRING', true);
            saveSetting('telegram', 'main_app_checked_at', 0, 'INTEGER');
        }
        saveSetting('telegram', 'bot_username', ltrim(trim($_POST['bot_username'] ?? ''), '@'));
        saveSetting('telegram', 'channel_id', trim($_POST['channel_id'] ?? ''));
        $mini = rtrim(trim($_POST['mini_app_url'] ?? ''), '/');
        if ($mini !== '' && !preg_match('#^https://[^\s]+$#', $mini)) {
            flash('error', 'Mini App URL must start with https://');
            header('Location: ' . $self);
            exit;
        }
        saveSetting('telegram', 'mini_app_url', $mini);
        saveSetting('telegram', 'mini_app_short_name', preg_replace('/[^A-Za-z0-9_]/', '', $_POST['mini_app_short_name'] ?? ''));
        logAudit('SETTINGS_CHANGED', 'Telegram settings updated', 'settings');
        $_SESSION['tg_diag'] = runDiagnostics();
        flash('success', 'Telegram settings saved. Check results below.');
    } elseif ($action === 'diagnose') {
        $_SESSION['tg_diag'] = runDiagnostics();
    } elseif ($action === 'set_webhook') {
        $secret = tgConf('webhook_secret');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(24));
            saveSetting('telegram', 'webhook_secret', $secret, 'STRING', true);
        }
        $r = (new Telegram())->setWebhook(appUrl() . '/bot/webhook.php', $secret);
        flash(!empty($r['ok']) ? 'success' : 'error', !empty($r['ok']) ? 'Webhook set. Send /start to your bot to test.' : 'Webhook error: ' . ($r['description'] ?? 'unknown'));
        logAudit('TELEGRAM_WEBHOOK_SET', 'Webhook set', 'settings');
    } elseif ($action === 'test_post') {
        $r = (new Telegram())->sendMessage(tgConf('channel_id'), "✅ Test message from " . htmlspecialchars((string)getSetting('general', 'app_name', APP_NAME)) . " admin panel.");
        flash(!empty($r['ok']) ? 'success' : 'error', !empty($r['ok']) ? 'Test message posted to the channel.' : 'Telegram error: ' . ($r['description'] ?? 'unknown'));
    } elseif ($action === 'test_dm') {
        $uid = preg_replace('/\D/', '', $_POST['telegram_user_id'] ?? '');
        $r = (new Telegram())->sendMessage($uid, '✅ Test message from the admin panel.',
            ['inline_keyboard' => [[['text' => '▶ Open app', 'web_app' => ['url' => tgConf('mini_app_url') . '/']]]]]);
        flash(!empty($r['ok']) ? 'success' : 'error', !empty($r['ok']) ? 'Message sent to ' . $uid . '.' : 'Telegram error: ' . ($r['description'] ?? 'unknown'));
    }
    header('Location: ' . $self);
    exit;
}

$diag = $_SESSION['tg_diag'] ?? null;
unset($_SESSION['tg_diag']);
$tokenSet = tgConf('bot_token') !== '';
$posts = db()->fetchAll(
    "SELECT tp.created_at, tp.status, tp.error_message, tp.chat_id, v.title FROM telegram_posts tp
     LEFT JOIN videos v ON v.id = tp.video_id ORDER BY tp.id DESC LIMIT 10"
);

$pageTitle = 'Telegram';
include __DIR__ . '/includes/header.php';
?>

<?php renderFlash(); ?>

<?php if ($diag): ?>
<div class="content-box">
    <h3 style="margin-bottom:12px">Check results</h3>
    <?php foreach ($diag as [$ok, $label, $detail]): ?>
        <div style="padding:8px 0;border-bottom:1px solid #eee">
            <strong style="color:<?= $ok ? '#1e7e34' : '#c0392b' ?>"><?= $ok ? '✔' : '✘' ?> <?= e($label) ?></strong>
            <?php if ($detail): ?><div style="color:#555;font-size:13px;margin-top:4px"><?= e($detail) ?></div><?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="content-box" style="max-width:820px">
    <h3 style="margin-bottom:16px">Bot settings</h3>
    <form method="POST">
        <?= CSRF::getInputField() ?>
        <input type="hidden" name="action" value="save">
        <div class="form-group">
            <label>Bot token <small>(from @BotFather; <?= $tokenSet ? '✔ saved — leave empty to keep' : 'not set' ?>)</small></label>
            <input class="form-control" type="password" name="bot_token" autocomplete="off" placeholder="<?= $tokenSet ? '••••••••••••' : '123456789:ABC…' ?>">
        </div>
        <div class="form-group">
            <label>Bot username <small>(without @ — auto-filled by "Check everything")</small></label>
            <input class="form-control" name="bot_username" value="<?= e(tgConf('bot_username')) ?>" placeholder="BharatPlayBot">
        </div>
        <div class="form-group">
            <label>Channel / group <small>(@channelusername or -100… ID; the bot must be an admin there)</small></label>
            <input class="form-control" name="channel_id" value="<?= e(tgConf('channel_id')) ?>" placeholder="@bharatplay">
        </div>
        <div class="form-group">
            <label>Mini App URL <small>(paste this same URL in @BotFather → Configure Mini App)</small></label>
            <input class="form-control" name="mini_app_url" value="<?= e(tgConf('mini_app_url')) ?>">
        </div>
        <div class="form-group">
            <label>Direct-link app short name <small>(optional; only if you created an app with /newapp)</small></label>
            <input class="form-control" name="mini_app_short_name" value="<?= e(tgConf('mini_app_short_name')) ?>">
        </div>
        <button class="btn btn-primary" type="submit">Save &amp; check</button>
    </form>
</div>

<div class="content-box" style="max-width:820px">
    <h3 style="margin-bottom:12px">Tools</h3>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <?php foreach (['diagnose' => '🔍 Check everything', 'set_webhook' => '🔗 Set webhook', 'test_post' => '📣 Send test message to channel'] as $a => $label): ?>
            <form method="POST"><?= CSRF::getInputField() ?><input type="hidden" name="action" value="<?= $a ?>">
                <button class="btn btn-primary" type="submit"><?= $label ?></button></form>
        <?php endforeach; ?>
        <form method="POST" style="display:flex;gap:6px"><?= CSRF::getInputField() ?><input type="hidden" name="action" value="test_dm">
            <input class="form-control" name="telegram_user_id" placeholder="Your Telegram user ID" style="width:200px" required>
            <button class="btn btn-primary" type="submit">💬 Send test DM</button></form>
    </div>
    <p style="color:#7f8c8d;font-size:13px;margin-top:10px">Test DM works only after you pressed START in the bot. Get your ID from @userinfobot.</p>
</div>

<div class="content-box" style="max-width:820px">
    <h3 style="margin-bottom:12px">@BotFather setup</h3>
    <ol style="line-height:1.9;padding-left:20px">
        <li><code>/mybots</code> → your bot → <b>Bot Settings → Configure Mini App → Enable Mini App</b> → URL:
            <code><?= e(tgConf('mini_app_url')) ?>/</code></li>
        <li>Optional menu button: <b>Bot Settings → Menu Button</b> → same URL.</li>
        <li>Add the bot to your channel as <b>Admin</b> with <b>Post Messages</b>.</li>
        <li>Click <b>Set webhook</b> above, then <b>Check everything</b>.</li>
    </ol>
</div>

<div class="content-box">
    <h3 style="margin-bottom:12px">Recent channel posts</h3>
    <?php if (!$posts): ?><p style="color:#7f8c8d">Nothing posted yet. Use “📱 Post to Telegram” in Videos.</p><?php else: ?>
    <table><thead><tr><th>When</th><th>Video</th><th>Chat</th><th>Status</th><th>Error</th></tr></thead><tbody>
    <?php foreach ($posts as $p): ?>
        <tr><td><?= e(timeAgo($p['created_at'])) ?></td><td><?= e($p['title']) ?></td><td><?= e($p['chat_id']) ?></td>
            <td><span class="badge badge-<?= $p['status'] === 'SENT' ? 'success' : 'danger' ?>"><?= e($p['status']) ?></span></td>
            <td style="font-size:13px;color:#c0392b"><?= e($p['error_message']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
