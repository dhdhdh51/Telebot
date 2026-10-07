<?php
/**
 * Renders an ad network's HTML/JS code (Admin → Ads → "Ad network code").
 *
 * Loaded in <iframe sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox">
 * WITHOUT allow-same-origin, so third-party ad scripts run in an opaque origin and
 * cannot read the Mini App's cookies, session or DOM.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$ad = db()->fetchOne(
    "SELECT creative_html FROM ads WHERE id = ? AND status = 'ACTIVE'
     AND (start_date IS NULL OR start_date <= NOW()) AND (end_date IS NULL OR end_date >= NOW())",
    [(int)($_GET['id'] ?? 0)]
);

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
// Ad networks load scripts/frames from many domains; isolation comes from the iframe sandbox.
header("Content-Security-Policy: default-src * data: blob: 'unsafe-inline' 'unsafe-eval'; frame-ancestors 'self'");

if (!$ad || trim((string)$ad['creative_html']) === '') {
    http_response_code(404);
    exit;
}
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>html,body{margin:0;padding:0;background:transparent;overflow:hidden;display:flex;justify-content:center;align-items:center;min-height:100%}</style>
</head><body>
<?= $ad['creative_html'] /* admin-authored (SUPER_ADMIN only), isolated by sandbox */ ?>
</body></html>
