<?php
/**
 * Analytics Aggregation Cron Job
 * Run daily to aggregate analytics data
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = db();

echo "Starting analytics aggregation...\n";

try {
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    // Check if already aggregated
    $existing = $db->fetchOne(
        "SELECT id FROM analytics_daily WHERE date = ?",
        [$yesterday]
    );
    
    if ($existing) {
        echo "Analytics for {$yesterday} already exists\n";
        exit(0);
    }
    
    // Aggregate data
    $stats = [
        'total_users' => $db->fetchOne("SELECT COUNT(*) as count FROM users WHERE DATE(created_at) <= ?", [$yesterday])['count'],
        'new_users' => $db->fetchOne("SELECT COUNT(*) as count FROM users WHERE DATE(created_at) = ?", [$yesterday])['count'],
        'active_users' => $db->fetchOne("SELECT COUNT(DISTINCT user_id) as count FROM video_views WHERE DATE(created_at) = ?", [$yesterday])['count'],
        'premium_users' => $db->fetchOne("SELECT COUNT(DISTINCT user_id) as count FROM subscriptions WHERE status = 'ACTIVE' AND DATE(end_date) >= ? AND DATE(start_date) <= ?", [$yesterday, $yesterday])['count'],
        'total_videos' => $db->fetchOne("SELECT COUNT(*) as count FROM videos WHERE status = 'PUBLISHED' AND DATE(published_at) <= ?", [$yesterday])['count'],
        'video_views' => $db->fetchOne("SELECT COUNT(*) as count FROM video_views WHERE DATE(created_at) = ?", [$yesterday])['count'],
        'unique_viewers' => $db->fetchOne("SELECT COUNT(DISTINCT user_id) as count FROM video_views WHERE DATE(created_at) = ?", [$yesterday])['count'],
        'total_watch_time' => $db->fetchOne("SELECT COALESCE(SUM(watch_duration), 0) as total FROM video_views WHERE DATE(created_at) = ?", [$yesterday])['total'],
        'ad_impressions' => $db->fetchOne("SELECT COUNT(*) as count FROM ad_impressions WHERE DATE(created_at) = ?", [$yesterday])['count'],
        'ad_clicks' => $db->fetchOne("SELECT COUNT(*) as count FROM ad_clicks WHERE DATE(created_at) = ?", [$yesterday])['count'],
        'rewards_paid' => $db->fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM reward_transactions WHERE status = 'COMPLETED' AND DATE(created_at) = ?", [$yesterday])['total'],
        'subscription_revenue' => $db->fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'SUCCESS' AND DATE(created_at) = ?", [$yesterday])['total'],
        'withdrawals_paid' => $db->fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM withdrawals WHERE status = 'PAID' AND DATE(processed_at) = ?", [$yesterday])['total']
    ];
    
    // Insert analytics record
    $db->execute(
        "INSERT INTO analytics_daily (date, total_users, new_users, active_users, premium_users, total_videos, video_views, unique_viewers, total_watch_time, ad_impressions, ad_clicks, rewards_paid, subscription_revenue, withdrawals_paid) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $yesterday,
            $stats['total_users'],
            $stats['new_users'],
            $stats['active_users'],
            $stats['premium_users'],
            $stats['total_videos'],
            $stats['video_views'],
            $stats['unique_viewers'],
            $stats['total_watch_time'],
            $stats['ad_impressions'],
            $stats['ad_clicks'],
            $stats['rewards_paid'],
            $stats['subscription_revenue'],
            $stats['withdrawals_paid']
        ]
    );
    
    echo "Analytics aggregated successfully for {$yesterday}\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
