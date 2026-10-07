<?php
/**
 * BharatPlay installation wizard.
 *
 *   1. Requirements   2. Database (writes config/config.php, imports database.sql)
 *   3. Super admin    4. Telegram bot (optional)   5. Cron jobs + finish
 *
 * Safety:
 *  - Only a database on THIS server (localhost) is accepted, so a stranger who opens
 *    install.php before you cannot point the site at their own database.
 *  - Once an admin exists, the wizard is locked unless you are the person who just ran it
 *    (same browser session) — and step 5 deletes this file.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
session_name('bharatplay_install');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; img-src data:; frame-ancestors 'none'");

$ROOT = __DIR__;
$CONFIG = $ROOT . '/config/config.php';
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrfField = '<input type="hidden" name="csrf" value="' . $_SESSION['csrf'] . '">';
$isOwner = !empty($_SESSION['installer_owner']);

/** PDO from an existing config.php (null if not configured / not reachable). */
function installerDb($configFile) {
    if (!is_file($configFile)) {
        return null;
    }
    $src = file_get_contents($configFile);
    $get = function ($name) use ($src) {
        return preg_match("/define\\('" . $name . "',\\s*'((?:[^'\\\\]|\\\\.)*)'\\)/", $src, $m) ? stripcslashes($m[1]) : null;
    };
    try {
        return new PDO('mysql:host=' . $get('DB_HOST') . ';dbname=' . $get('DB_NAME') . ';charset=utf8mb4', $get('DB_USER'), $get('DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    } catch (PDOException $ex) {
        return null;
    }
}

$pdo = installerDb($CONFIG);
$tablesOk = false;
$adminCount = 0;
if ($pdo) {
    try {
        $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
        $tablesOk = true;
    } catch (PDOException $ex) {
        $tablesOk = false;
    }
}

// ---- Lock ----
if ($adminCount > 0 && !$isOwner) {
    http_response_code(403);
    exit('BharatPlay is already installed. For safety, delete install.php from your server (cPanel → File Manager).');
}

// Current step
$step = (int)($_GET['step'] ?? 0);
if (!$step) {
    $step = !$pdo || !$tablesOk ? 1 : ($adminCount === 0 ? 3 : 4);
}
if ($step >= 3 && (!$pdo || !$tablesOk)) $step = 2;
if ($step >= 4 && $adminCount === 0) $step = 3;
if ($step === 3 && $adminCount > 0) $step = 4;
// Step 2 must not be re-runnable by strangers once configured (it rewrites config.php).
if ($step <= 2 && $pdo && $tablesOk && !$isOwner) $step = 3;

$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $error = 'Form expired, please try again.';
    } elseif ($step === 2) {
        // ---------- STEP 2: database + config ----------
        $host = trim($_POST['db_host'] ?? 'localhost');
        $name = trim($_POST['db_name'] ?? '');
        $user = trim($_POST['db_user'] ?? '');
        $pass = (string)($_POST['db_pass'] ?? '');
        $url = rtrim(trim($_POST['app_url'] ?? ''), '/');
        $fresh = !empty($_POST['fresh']);

        if (!in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
            $error = 'For security the database must be on this server (use "localhost").';
        } elseif (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) || !preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $user)) {
            $error = 'Database name / user may contain only letters, numbers and _ (cPanel names look like cpuser_bharatplay).';
        } elseif (!preg_match('#^https://[A-Za-z0-9.-]+(:\d+)?(/[A-Za-z0-9._~/-]*)?$#', $url)) {
            $error = 'Site URL must start with https:// (e.g. https://bharatseo.site). Install SSL in cPanel first.';
        } else {
            try {
                $db = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            } catch (PDOException $ex) {
                $db = null;
                $error = 'Could not connect to the database. Check name, user and password, and that the user has ALL PRIVILEGES on the database (cPanel → MySQL Databases → Add User To Database).';
            }
            if ($db) {
                try {
                    $existing = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                    $ours = ['admin_sessions', 'audit_logs', 'ad_clicks', 'ad_impressions', 'ads', 'analytics_daily', 'notifications', 'referrals',
                        'reward_transactions', 'reward_rules', 'telegram_posts', 'video_views', 'watch_history', 'wallet_transactions', 'wallets',
                        'withdrawals', 'subscriptions', 'payments', 'subscription_plans', 'videos', 'categories', 'users', 'admins', 'settings'];
                    $present = array_values(array_intersect($ours, $existing));
                    if ($present && $fresh) {
                        $db->exec("SET FOREIGN_KEY_CHECKS = 0");
                        foreach ($present as $t) {
                            $db->exec("DROP TABLE IF EXISTS `$t`");
                        }
                        $db->exec("SET FOREIGN_KEY_CHECKS = 1");
                        $present = [];
                    }
                    if (!$present) {
                        $sql = file_get_contents($ROOT . '/database.sql');
                        $sql = preg_replace('/^--.*$/m', '', $sql);
                        foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
                            if (trim($stmt) !== '') {
                                $db->exec($stmt);
                            }
                        }
                        $notice = 'Database tables created.';
                    } elseif (count($present) < count($ours)) {
                        $error = 'This database contains only some BharatPlay tables (' . count($present) . ' of ' . count($ours) . '). Tick "Erase existing data" to start fresh, or use an empty database.';
                    } else {
                        $notice = 'Existing BharatPlay data found and kept.';
                    }
                } catch (PDOException $ex) {
                    $error = 'Importing database.sql failed: ' . $ex->getMessage();
                }

                if (!$error) {
                    $tpl = file_get_contents($ROOT . '/config/config.example.php');
                    $existingKey = null;
                    if (is_file($CONFIG) && preg_match("/define\\('SECRET_KEY',\\s*'([^']+)'\\)/", file_get_contents($CONFIG), $km)
                        && strpos($km[1], 'CHANGE_THIS') === false) {
                        $existingKey = $km[1]; // keep it: encrypted settings depend on it
                    }
                    $vals = [
                        'DB_HOST' => $host, 'DB_NAME' => $name, 'DB_USER' => $user, 'DB_PASS' => $pass,
                        'APP_URL' => $url, 'APP_ENV' => 'production',
                        'SECRET_KEY' => $existingKey ?? bin2hex(random_bytes(32)),
                        'TELEGRAM_MINI_APP_URL' => $url . '/app',
                    ];
                    foreach ($vals as $k => $v) {
                        $tpl = preg_replace_callback("/define\\('" . $k . "',\\s*'[^']*'\\);/",
                            fn() => "define('" . $k . "', " . var_export($v, true) . ");", $tpl, 1);
                    }
                    if (@file_put_contents($CONFIG, $tpl) === false) {
                        $_SESSION['config_text'] = $tpl;
                        $error = 'config/ folder is not writable. Create config/config.php in cPanel File Manager with the content shown below, then click Continue again.';
                    } else {
                        @chmod($CONFIG, 0640);
                        if ($existingKey === null && $notice === 'Existing BharatPlay data found and kept.') {
                            $_SESSION['key_changed'] = true;
                        }
                        $_SESSION['installer_owner'] = true;
                        // Site URL + app name as admin-panel settings too
                        $db->prepare("INSERT INTO settings (category, `key`, value, type) VALUES ('general','app_url',?, 'STRING') ON DUPLICATE KEY UPDATE value = VALUES(value)")->execute([$url]);
                        header('Location: install.php?step=3');
                        exit;
                    }
                }
            }
        }
    } elseif ($step === 3) {
        // ---------- STEP 3: super admin ----------
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        if (!$isOwner) {
            // A different browser (e.g. config.php was created manually): prove ownership.
            $src = file_get_contents($CONFIG);
            $dbPass = preg_match("/define\\('DB_PASS',\\s*'((?:[^'\\\\]|\\\\.)*)'\\)/", $src, $m) ? stripcslashes($m[1]) : null;
            if ($dbPass === null || !hash_equals($dbPass, (string)($_POST['db_password'] ?? ''))) {
                $error = 'Database password is incorrect.';
            }
        }
        if (!$error) {
            if ($adminCount > 0) {
                $error = 'An admin already exists.';
            } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
                $error = 'Username: 3–50 characters (letters, numbers, _ . -).';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Enter a valid email.';
            } elseif (strlen($password) < 12 || $password !== ($_POST['password2'] ?? '')) {
                $error = 'Password must be at least 12 characters and both fields must match.';
            } else {
                $pdo->prepare("INSERT INTO admins (username, email, password, full_name, role, status) VALUES (?, ?, ?, ?, 'SUPER_ADMIN', 'ACTIVE')")
                    ->execute([$username, $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $username]);
                $_SESSION['installer_owner'] = true;
                header('Location: install.php?step=4');
                exit;
            }
        }
    } elseif ($step === 4) {
        // ---------- STEP 4: Telegram (uses the real app code) ----------
        if (!empty($_POST['skip'])) {
            header('Location: install.php?step=5');
            exit;
        }
        require_once $CONFIG;
        require_once $ROOT . '/config/database.php';
        require_once $ROOT . '/includes/security.php';
        require_once $ROOT . '/includes/functions.php';
        require_once $ROOT . '/includes/telegram.php';
        $token = trim($_POST['bot_token'] ?? '');
        $channel = trim($_POST['channel_id'] ?? '');
        if (!preg_match('/^\d+:[A-Za-z0-9_-]{30,}$/', $token)) {
            $error = 'Bot token format is 123456789:ABC… (copy it from @BotFather).';
        } else {
            $tg = new Telegram($token);
            $me = $tg->getMe();
            if (empty($me['ok'])) {
                $error = 'Telegram rejected the token: ' . ($me['description'] ?? 'unknown');
            } else {
                saveSetting('telegram', 'bot_token', $token, 'STRING', true);
                saveSetting('telegram', 'bot_username', $me['result']['username']);
                saveSetting('telegram', 'channel_id', $channel);
                saveSetting('telegram', 'mini_app_url', appUrl() . '/app');
                $secret = bin2hex(random_bytes(24));
                saveSetting('telegram', 'webhook_secret', $secret, 'STRING', true);
                $wh = $tg->setWebhook(appUrl() . '/bot/webhook.php', $secret);
                $lines = ['✔ Bot connected: @' . $me['result']['username']];
                $lines[] = !empty($wh['ok']) ? '✔ Webhook set' : '✘ Webhook: ' . ($wh['description'] ?? 'failed') . ' (retry in Admin → Telegram)';
                if ($channel !== '') {
                    $chk = $tg->getChat(tgConf('channel_id'));
                    if (empty($chk['ok'])) {
                        $lines[] = '✘ Channel: ' . ($chk['description'] ?? 'not found') . ' (fix later in Admin → Telegram)';
                    } else {
                        $mem = $tg->getChatMember(tgConf('channel_id'), $me['result']['id']);
                        $st = $mem['result']['status'] ?? '';
                        $lines[] = in_array($st, ['administrator', 'creator'], true)
                            ? '✔ Channel "' . ($chk['result']['title'] ?? '') . '" — bot is admin'
                            : '✘ Bot is not an admin of "' . ($chk['result']['title'] ?? '') . '". Add it as admin with Post Messages.';
                    }
                }
                $tg->hasMainWebApp(true);
                $lines[] = getSetting('telegram', 'has_main_web_app', false) ? '✔ Main Mini App is configured in @BotFather' : '• Main Mini App not set yet (see below)';
                $_SESSION['tg_result'] = $lines;
                header('Location: install.php?step=4');
                exit;
            }
        }
    } elseif ($step === 5 && !empty($_POST['finish'])) {
        $deleted = @unlink(__FILE__);
        session_destroy();
        if ($deleted) {
            header('Location: admin/login.php');
            exit;
        }
        $error = 'Could not delete install.php automatically. Delete it in cPanel → File Manager.';
    }
}

