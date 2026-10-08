<?php
/**
 * VidVault (https://vault.bharatseo.site) as video storage.
 *
 * A BharatPlay video stored in VidVault has video_path = "vidvault:<uuid>".
 * The API key stays on this server (Settings → Storage, encrypted). Playback:
 * api/video-stream.php checks login + premium as usual, then asks VidVault for
 * short-lived signed URLs (GET /api/videos/:id/playback) and redirects the player
 * there – so VidVault videos stay PRIVATE (no public share link needed).
 *
 * Response shapes follow VidVault's server code (routes/videos.ts, services/videos.ts):
 *   GET /api/videos            → { items: VideoDTO[], total, page, pageSize }
 *   GET /api/videos/:id        → { video: VideoDTO }
 *   GET /api/videos/:id/playback → { sources: [{label,height,mimeType,url}], poster, downloadUrl }
 *   GET /api/me/stats          → storage stats
 *   errors                     → { error: { code, message } }
 */

require_once __DIR__ . '/functions.php';

class VidVault {
    const PREFIX = 'vidvault:';

    public static function baseUrl() {
        $u = rtrim(trim((string)getSetting('storage', 'vidvault_url', '')), '/');
        return $u !== '' ? $u : (defined('VIDVAULT_BASE') ? VIDVAULT_BASE : 'https://vault.bharatseo.site');
    }

    public static function apiKey() {
        return trim((string)getSetting('storage', 'vidvault_key', ''));
    }

    public static function configured() {
        return (bool)preg_match('/^vv_[A-Za-z0-9_-]{20,100}$/', self::apiKey());
    }

    public static function isRemote($videoPath) {
        return is_string($videoPath) && strpos($videoPath, self::PREFIX) === 0;
    }

    public static function remoteId($videoPath) {
        $id = substr((string)$videoPath, strlen(self::PREFIX));
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) ? strtolower($id) : null;
    }

    /** @return array decoded JSON, or ['error' => ['code','message'], '_status' => int] */
    public static function request($path, array $query = []) {
        if (!self::configured()) {
            return ['error' => ['code' => 'NOT_CONFIGURED', 'message' => 'VidVault API key is not set (Admin → Settings → Storage).'], '_status' => 0];
        }
        $url = self::baseUrl() . $path . ($query ? '?' . http_build_query($query) : '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::apiKey(), 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
        ]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $json = is_string($res) ? json_decode($res, true) : null;
        if ($err || !is_array($json)) {
            error_log("VidVault $path failed: " . ($err ?: "HTTP $code, non-JSON"));
            return ['error' => ['code' => 'UNREACHABLE', 'message' => 'Could not reach VidVault: ' . ($err ?: "HTTP $code")], '_status' => $code];
        }
        if ($code >= 400 && !isset($json['error'])) {
            $json['error'] = ['code' => 'HTTP_' . $code, 'message' => "VidVault returned HTTP $code"];
        }
        if (isset($json['error'])) {
            if ($code === 401) {
                $json['error']['message'] = 'VidVault rejected the API key (create a new one in VidVault → Settings → API keys).';
            }
            $json['_status'] = $code;
            error_log("VidVault $path error: " . ($json['error']['message'] ?? ''));
        }
        return $json;
    }

    /** Absolute URL (local storage driver returns paths like /api/storage/local/object?t=…). */
    public static function absolute($url) {
        if (!is_string($url) || $url === '') return null;
        return strpos($url, '/') === 0 ? self::baseUrl() . $url : $url;
    }

    public static function listVideos($q = '', $page = 1) {
        $query = ['sort' => 'newest', 'page' => max(1, (int)$page), 'pageSize' => 24];
        if ($q !== '') $query['q'] = mb_substr($q, 0, 200);
        return self::request('/api/videos', $query);
    }

    public static function getVideo($id) {
        return self::request('/api/videos/' . rawurlencode($id));
    }

    public static function stats() {
        return self::request('/api/me/stats');
    }

    /**
     * Signed playback sources, cached for 20 min (VidVault signs them for ~6 h),
     * so a busy video doesn't hit the API on every play / seek.
     */
    public static function playback($id) {
        $cacheFile = sys_get_temp_dir() . '/bharatplay_vv_' . hash('sha256', $id . self::apiKey()) . '.json';
        if (is_file($cacheFile) && filemtime($cacheFile) > time() - 1200) {
            $c = json_decode((string)@file_get_contents($cacheFile), true);
            if (!empty($c['sources'])) return $c;
        }
        $r = self::request('/api/videos/' . rawurlencode($id) . '/playback');
        if (isset($r['error']) || empty($r['sources'])) {
            return $r + ['error' => ['code' => 'NO_SOURCES', 'message' => 'VidVault has no playable source']];
        }
        foreach ($r['sources'] as &$s) {
            $s['url'] = self::absolute($s['url'] ?? '');
        }
        $r['poster'] = self::absolute($r['poster'] ?? null);
        unset($r['downloadUrl']);
        @file_put_contents($cacheFile, json_encode($r), LOCK_EX);
        return $r;
    }

    /** Pick a source: requested quality label/height, else the original (first). */
    public static function pickSource(array $sources, $quality = '') {
        if ($quality !== '') {
            foreach ($sources as $s) {
                if (strcasecmp((string)($s['label'] ?? ''), $quality) === 0 || (string)($s['height'] ?? '') === rtrim($quality, 'p')) {
                    return $s;
                }
            }
        }
        return $sources[0];
    }

    /** Download VidVault's thumbnail into uploads/thumbnails. Returns filename or null. */
    public static function importThumbnail($id) {
        $p = self::playback($id);
        $url = $p['poster'] ?? null;
        if (!$url) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 20, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP]);
        $img = curl_exec($ch);
        curl_close($ch);
        if (!is_string($img) || strlen($img) < 100 || strlen($img) > 5 * 1024 * 1024) return null;
        $info = @getimagesizefromstring($img);
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
        if (!$ext) return null;
        if (!is_dir(THUMBNAIL_DIR)) @mkdir(THUMBNAIL_DIR, 0755, true);
        $name = Security::generateSecureFilename('thumb.' . $ext);
        return @file_put_contents(THUMBNAIL_DIR . '/' . $name, $img) ? $name : null;
    }
}
