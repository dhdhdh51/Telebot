<?php
/**
 * BharatPlay installer: environment check + first SUPER_ADMIN creation.
 *
 * Safety:
 *  - Refuses to run once any admin exists (cannot be used to add admins later).
 *  - Creating the admin requires the database password from config.php, so a
 *    stranger who finds this URL before you do cannot take over the install.
 *  - Delete this file after installation.
 */

$checks = [];
$fatal = false;
$check = function ($ok, $label, $fix = '') use (&$checks, &$fatal) {
    $checks[] = [$ok, $label, $fix];
    if (!$ok) $fatal = true;
};

$check(version_compare(PHP_VERSION, '8.1.0', '>='), 'PHP ' . PHP_VERSION . ' (8.1+ required, 8.2+ recommended)', 'Select PHP 8.2 in cPanel → MultiPHP Manager');
foreach (['pdo_mysql', 'curl', 'openssl', 'json', 'mbstring', 'fileinfo', 'gd'] as $ext) {
    $check(extension_loaded($ext), "PHP extension: $ext", 'Enable it in cPanel → Select PHP Version → Extensions');
}
foreach (['uploads/videos', 'uploads/thumbnails', 'logs'] as $dir) {
    $check(is_dir(__DIR__ . "/$dir") && is_writable(__DIR__ . "/$dir"), "Writable: $dir/", "chmod 755 $dir");
}
$hasConfig = file_exists(__DIR__ . '/config/config.php');
$check($hasConfig, 'config/config.php exists', 'Copy config/config.example.php to config/config.php and edit it');

$db = null;
$adminCount = null;
if ($hasConfig) {
    require_once __DIR__ . '/config/config.php';
    $check(defined('SECRET_KEY') && strlen(SECRET_KEY) >= 32 && strpos(SECRET_KEY, 'CHANGE_THIS') === false,
        'SECRET_KEY is set (32+ random chars)', 'Set a long random SECRET_KEY in config.php');
    $check(defined('TELEGRAM_BOT_TOKEN') && preg_match('/^\d+:[\w-]{30,}$/', TELEGRAM_BOT_TOKEN),
        'TELEGRAM_BOT_TOKEN looks valid', 'Paste the token from @BotFather');
    try {
        $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $check(true, 'Database connection');
        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_diff(['admins', 'users', 'videos', 'wallets', 'wallet_transactions', 'settings'], $tables);
        $check(!$missing, 'Database tables imported (' . count($tables) . ' found)', 'Import database.sql in phpMyAdmin');
        if (!$missing) {
            $adminCount = (int)$db->query("SELECT COUNT(*) FROM admins")->fetchColumn();
        }
    } catch (PDOException $e) {
        $check(false, 'Database connection', 'Check DB_HOST / DB_NAME / DB_USER / DB_PASS in config.php');
    }
}

// Lock: once an admin exists, the installer is permanently disabled.
if ($adminCount) {
    http_response_code(403);
    exit('BharatPlay is already installed. Delete install.php from the server.');
}

$message = '';
$created = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$fatal && $adminCount === 0) {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!hash_equals((string)DB_PASS, (string)($_POST['db_password'] ?? ''))) {
        $message = 'Database password is incorrect.';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
        $message = 'Username: 3-50 characters (letters, digits, _ . -).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Enter a valid email address.';
    } elseif (strlen($password) < 12) {
        $message = 'Password must be at least 12 characters.';
    } else {
        $stmt = $db->prepare("INSERT INTO admins (username, email, password, full_name, role, status) VALUES (?, ?, ?, ?, 'SUPER_ADMIN', 'ACTIVE')");
        $stmt->execute([$username, $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $username]);
        $created = true;
    }
}
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BharatPlay Installer</title>
<style>
body{font-family:system-ui,sans-serif;max-width:720px;margin:40px auto;padding:0 16px;color:#222}
li{margin:4px 0}.ok{color:#1e7e34}.bad{color:#c0392b}small{color:#666}
form{background:#f6f7f9;padding:20px;border-radius:8px}input{display:block;width:100%;padding:8px;margin:4px 0 12px;box-sizing:border-box}
button{padding:10px 18px;background:#3498db;color:#fff;border:0;border-radius:6px;cursor:pointer}
.msg{padding:10px;border-radius:6px;background:#fdecea;color:#a12}.done{background:#e6f4ea;color:#1e7e34}
</style>
</head>
<body>
<h1>🎬 BharatPlay Installer</h1>
<h3>Environment</h3>
<ul>
<?php foreach ($checks as [$ok, $label, $fix]): ?>
  <li class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✔' : '✘' ?> <?= $e($label) ?><?php if (!$ok && $fix): ?> — <small><?= $e($fix) ?></small><?php endif; ?></li>
<?php endforeach; ?>
</ul>

<?php if ($created): ?>
  <p class="msg done">Super admin created. <strong>Delete install.php now</strong>, then <a href="/admin/login.php">log in</a>.</p>
<?php elseif ($fatal): ?>
  <p class="msg">Fix the items marked ✘ and reload this page.</p>
<?php else: ?>
  <h3>Create the first super admin</h3>
  <?php if ($message): ?><p class="msg"><?= $e($message) ?></p><?php endif; ?>
  <form method="POST" autocomplete="off">
    <label>Username<input name="username" required value="<?= $e($_POST['username'] ?? '') ?>"></label>
    <label>Email<input name="email" type="email" required value="<?= $e($_POST['email'] ?? '') ?>"></label>
    <label>Password (min 12 characters)<input name="password" type="password" required minlength="12"></label>
    <label>Database password from config.php <small>(proves you own this installation)</small><input name="db_password" type="password" required></label>
    <button type="submit">Create admin</button>
  </form>
<?php endif; ?>
</body>
</html>
