<?php
/**
 * Earn API.
 *   GET  ?action=status
 *   POST {action:"checkin"}
 *   POST {action:"ad_start"}            → returns intent id; ad network's server confirms the view
 *   GET  ?action=ad_status&intent=...   → PENDING | COMPLETED | EXPIRED
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/rewards.php';

header('Content-Type: application/json');

$userId = requireAppUser();
session_write_close();

$action = $_GET['action'] ?? jsonInput()['action'] ?? '';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

switch ($action) {
    case 'status':
        $st = Rewards::status($userId);
        $st['invited'] = db()->fetchOne(
            "SELECT COUNT(*) total, COALESCE(SUM(reward_paid), 0) paid, COALESCE(SUM(reward_earned), 0) earned FROM referrals WHERE referrer_id = ?",
            [$userId]
        );
        jsonResponse(true, $st);
        break;

    case 'checkin':
        if (!$isPost) {
            jsonResponse(false, ['code' => 'METHOD_NOT_ALLOWED'], 'Method not allowed', 405);
        }
        $r = Rewards::dailyCheckin($userId);
        $r['success'] ? jsonResponse(true, $r, 'Check-in reward added') : jsonResponse(false, ['code' => 'CHECKIN_FAILED'], $r['error']);
        break;

    case 'ad_start':
        if (!$isPost) {
            jsonResponse(false, ['code' => 'METHOD_NOT_ALLOWED'], 'Method not allowed', 405);
        }
        $r = Rewards::startAdIntent($userId);
        $r['success'] ? jsonResponse(true, ['intent' => $r['intent']])
                      : jsonResponse(false, ['code' => 'AD_UNAVAILABLE', 'cooldown_seconds' => $r['cooldown_seconds'] ?? null], $r['error']);
        break;

    case 'ad_status':
        $s = Rewards::intentStatus($userId, (string)($_GET['intent'] ?? ''));
        $s ? jsonResponse(true, $s) : jsonResponse(false, ['code' => 'NOT_FOUND'], 'Not found', 404);
        break;

    default:
        jsonResponse(false, ['code' => 'INVALID_ACTION'], 'Invalid action');
}