// Requirements (step 1)
$checks = [];
$fatal = false;
$req = function ($ok, $label, $fix = '') use (&$checks, &$fatal) { $checks[] = [$ok, $label, $fix]; if (!$ok) $fatal = true; };
$req(version_compare(PHP_VERSION, '8.1.0', '>='), 'PHP ' . PHP_VERSION . ' (8.1+ needed, 8.2+ recommended)', 'cPanel → MultiPHP Manager → choose PHP 8.2 for this domain');
foreach (['pdo_mysql' => 'PDO MySQL', 'curl' => 'cURL', 'openssl' => 'OpenSSL', 'mbstring' => 'mbstring', 'fileinfo' => 'fileinfo', 'gd' => 'GD (images)', 'json' => 'JSON'] as $ext => $label) {
    $req(extension_loaded($ext), "PHP extension: $label", 'cPanel → Select PHP Version → Extensions → tick ' . $ext);
}
foreach (['config', 'uploads/videos', 'uploads/thumbnails', 'logs'] as $dir) {
    if (!is_dir("$ROOT/$dir")) @mkdir("$ROOT/$dir", 0755, true);
    $req(is_writable("$ROOT/$dir"), "Folder writable: $dir/", "cPanel → File Manager → right-click $dir → Change Permissions → 755");
}
$req(is_file("$ROOT/database.sql"), 'database.sql present', 'Upload all files again');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$checks[] = [$https, 'Opened over HTTPS', 'Telegram needs HTTPS: cPanel → SSL/TLS Status → Run AutoSSL, then open https://… (warning only)'];
$upload = ini_get('upload_max_filesize');
$checks[] = [true, "Upload limit: $upload (post_max_size " . ini_get('post_max_size') . ')', ''];

