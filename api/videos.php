<?php
/**
 * Videos API
 * Listing, search, categories, watch progress, ads.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/video.php';
require_once __DIR__ . '/../includes/ads.php';
require_once __DIR__ . '/../includes/telegram.php';

header('Content-Type: application/json');

$userId = requireAppUser();
// Release the session lock so parallel requests (and the video stream)
// from the same user aren't serialised behind each other.
session_write_close();

// POST requests send JSON bodies, which never populate $_POST.
$action = $_GET['action'] ?? jsonInput()['action'] ?? '';

/**
 * Public columns only. video_path / hls_path are storage internals and must
 * never reach the client — playback goes through api/video-stream.php.
 */
const VIDEO_PUBLIC_COLS = "v.id, v.title, v.slug, v.description, v.category_id, v.thumbnail,
    v.duration, v.access_type, v.views, v.tags, v.published_at,
    c.name AS category_name, c.slug AS category_slug";

switch ($action) {
    case 'list':              listVideos(); break;
    case 'get':               getVideo($userId); break;
    case 'categories':        getCategories(); break;
    case 'category_videos':   getCategoryVideos(); break;
    case 'search':            searchVideos(); break;
    case 'continue_watching': getContinueWatching($userId); break;
    case 'trending':          getTrendingVideos(); break;
    case 'related':           getRelatedVideos(); break;
    case 'update_progress':   updateWatchProgress($userId); break;
    case 'get_ad':            getAd($userId); break;
    case 'ad_click':          adClick($userId); break;
    case 'ad_complete':       adComplete($userId); break;
    default:
        jsonResponse(false, ['code' => 'INVALID_ACTION'], 'Invalid action');
}

function pageParams() {
    return [
        max(1, (int)($_GET['page'] ?? 1)),
        min(50, max(1, (int)($_GET['per_page'] ?? 20)))
    ];
}

function listVideos() {
    [$page, $perPage] = pageParams();
    $sql = "SELECT " . VIDEO_PUBLIC_COLS . " FROM videos v
            LEFT JOIN categories c ON v.category_id = c.id
            WHERE v.status = 'PUBLISHED'";
    $params = [];
    $accessType = $_GET['access_type'] ?? null;
    if (in_array($accessType, ['FREE', 'PREMIUM'], true)) {
        $sql .= " AND v.access_type = ?";
        $params[] = $accessType;
    }
    $sql .= " ORDER BY v.published_at DESC, v.id DESC";
    jsonResponse(true, paginate($sql, $params, $page, $perPage));
}

function getVideo($userId) {
    $videoId = (int)($_GET['id'] ?? 0);
    if (!$videoId) {
        jsonResponse(false, ['code' => 'MISSING_ID'], 'Video ID required');
    }

    $video = db()->fetchOne(
        "SELECT " . VIDEO_PUBLIC_COLS . ", v.status FROM videos v
         LEFT JOIN categories c ON v.category_id = c.id WHERE v.id = ?",
        [$videoId]
    );
    if (!$video || $video['status'] !== 'PUBLISHED') {
        jsonResponse(false, ['code' => 'VIDEO_NOT_FOUND'], 'Video not found', 404);
    }
    unset($video['status']);

    $canAccess = canAccessVideo($userId, $video);
    $watchHistory = db()->fetchOne(
        "SELECT last_position, completed FROM watch_history WHERE user_id = ? AND video_id = ?",
        [$userId, $videoId]
    );

    if ($canAccess) {
        Video::incrementViews($videoId, $userId); // de-duplicated per user (see Video)
    }

    jsonResponse(true, [
        'video' => $video,
        'can_access' => $canAccess,
        'watch_history' => $watchHistory ?: null,
        'stream_url' => $canAccess ? "/api/video-stream.php?id={$videoId}" : null,
        'share_url' => tgConf('bot_username') !== '' ? (new Telegram())->watchLink($videoId) : null
    ]);
}

function getCategories() {
    $categories = db()->fetchAll(
        "SELECT id, name, slug, description, icon FROM categories WHERE status = 'ACTIVE' ORDER BY display_order ASC"
    );
    jsonResponse(true, ['categories' => $categories]);
}

function getCategoryVideos() {
    [$page, $perPage] = pageParams();
    $categoryId = (int)($_GET['category_id'] ?? 0);
    if (!$categoryId) {
        jsonResponse(false, ['code' => 'MISSING_CATEGORY'], 'Category ID required');
    }
    $sql = "SELECT " . VIDEO_PUBLIC_COLS . " FROM videos v
            LEFT JOIN categories c ON v.category_id = c.id
            WHERE v.status = 'PUBLISHED' AND v.category_id = ?
            ORDER BY v.published_at DESC, v.id DESC";
    jsonResponse(true, paginate($sql, [$categoryId], $page, $perPage));
}

function searchVideos() {
    [$page, $perPage] = pageParams();
    $query = trim((string)($_GET['q'] ?? ''));
    if ($query === '' || mb_strlen($query) > 100) {
        jsonResponse(false, ['code' => 'INVALID_QUERY'], 'Search query must be 1-100 characters');
    }
    // Escape LIKE wildcards so "%" or "_" in the query are matched literally.
    $term = '%' . addcslashes($query, '%_\\') . '%';
    $sql = "SELECT " . VIDEO_PUBLIC_COLS . " FROM videos v
            LEFT JOIN categories c ON v.category_id = c.id
            WHERE v.status = 'PUBLISHED'
            AND (v.title LIKE ? OR v.description LIKE ? OR v.tags LIKE ?)
            ORDER BY v.views DESC, v.published_at DESC";
    jsonResponse(true, paginate($sql, [$term, $term, $term], $page, $perPage));
}

