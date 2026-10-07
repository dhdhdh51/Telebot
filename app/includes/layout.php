<?php
/**
 * Shared Mini App layout: <head>, top bar and bottom navigation.
 * Pages are static shells; all data comes from the JSON APIs (session-authenticated).
 */

function appNav($active) {
    $tabs = [
        'index' => ['🏠', 'Home'], 'search' => ['🔎', 'Search'], 'categories' => ['🎬', 'Categories'],
        'earn' => ['💰', 'Earn'], 'subscription' => ['💎', 'Premium'], 'profile' => ['👤', 'Profile'],
    ];
    echo '<nav class="bottom-nav">';
    foreach ($tabs as $page => [$icon, $label]) {
        if (!file_exists(__DIR__ . "/../{$page}.php")) {
            continue;
        }
        $cls = 'nav-item' . ($page === $active ? ' active' : '');
        echo '<a href="/app/' . $page . '.php" class="' . $cls . '"><span>' . $icon . '</span><span>' . $label . '</span></a>';
    }
    echo '</nav>';
}

require_once __DIR__ . '/../../includes/csp.php';

function appHead($title, array $cspExtra = []) {
    setCsp($cspExtra);
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>{$t} - BharatPlay</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="app-container">
    <div class="header">
        <div class="logo">🎬 BharatPlay</div>
        <div class="user-info"><span id="premiumBadge" style="display:none;" class="premium-badge">PREMIUM</span></div>
    </div>
    <div class="content">
        <div id="errorContainer"></div>
HTML;
}

function appFoot($active) {
    echo "    </div>\n";
    appNav($active);
    echo "</div>\n<script src=\"/assets/js/app.js\"></script>\n";
}