$host = preg_replace('/[^A-Za-z0-9.:-]/', '', $_SERVER['HTTP_HOST'] ?? 'yourdomain.com');
$baseGuess = 'https://' . $host . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$appUrl = $baseGuess;
if ($pdo && $tablesOk) {
    $u = $pdo->query("SELECT value FROM settings WHERE category='general' AND `key`='app_url'")->fetchColumn();
    if ($u && strpos($u, 'yourdomain') === false) $appUrl = $u;
}
$steps = [1 => 'Check', 2 => 'Database', 3 => 'Admin', 4 => 'Telegram', 5 => 'Finish'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex">
<title>BharatPlay Setup – Step <?= $step ?></title>
<style>
*{box-sizing:border-box}body{font-family:system-ui,-apple-system,sans-serif;background:#f0f2f7;color:#222;margin:0;padding:24px 12px}
.wrap{max-width:720px;margin:0 auto}.card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 2px 12px rgba(0,0,0,.06);margin-bottom:16px}
h1{margin:0 0 6px;font-size:24px}h2{margin:0 0 14px;font-size:19px}
.steps{display:flex;gap:6px;margin:16px 0}.steps div{flex:1;text-align:center;padding:8px 4px;border-radius:8px;background:#e1e5ee;font-size:13px;font-weight:600;color:#666}
.steps .on{background:#667eea;color:#fff}.steps .done{background:#c6f6d5;color:#1e7e34}
label{display:block;font-weight:600;margin:12px 0 4px;font-size:14px}small{color:#666;font-weight:normal}
input[type=text],input[type=password],input[type=email],input[type=url]{width:100%;padding:10px 12px;border:1px solid #ccd;border-radius:8px;font-size:15px}
.btn{display:inline-block;padding:12px 22px;border:0;border-radius:8px;background:#667eea;color:#fff;font-size:15px;font-weight:700;cursor:pointer;text-decoration:none;margin-top:16px}
.btn.grey{background:#8a94a6}.btn.red{background:#e74c3c}
.ok{color:#1e7e34}.bad{color:#c0392b}li{margin:6px 0}
.err{background:#fdecea;color:#a12;padding:12px;border-radius:8px;margin-bottom:12px}.note{background:#e6f4ea;color:#1e6b34;padding:12px;border-radius:8px;margin-bottom:12px}
code,pre{background:#1e2235;color:#e7e9f3;border-radius:6px;font-size:13px}code{padding:2px 6px}pre{padding:12px;overflow-x:auto;white-space:pre-wrap;word-break:break-all}
.warn{background:#fff6e0;padding:10px;border-radius:8px;font-size:14px}
</style>
</head>
<body><div class="wrap">
<div class="card">
    <h1>🎬 BharatPlay Setup</h1>
    <div class="steps"><?php foreach ($steps as $n => $l): ?><div class="<?= $n === $step ? 'on' : ($n < $step ? 'done' : '') ?>"><?= $n ?>. <?= $l ?></div><?php endforeach; ?></div>
    <?php if ($error): ?><div class="err"><?= $e($error) ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="note"><?= $e($notice) ?></div><?php endif; ?>

<?php if ($step === 1): ?>
    <h2>Step 1 – Server check</h2>
    <ul style="list-style:none;padding:0">
    <?php foreach ($checks as [$ok, $label, $fix]): ?>
        <li class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✔' : '✘' ?> <?= $e($label) ?><?php if (!$ok && $fix): ?><br><small><?= $e($fix) ?></small><?php endif; ?></li>
    <?php endforeach; ?>
    </ul>
    <?php if ($fatal): ?><p class="bad">Fix the ✘ items, then reload this page.</p><a class="btn grey" href="install.php?step=1">Reload</a>
    <?php else: ?><a class="btn" href="install.php?step=2">Continue →</a><?php endif; ?>

<?php elseif ($step === 2): ?>
    <h2>Step 2 – Database</h2>
    <p>First create it in <b>cPanel → MySQL® Databases</b>:</p>
    <ol style="font-size:14px">
        <li><b>Create New Database</b> → e.g. <code>bharatplay</code> (cPanel adds a prefix: <code>cpuser_bharatplay</code>)</li>
        <li><b>Add New User</b> → choose a username + strong password</li>
        <li><b>Add User To Database</b> → select both → tick <b>ALL PRIVILEGES</b> → Make Changes</li>
    </ol>
    <form method="POST" autocomplete="off"><?= $csrfField ?>
        <label>Database host <small>(almost always localhost)</small></label><input type="text" name="db_host" value="<?= $e($_POST['db_host'] ?? 'localhost') ?>">
        <label>Database name <small>(full name incl. prefix)</small></label><input type="text" name="db_name" required value="<?= $e($_POST['db_name'] ?? '') ?>" placeholder="cpuser_bharatplay">
        <label>Database user</label><input type="text" name="db_user" required value="<?= $e($_POST['db_user'] ?? '') ?>" placeholder="cpuser_bpuser">
        <label>Database password</label><input type="password" name="db_pass" required>
        <label>Site URL <small>(where these files are, with https)</small></label><input type="url" name="app_url" required value="<?= $e($_POST['app_url'] ?? $baseGuess) ?>">
        <label style="font-weight:normal;margin-top:16px"><input type="checkbox" name="fresh" value="1"> <b class="bad">Erase existing BharatPlay data</b> in this database and start fresh <small>(only for a re-install; deletes users, videos, payments)</small></label>
        <button class="btn">Connect &amp; create tables →</button>
    </form>
    <?php if (!empty($_SESSION['config_text'])): ?>
        <h3>config/config.php</h3><pre><?= $e($_SESSION['config_text']) ?></pre>
    <?php endif; ?>

<?php elseif ($step === 3): ?>
    <h2>Step 3 – Create your admin account</h2>
    <form method="POST" autocomplete="off"><?= $csrfField ?>
        <label>Username</label><input type="text" name="username" required value="<?= $e($_POST['username'] ?? 'admin') ?>">
        <label>Email</label><input type="email" name="email" required value="<?= $e($_POST['email'] ?? '') ?>">
        <label>Password <small>(min 12 characters)</small></label><input type="password" name="password" required minlength="12">
        <label>Repeat password</label><input type="password" name="password2" required minlength="12">
        <?php if (!$isOwner): ?>
            <label>Database password <small>(proves you own this server)</small></label><input type="password" name="db_password" required>
        <?php endif; ?>
        <button class="btn">Create admin →</button>
    </form>

<?php elseif ($step === 4): ?>
    <h2>Step 4 – Connect your Telegram bot</h2>
    <?php if (!empty($_SESSION['key_changed'])): unset($_SESSION['key_changed']); ?>
        <div class="warn">Your old data was kept, but a new encryption key was created, so saved secrets (bot token, payment keys, payout details of old withdrawals) must be entered again.</div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['tg_result'])): ?>
        <div class="note"><?php foreach ($_SESSION['tg_result'] as $l) echo $e($l) . '<br>'; unset($_SESSION['tg_result']); ?></div>
    <?php endif; ?>
    <ol style="font-size:14px;line-height:1.7">
        <li>Telegram → <b>@BotFather</b> → <code>/newbot</code> → copy the <b>token</b>.</li>
        <li>Create your channel → <b>Administrators → Add Admin</b> → your bot → allow <b>Post Messages</b>.</li>
        <li>@BotFather → <code>/mybots</code> → your bot → <b>Bot Settings → Configure Mini App → Enable Mini App</b> → paste:<br><code><?= $e($appUrl) ?>/app/</code></li>
        <li>(Optional) <b>Bot Settings → Menu Button</b> → same URL, text “Open”.</li>
    </ol>
    <form method="POST" autocomplete="off"><?= $csrfField ?>
        <label>Bot token</label><input type="password" name="bot_token" placeholder="123456789:ABC…">
        <label>Channel <small>(@channelusername or -100… ID; can be added later)</small></label><input type="text" name="channel_id" placeholder="@bharatplay">
        <button class="btn">Connect bot</button>
        <button class="btn grey" name="skip" value="1">Skip / Next →</button>
    </form>
    <p><small>Everything here can be changed later in <b>Admin → Telegram</b>.</small></p>

<?php else: ?>
    <h2>Step 5 – Cron jobs &amp; finish</h2>
    <p>cPanel → <b>Cron Jobs</b> → add these 4 lines (Common Settings: as shown).<br>
       <small>If cPanel shows a different PHP path, check it with <code>which php</code> in Terminal — usually <code>/usr/local/bin/php</code>.</small></p>
    <pre><?php $p = $ROOT; echo $e("0 * * * *  /usr/local/bin/php $p/cron/subscription-expiry.php >/dev/null 2>&1
15 * * * * /usr/local/bin/php $p/cron/referral-rewards.php >/dev/null 2>&1
0 1 * * *  /usr/local/bin/php $p/cron/analytics.php >/dev/null 2>&1
0 3 * * *  /usr/local/bin/php $p/cron/reward-cleanup.php >/dev/null 2>&1"); ?></pre>
    <h3>After login, in the admin panel</h3>
    <ol style="font-size:14px;line-height:1.8">
        <li><b>Telegram</b> → click <b>🔍 Check everything</b> (all ✔?)</li>
        <li><b>Videos → + Upload Video</b> → tick “Post to Telegram”</li>
        <li><b>Subscriptions</b> → set your plan prices</li>
        <li><b>Settings → Payment</b> → Razorpay keys (test keys first)</li>
        <li><b>Ads</b> → add banner / ad network code</li>
        <li><b>Rewards</b> → Adsgram block ID (optional)</li>
    </ol>
    <div class="warn">⚠ Uncomment the two “Force HTTPS” lines at the top of <code>.htaccess</code> once https works.</div>
    <form method="POST"><?= $csrfField ?><input type="hidden" name="finish" value="1">
        <button class="btn">✔ Finish – delete installer &amp; go to admin login</button></form>
<?php endif; ?>
</div>
</div></body></html>
