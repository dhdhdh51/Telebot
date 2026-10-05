<?php
/**
 * Advertisement Management
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/security.php';

class Ads {
    
    /**
     * Get ad to show based on rules and frequency
     */
    public static function getAdToShow($userId, $videoId = null, $adType = 'INTERSTITIAL') {
        $db = db();
        
        // Check if ads are enabled
        if (!getSetting('ads', 'enabled', true)) {
            return null;
        }
        
        // Check user subscription (premium users don't see ads)
        if (hasActiveSubscription($userId)) {
            return null;
        }
        
        // Get frequency setting
        $frequency = (int)getSetting('ads', 'free_user_frequency', 3);
        
        // Check if user should see ad based on frequency
        if ($frequency > 0) {
            // Count videos watched since last ad
            $lastAdTime = $db->fetchOne(
                "SELECT MAX(created_at) as last_ad FROM ad_impressions WHERE user_id = ?",
                [$userId]
            );
            
            if ($lastAdTime && $lastAdTime['last_ad']) {
                $videosSinceAd = $db->fetchOne(
                    "SELECT COUNT(*) as count FROM video_views 
                     WHERE user_id = ? AND created_at > ?",
                    [$userId, $lastAdTime['last_ad']]
                );
                
                if ($videosSinceAd['count'] < $frequency) {
                    return null; // Not time for ad yet
                }
            }
        }
        
        // Get active ads of specified type
        $ads = $db->fetchAll(
            "SELECT * FROM ads 
             WHERE type = ? AND status = 'ACTIVE' 
             AND (start_date IS NULL OR start_date <= NOW()) 
             AND (end_date IS NULL OR end_date >= NOW()) 
             ORDER BY priority DESC, RAND() 
             LIMIT 1",
            [$adType]
        );
        
        if (empty($ads)) {
            return null;
        }
        
        $ad = $ads[0];
        
        // Log impression
        $db->execute(
            "INSERT INTO ad_impressions (ad_id, user_id, video_id, ip_address, user_agent) 
             VALUES (?, ?, ?, ?, ?)",
            [
                $ad['id'],
                $userId,
                $videoId,
                Security::getClientIP(),
                Security::getUserAgent()
            ]
        );
        
        $impressionId = $db->lastInsertId();
        
        // Update ad stats
        $db->execute("UPDATE ads SET impressions = impressions + 1 WHERE id = ?", [$ad['id']]);
        
        // Add impression ID to ad data
        $ad['impression_id'] = $impressionId;
        
        return $ad;
    }
    
    /**
     * Track ad click
     */
    public static function trackClick($adId, $userId, $impressionId = null) {
        $db = db();
        
        $db->execute(
            "INSERT INTO ad_clicks (ad_id, user_id, impression_id, ip_address, user_agent) 
             VALUES (?, ?, ?, ?, ?)",
            [
                $adId,
                $userId,
                $impressionId,
                Security::getClientIP(),
                Security::getUserAgent()
            ]
        );
        
        // Update ad stats
        $db->execute("UPDATE ads SET clicks = clicks + 1 WHERE id = ?", [$adId]);
        
        // Mark impression as clicked
        if ($impressionId) {
            $db->execute("UPDATE ad_impressions SET clicked = 1 WHERE id = ?", [$impressionId]);
        }
        
        return true;
    }
    
    /**
     * Track ad completion (for video ads)
     */
    public static function trackCompletion($adId, $impressionId) {
        $db = db();
        
        // Update ad stats
        $db->execute("UPDATE ads SET completions = completions + 1 WHERE id = ?", [$adId]);
        
        // Mark impression as completed
        if ($impressionId) {
            $db->execute("UPDATE ad_impressions SET completed = 1 WHERE id = ?", [$impressionId]);
        }
        
        return true;
    }
    
    /**
     * Get rewarded ad (if available)
     */
    public static function getRewardedAd($userId) {
        // Check if rewarded ads are enabled
        if (!getSetting('ads', 'rewarded_ads_enabled', true)) {
            return null;
        }
        
        return self::getAdToShow($userId, null, 'REWARDED');
    }
}