function getContinueWatching($userId) {
    $videos = db()->fetchAll(
        "SELECT " . VIDEO_PUBLIC_COLS . ", wh.last_position, wh.last_watched_at
         FROM watch_history wh
         JOIN videos v ON wh.video_id = v.id
         LEFT JOIN categories c ON v.category_id = c.id
         WHERE wh.user_id = ? AND wh.completed = 0 AND wh.last_position > 0 AND v.status = 'PUBLISHED'
         ORDER BY wh.last_watched_at DESC
         LIMIT 10",
        [$userId]
    );
    jsonResponse(true, ['videos' => $videos]);
}

function getTrendingVideos() {
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $videos = db()->fetchAll(
        "SELECT " . VIDEO_PUBLIC_COLS . " FROM videos v
         LEFT JOIN categories c ON v.category_id = c.id
         WHERE v.status = 'PUBLISHED'
         ORDER BY v.views DESC, v.published_at DESC
         LIMIT " . (int)$limit
    );
    jsonResponse(true, ['videos' => $videos]);
}

function getRelatedVideos() {
    $videoId = (int)($_GET['id'] ?? 0);
    $limit = 12;
    $db = db();
    // Same category first, then fill up with popular videos so the list is never empty.
    $videos = $db->fetchAll(
        "SELECT " . VIDEO_PUBLIC_COLS . " FROM videos v
         LEFT JOIN categories c ON v.category_id = c.id
         WHERE v.status = 'PUBLISHED' AND v.id != ?
           AND v.category_id = (SELECT category_id FROM videos WHERE id = ?)
         ORDER BY v.views DESC, v.published_at DESC LIMIT $limit",
        [$videoId, $videoId]
    );
    if (count($videos) < $limit) {
        $ids = array_merge([$videoId], array_map(fn($v) => (int)$v['id'], $videos));
        $more = $db->fetchAll(
            "SELECT " . VIDEO_PUBLIC_COLS . " FROM videos v
             LEFT JOIN categories c ON v.category_id = c.id
             WHERE v.status = 'PUBLISHED' AND v.id NOT IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
             ORDER BY v.views DESC, v.published_at DESC LIMIT " . ($limit - count($videos)),
            $ids
        );
        $videos = array_merge($videos, $more);
    }
    jsonResponse(true, ['videos' => $videos]);
}

function updateWatchProgress($userId) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, ['code' => 'METHOD_NOT_ALLOWED'], 'Method not allowed', 405);
    }
    $input = jsonInput();
    $videoId = (int)($input['video_id'] ?? 0);
    $position = (int)($input['position'] ?? 0);

    if (!$videoId || $position < 0) {
        jsonResponse(false, ['code' => 'INVALID_DATA'], 'Invalid data');
    }

    $video = Video::getById($videoId);
    if (!$video || $video['status'] !== 'PUBLISHED') {
        jsonResponse(false, ['code' => 'VIDEO_NOT_FOUND'], 'Video not found', 404);
    }
    if (!canAccessVideo($userId, $video)) {
        jsonResponse(false, ['code' => 'ACCESS_DENIED'], 'Access denied', 403);
    }
    if ($video['duration'] > 0) {
        $position = min($position, (int)$video['duration']);
    }

    $completed = $video['duration'] > 0 && ($position / $video['duration']) >= 0.9;

    // Watch time credited is computed server-side from wall-clock time between
    // updates (the client's claimed duration is not trusted, since referral
    // eligibility depends on it).
    $credited = Video::updateWatchProgress($videoId, $userId, $position, $completed);

    jsonResponse(true, ['completed' => $completed, 'credited_seconds' => $credited], 'Progress updated');
}

function getAd($userId) {
    $videoId = (int)($_GET['video_id'] ?? 0) ?: null;
    $adType = $_GET['type'] ?? 'INTERSTITIAL';
    // REWARDED ads are never served through this endpoint: rewards need a
    // provider with server-side verification (see Wallet::rewardedAdsAvailable()).
    if (!in_array($adType, ['BANNER', 'INTERSTITIAL', 'VIDEO'], true)) {
        jsonResponse(false, ['code' => 'INVALID_AD_TYPE'], 'Invalid ad type');
    }

    $ad = Ads::getAdToShow($userId, $videoId, $adType === 'BANNER' ? 'BANNER' : 'INTERSTITIAL');
    if (!$ad) {
        jsonResponse(true, ['ad' => null], 'No ad available');
    }
    jsonResponse(true, ['ad' => Ads::toClient($ad)]);
}

function adClick($userId) {
    $impressionId = (int)(jsonInput()['impression_id'] ?? 0);
    // Only count clicks on an impression that was actually served to this user.
    $imp = db()->fetchOne(
        "SELECT id, ad_id, clicked FROM ad_impressions WHERE id = ? AND user_id = ?",
        [$impressionId, $userId]
    );
    if ($imp && !$imp['clicked']) {
        Ads::trackClick((int)$imp['ad_id'], $userId, $impressionId);
    }
    jsonResponse(true, null, 'OK');
}

function adComplete($userId) {
    $impressionId = (int)(jsonInput()['impression_id'] ?? 0);
    $imp = db()->fetchOne(
        "SELECT id, ad_id, completed FROM ad_impressions WHERE id = ? AND user_id = ?",
        [$impressionId, $userId]
    );
    if ($imp && !$imp['completed']) {
        Ads::trackCompletion((int)$imp['ad_id'], $impressionId);
    }
    jsonResponse(true, null, 'OK');
}
