<?php
/**
 * Admin Panel Header
 */

if (!defined('ADMIN_PAGE')) {
    die('Direct access not permitted');
}

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
require_once __DIR__ . '/../../includes/csrf.php';
$currentAdmin = Auth::getCurrentAdmin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'Admin Panel'; ?> - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="/assets/css/admin.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        
        .admin-wrapper {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 260px;
            background: #2c3e50;
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }
        
        .sidebar-header {
            padding: 24px 20px;
            background: #1a252f;
            border-bottom: 1px solid #34495e;
        }
        
        .sidebar-header h2 {
            font-size: 20px;
            color: #3498db;
        }
        
        .sidebar-menu {
            padding: 16px 0;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: #ecf0f1;
            text-decoration: none;
            transition: background 0.2s;
        }
        
        .sidebar-menu a:hover {
            background: #34495e;
        }
        
        .sidebar-menu a.active {
            background: #3498db;
            color: white;
        }
        
        .sidebar-menu a span {
            margin-right: 12px;
            font-size: 18px;
        }
        
        .main-content {
            margin-left: 260px;
            flex: 1;
            padding: 24px;
            width: calc(100% - 260px);
        }
        
        .top-bar {
            background: white;
            padding: 16px 24px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .top-bar h1 {
            font-size: 24px;
            color: #2c3e50;
        }
        
        .top-bar-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        
        .admin-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .admin-avatar {
            width: 36px;
            height: 36px;
            background: #3498db;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: transform 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
        }
        
        .btn-primary {
            background: #3498db;
            color: white;
        }
        
        .btn-danger {
            background: #e74c3c;
            color: white;
        }
        
        .content-box {
            background: white;
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            margin-bottom: 24px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .stat-card h3 {
            font-size: 14px;
            color: #7f8c8d;
            margin-bottom: 8px;
        }
        
        .stat-card .value {
            font-size: 32px;
            font-weight: 700;
            color: #2c3e50;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        table thead {
            background: #f8f9fa;
        }
        
        table th {
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #dee2e6;
        }
        
        table td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
        }
        
        table tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }
        
        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .badge-info {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #2c3e50;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #3498db;
        }
        
        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                position: relative;
                height: auto;
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>🎬 <?php echo APP_NAME; ?></h2>
                <p>Admin Panel</p>
            </div>
            
            <nav class="sidebar-menu">
                <?php
                // Only link pages that exist, so the menu never leads to a 404.
                $menu = [
                    'dashboard' => ['📊', 'Dashboard'], 'videos' => ['🎬', 'Videos'], 'vidvault' => ['☁️', 'VidVault'],
                    'categories' => ['📁', 'Categories'], 'telegram' => ['📱', 'Telegram'],
                    'users' => ['👥', 'Users'], 'subscriptions' => ['💎', 'Subscriptions'],
                    'ads' => ['📺', 'Ads'], 'rewards' => ['🎁', 'Rewards'],
                    'wallet' => ['👛', 'Wallet'], 'withdrawals' => ['💰', 'Withdrawals'],
                    'referrals' => ['🤝', 'Referrals'], 'reports' => ['📈', 'Reports'],
                    'settings' => ['⚙️', 'Settings'], 'logs' => ['📋', 'Audit Logs'],
                ];
                foreach ($menu as $page => [$icon, $label]):
                    if (!file_exists(__DIR__ . "/../{$page}.php")) continue;
                    if (in_array($page, ['telegram', 'settings'], true) && ($currentAdmin['role'] ?? '') !== 'SUPER_ADMIN') continue; ?>
                <a href="/admin/<?php echo $page; ?>.php" class="<?php echo ($currentPage === $page || ($page === 'videos' && strpos($currentPage, 'video-') === 0)) ? 'active' : ''; ?>">
                    <span><?php echo $icon; ?></span> <?php echo $label; ?>
                </a>
                <?php endforeach; ?>
                <form method="POST" action="/admin/logout.php" style="margin-top: 20px; border-top: 1px solid #34495e; padding-top: 20px;">
                    <?php echo CSRF::getInputField(); ?>
                    <button type="submit" style="background:none;border:0;color:#ecf0f1;padding:12px 20px;cursor:pointer;font-size:inherit;width:100%;text-align:left;">
                        <span>🚪</span> Logout
                    </button>
                </form>
            </nav>
        </aside>
        
        <main class="main-content">
            <div class="top-bar">
                <h1><?php echo $pageTitle ?? 'Dashboard'; ?></h1>
                <div class="top-bar-actions">
                    <div class="admin-info">
                        <div class="admin-avatar">
                            <?php echo strtoupper(substr($currentAdmin['username'], 0, 1)); ?>
                        </div>
                        <div>
                            <strong><?php echo htmlspecialchars($currentAdmin['username']); ?></strong><br>
                            <small><?php echo htmlspecialchars($currentAdmin['role']); ?></small>
                        </div>
                    </div>
                </div>
            </div>
