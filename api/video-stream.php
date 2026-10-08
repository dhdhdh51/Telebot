<?php
/**
 * Protected video streaming endpoint with HTTP Range support.
 *
 * GET /api/video-stream.php?id=123
 *  1. authenticated session  2. video exists & published
 *  3. FREE, or PREMIUM with active subscription  4. stream (206 for ranges)
 *
 * Storage note: for large catalogues, replace the readfile loop with a redirect to
 * a short-lived signed URL (S3/R2/CDN) — the access checks above stay the same.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/video.php';
require_once __DIR__ . '/../includes/vidvault.php';

$userId = !empty($_SESSION['authenticated']) ? (int)($_SESSION['user_id'] ?? 0) : 0;
// CRITICAL: release the session lock before streaming. PHP sessions are locked
// per request; holding it for the whole video would block every other API call
// (progress saves, navigation) from this user until the download finishes.
session_write_close();

function streamError($code, $msg) {
    http_response_code($code);
    header('Content-Type: text/plain');
    exit($msg);
}

if (!$userId) {
    streamError(401, 'Authentication required');
}
$user = db()->fetchOne("SELECT status FROM users WHERE id = ?", [$userId]);
if (!$user || $user['status'] !== 'ACTIVE') {
    streamError(403, 'Account inactive');
}

$videoId = (int)($_GET['id'] ?? 0);
$video = $videoId ? Video::getById($videoId) : null;
if (!$video || $video['status'] !== 'PUBLISHED') {
    streamError(404, 'Video not found');
}
if (!canAccessVideo($userId, $video)) {
    streamError(403, 'Premium subscription required');
}

// VidVault storage: access was checked above; send the player to a short-lived
// signed URL (VidVault serves Range requests itself). The video stays private there.
if (VidVault::isRemote($video['video_path'])) {
    $remoteId = VidVault::remoteId($video['video_path']);
    $pb = $remoteId ? VidVault::playback($remoteId) : ['error' => ['message' => 'bad id']];
    if (isset($pb['error']) || empty($pb['sources'])) {
        error_log("VidVault playback failed for video #$videoId: " . ($pb['error']['message'] ?? ''));
        streamError(502, 'Video storage is temporarily unavailable');
    }
    $src = VidVault::pickSource($pb['sources'], preg_replace('/[^0-9A-Za-z() ]/', '', (string)($_GET['quality'] ?? '')));
    header('Cache-Control: private, no-store');
    header('Referrer-Policy: no-referrer');
    header('Location: ' . $src['url'], true, 302);
    exit;
}

// basename() guards against a tampered DB value escaping the storage directory.
$videoPath = VIDEO_DIR . '/' . basename($video['video_path']);
if (!is_file($videoPath)) {
    error_log("Video file missing for video #$videoId");
    streamError(404, 'Video file not found');
}

$fileSize = filesize($videoPath);
$ext = strtolower(pathinfo($videoPath, PATHINFO_EXTENSION));
$mimeTypes = ['mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'mkv' => 'video/x-matroska', 'avi' => 'video/x-msvideo'];
$mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';

$start = 0;
$end = $fileSize - 1;

if (isset($_SERVER['HTTP_RANGE'])) {
    // Supports "bytes=START-", "bytes=START-END" and suffix "bytes=-N". Multi-range is not supported.
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m) || ($m[1] === '' && $m[2] === '')) {
        header("Content-Range: bytes */$fileSize");
        streamError(416, 'Invalid range');
    }
    if ($m[1] === '') {
        $start = max(0, $fileSize - (int)$m[2]);
    } else {
        $start = (int)$m[1];
        if ($m[2] !== '') {
            $end = min((int)$m[2], $fileSize - 1);
        }
    }
    if ($start > $end || $start >= $fileSize) {
        header("Content-Range: bytes */$fileSize");
        streamError(416, 'Range not satisfiable');
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$fileSize");
}

$length = $end - $start + 1;

header("Content-Type: $mimeType");
header("Content-Length: $length");
header('Accept-Ranges: bytes');
header('Content-Disposition: inline');
header('Cache-Control: private, no-store'); // authorised content must not be cached by shared proxies
header('X-Content-Type-Options: nosniff');
header('X-Accel-Buffering: no'); // Nginx/aaPanel: stream directly, don't buffer the whole file

while (ob_get_level()) {
    ob_end_clean();
}
set_time_limit(0);

$fp = fopen($videoPath, 'rb');
fseek($fp, $start);
$remaining = $length;
while ($remaining > 0 && !feof($fp) && connection_status() === CONNECTION_NORMAL) {
    $chunk = fread($fp, min(65536, $remaining));
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    flush();
    $remaining -= strlen($chunk);
}
fclose($fp);
exit;
