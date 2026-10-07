<?php
/**
 * Admin → Settings. Everything here is stored in the `settings` table;
 * secrets (payment keys) are stored encrypted with SECRET_KEY.
 */

require_once __DIR__ . '/includes/admin-bootstrap.php';
requireSuperAdmin();

// [category, key, label, kind, help, secret]
$sections = [
    'General' => [
        ['general', 'app_name', 'App name', 'text', 'Shown in the bot messages and payment page'],
        ['general', 'app_url', 'Site URL', 'url', 'e.g. https://bharatseo.site (no trailing slash)'],
    ],
    'Ads' => [
        ['ads', 'enabled', 'Show ads to free users', 'bool', 'Master switch. Premium users never see ads.'],
        ['ads', 'banner_enabled', 'Show banner ads', 'bool', 'Banner on Home and below the player'],
        ['ads', 'free_user_frequency', 'Video ad every N videos', 'int', '1 = before every video, 3 = every 3rd video'],
        ['ads', 'skip_after_seconds', 'Allow skip after (seconds)', 'int', 'For interstitial / video ads'],
    ],
    'Payment' => [
        ['payment', 'gateway', 'Payment gateway', 'gateway', 'Choose "None" to disable online payments (you can still grant premium from Users)'],
        ['payment', 'razorpay_key_id', 'Razorpay Key ID', 'text', 'Razorpay Dashboard → Account & Settings → API Keys (rzp_live_… or rzp_test_…)'],
        ['payment', 'razorpay_key_secret', 'Razorpay Key Secret', 'secret', 'Leave empty to keep the saved value'],
        ['payment', 'razorpay_webhook_secret', 'Razorpay Webhook Secret', 'secret', 'Any random text; put the same in Razorpay → Webhooks'],
    ],
    'Wallet & withdrawals' => [
        ['wallet', 'withdrawals_enabled', 'Allow withdrawals', 'bool', 'Turn off to pause all new withdrawal requests'],
        ['wallet', 'withdrawal_methods', 'Allowed methods', 'methods', ''],
        ['wallet', 'min_withdrawal', 'Minimum withdrawal (₹)', 'money', ''],
        ['wallet', 'max_withdrawal_daily', 'Max withdrawal per day (₹)', 'money', ''],
        ['wallet', 'withdrawal_fee_percent', 'Withdrawal fee (%)', 'money', 'e.g. 2.5'],
        ['wallet', 'withdrawal_fee_fixed', 'Withdrawal fee (fixed ₹)', 'money', ''],
    ],
    'Referral' => [
        ['referral', 'reward_amount', 'Referral bonus (₹)', 'money', 'Paid to the referrer'],
        ['referral', 'min_referred_watch_time', 'Required watch time of new user (seconds)', 'int', 'Bonus is paid only after the referred user really watched this long (anti-fake-account)'],
    ],
    'Security' => [
        ['security', 'telegram_auth_timeout', 'Mini App login validity (seconds)', 'int', 'How old Telegram login data may be (default 3600)'],
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    $errors = [];
    $in = is_array($_POST['s'] ?? null) ? $_POST['s'] : [];
    $in = array_map(fn($v) => is_string($v) ? $v : '', $in);
    foreach ($sections as $fields) {
        foreach ($fields as [$cat, $key, $label, $kind]) {
            $name = "$cat.$key";
            $val = $kind === 'bool' ? !empty($in[$name]) : trim((string)($in[$name] ?? ''));
            switch ($kind) {
                case 'bool':
                    saveSetting($cat, $key, $val, 'BOOLEAN');
                    break;
                case 'int':
                    if (!preg_match('/^\d{1,9}$/', $val)) { $errors[] = "$label must be a whole number"; break; }
                    saveSetting($cat, $key, (int)$val, 'INTEGER');
                    break;
                case 'money':
                    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $val)) { $errors[] = "$label must be a number like 100 or 2.50"; break; }
                    saveSetting($cat, $key, $val, 'STRING');
                    break;
                case 'url':
                    if ($val !== '' && !preg_match('#^https://[^\s/]+(/[^\s]*)?$#', $val)) { $errors[] = "$label must start with https://"; break; }
                    saveSetting($cat, $key, rtrim($val, '/'));
                    break;
                case 'methods':
                    $m = array_values(array_intersect((array)($_POST['methods'] ?? []), ['UPI', 'BANK_TRANSFER']));
                    saveSetting($cat, $key, $m, 'JSON');
                    break;
                case 'gateway':
                    saveSetting($cat, $key, in_array($val, ['razorpay'], true) ? $val : '');
                    break;
                case 'secret':
                    if ($val !== '') { saveSetting($cat, $key, $val, 'STRING', true); }
                    break;
                default:
                    saveSetting($cat, $key, mb_substr($val, 0, 255));
            }
        }
    }
    logAudit('SETTINGS_CHANGED', 'Settings updated', 'settings');
    flash($errors ? 'error' : 'success', $errors ? 'Saved, except: ' . implode('; ', $errors) : 'Settings saved.');
    header('Location: /admin/settings.php');
    exit;
}

