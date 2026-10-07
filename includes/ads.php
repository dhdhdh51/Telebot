<?php
/**
 * Advertisement selection and tracking. Everything is controlled from Admin → Ads
 * (creatives, dates, priority) and Admin → Settings → Ads (on/off, frequency).
 *
 * Ad types:
 *   BANNER       – strip on Home and below the player (every page view)
 *   INTERSTITIAL – full card before a video starts (every N videos, skippable after X s)
 *   VIDEO        – short video ad before the video (same frequency rule)
 * Creative = image + link, video URL + link, or ad-network HTML/JS code
 * (rendered in a sandboxed iframe by api/ad-frame.php).
 * Premium users never get ads (checked here, server-side).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/functions.php';

class Ads {

    public static function getAdToShow($userId, $videoId = null, $adType = 'INTERSTITIAL') {
        $db = db();

        if (!getSetting('ads', 'enabled', true) || hasActiveSubscription($userId)) {
            return null;
        }
        if ($adType === 'BANNER' && !getSetting('ads', 'banner_enabled', true)) {
            return null;
        }

        // Frequency applies to ads that interrupt playback, not to banners.
        if ($adType !== 'BANNER') {
            $frequency = (int)getSetting('ads', 'free_user_frequency', 3);
            if ($frequency > 1) {
                $last = $db->fetchOne(
                    "SELECT MAX(ai.created_at) AS last_ad FROM ad_impressions ai JOIN ads a ON a.id = ai.ad_id
                     WHERE ai.user_id = ? AND a.type IN ('INTERSTITIAL', 'VIDEO')",
                    [$userId]
                );
                if ($last && $last['last_ad']) {
                    $since = $db->fetchOne(
                        "SELECT COUNT(*) AS c FROM video_views WHERE user_id = ? AND created_at > ?",
                        [$userId, $last['last_ad']]
                    );
                    if ($since['c'] < $frequency) {
                        return null;
                    }
                }
            }
        }

        $typeSql = $adType === 'BANNER' ? "type = 'BANNER'" : "type IN ('INTERSTITIAL', 'VIDEO')";
        $ad = $db->fetchOne(
            "SELECT * FROM ads
             WHERE $typeSql AND status = 'ACTIVE'
             AND (start_date IS NULL OR start_date <= NOW())
             AND (end_date IS NULL OR end_date >= NOW())
             ORDER BY priority DESC, RAND()
             LIMIT 1"
        );
        if (!$ad) {
            return null;
        }

        $db->execute(
            "INSERT INTO ad_impressions (ad_id, user_id, video_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)",
            [$ad['id'], $userId, $videoId, Security::getClientIP(), Security::getUserAgent()]
        );
        $ad['impression_id'] = (int)$db->lastInsertId();
        $db->execute("UPDATE ads SET impressions = impressions + 1 WHERE id = ?", [$ad['id']]);
        return $ad;
    }

    /** Public, client-safe representation of an ad. */
    public static function toClient(array $ad) {
        return [
            'id' => (int)$ad['id'],
            'impression_id' => (int)$ad['impression_id'],
            'type' => $ad['type'],
            'name' => $ad['name'],
            'image_url' => $ad['image_url'] ?: null,
            'video_url' => $ad['video_url'] ?: null,
            'destination_url' => $ad['destination_url'] ?: null,
            'html_frame' => trim((string)$ad['creative_html']) !== '' ? '/api/ad-frame.php?id=' . (int)$ad['id'] : null,
            'skip_after' => max(0, (int)getSetting('ads', 'skip_after_seconds', 5)),
        ];
    }

    public static function trackClick($adId, $userId, $impressionId = null) {
        $db = db();
        $db->execute(
            "INSERT INTO ad_clicks (ad_id, user_id, impression_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)",
            [$adId, $userId, $impressionId, Security::getClientIP(), Security::getUserAgent()]
        );
        $db->execute("UPDATE ads SET clicks = clicks + 1 WHERE id = ?", [$adId]);
        if ($impressionId) {
            $db->execute("UPDATE ad_impressions SET clicked = 1 WHERE id = ?", [$impressionId]);
        }
        return true;
    }

    public static function trackCompletion($adId, $impressionId) {
        $db = db();
        $db->execute("UPDATE ads SET completions = completions + 1 WHERE id = ?", [$adId]);
        if ($impressionId) {
            $db->execute("UPDATE ad_impressions SET completed = 1 WHERE id = ?", [$impressionId]);
        }
        return true;
    }
}