$pageTitle = 'Settings';
include __DIR__ . '/includes/header.php';
?>

<?php renderFlash(); ?>

<form method="POST" style="max-width:820px">
    <?= CSRF::getInputField() ?>
    <?php foreach ($sections as $title => $fields): ?>
    <div class="content-box">
        <h3 style="margin-bottom:16px"><?= e($title) ?></h3>
        <?php foreach ($fields as [$cat, $key, $label, $kind, $help]):
            $name = "s[$cat.$key]"; $val = getSetting($cat, $key, $kind === 'bool' && $key === 'withdrawals_enabled' ? true : ''); ?>
            <div class="form-group">
                <?php if ($kind === 'bool'): ?>
                    <label style="display:flex;gap:8px;align-items:center">
                        <input type="checkbox" name="<?= e($name) ?>" value="1" <?= $val ? 'checked' : '' ?>> <?= e($label) ?>
                    </label>
                <?php else: ?>
                    <label><?= e($label) ?></label>
                    <?php if ($kind === 'methods'): $cur = is_array($val) ? $val : ['UPI', 'BANK_TRANSFER']; ?>
                        <label style="font-weight:normal;margin-right:16px"><input type="checkbox" name="methods[]" value="UPI" <?= in_array('UPI', $cur, true) ? 'checked' : '' ?>> UPI</label>
                        <label style="font-weight:normal"><input type="checkbox" name="methods[]" value="BANK_TRANSFER" <?= in_array('BANK_TRANSFER', $cur, true) ? 'checked' : '' ?>> Bank transfer</label>
                    <?php elseif ($kind === 'gateway'): ?>
                        <select class="form-control" name="<?= e($name) ?>">
                            <option value="">None (payments off)</option>
                            <option value="razorpay" <?= $val === 'razorpay' ? 'selected' : '' ?>>Razorpay (UPI, cards, netbanking)</option>
                        </select>
                    <?php elseif ($kind === 'secret'): ?>
                        <input class="form-control" type="password" autocomplete="off" name="<?= e($name) ?>" placeholder="<?= $val !== '' ? '•••••••• saved' : 'not set' ?>">
                    <?php else: ?>
                        <input class="form-control" name="<?= e($name) ?>" value="<?= e(is_bool($val) ? (int)$val : $val) ?>">
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($help): ?><small style="color:#7f8c8d"><?= e($help) ?></small><?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if ($title === 'Payment'): ?>
            <div class="alert" style="background:#eef6ff;border:1px solid #cfe3ff">
                Razorpay → Account &amp; Settings → <b>Webhooks</b> → Add:<br>
                URL: <code><?= e(appUrl()) ?>/api/payment-webhook.php?gateway=razorpay</code><br>
                Events: <code>payment.captured</code>, <code>order.paid</code> · Secret: same as above.
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <button class="btn btn-primary" type="submit" style="padding:12px 28px">Save settings</button>
</form>

<?php include __DIR__ . '/includes/footer.php'; ?>
