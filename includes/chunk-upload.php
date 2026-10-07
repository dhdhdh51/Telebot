<?php
/**
 * Chunked (resumable-per-chunk) video upload for shared hosting.
 *
 * Shared hosts cap upload_max_filesize / post_max_size (often 50–128 MB) and proxies
 * like Cloudflare cap request bodies at 100 MB. The admin browser therefore sends the
 * file in small chunks; each chunk is a normal small upload, the server stores it as
 * a part file and joins the parts when the last one arrived.
 *
 *   init(name, size)          → upload id + chunk size     (extension + size checked)
 *   chunk(id, index, file)    → stores part <index>         (re-sending a part is safe)
 *   finish(id)                → joins parts, checks size + real MIME, moves to uploads/videos
 *   claim(id)                 → used once by video-add.php to attach the finished file
 *
 * Uploads are bound to the admin's session, so nobody else can use or finish them.
 */

require_once __DIR__ . '/functions.php';

class ChunkUpload {
    const EXTENSIONS = ['mp4', 'webm', 'mov', 'mkv', 'avi'];
    const MIME = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-matroska', 'video/x-msvideo', 'video/avi', 'application/octet-stream'];

    public static function iniBytes($val) {
        $val = trim((string)$val);
        $num = (float)$val;
        switch (strtolower(substr($val, -1))) {
            case 'g': return (int)($num * 1024 ** 3);
            case 'm': return (int)($num * 1024 ** 2);
            case 'k': return (int)($num * 1024);
        }
        return (int)$num;
    }

    /** Max video size from Admin → Settings → Video (bytes). */
    public static function maxVideoSize() {
        $v = (int)getSetting('video', 'max_upload_size', defined('MAX_UPLOAD_SIZE') ? MAX_UPLOAD_SIZE : 524288000);
        return max(10 * 1024 * 1024, $v);
    }

    /** Largest single request PHP accepts on this server. */
    public static function phpRequestLimit() {
        $limits = array_filter([self::iniBytes(ini_get('upload_max_filesize')), self::iniBytes(ini_get('post_max_size'))]);
        return $limits ? min($limits) : 2 * 1024 * 1024;
    }

    /** 8 MB chunks, or smaller if the host allows less per request. */
    public static function chunkSize() {
        return (int)max(256 * 1024, min(8 * 1024 * 1024, self::phpRequestLimit() - 256 * 1024));
    }

    private static function tmpRoot() {
        $dir = UPLOAD_DIR . '/tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        return $dir;
    }

    private static function dir($id) {
        return self::tmpRoot() . '/' . $id;
    }

    private static function &rec($id) {
        if (!isset($_SESSION['chunk_uploads']) || !is_array($_SESSION['chunk_uploads'])) {
            $_SESSION['chunk_uploads'] = [];
        }
        $null = null;
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id) || !isset($_SESSION['chunk_uploads'][$id])) {
            return $null;
        }
        return $_SESSION['chunk_uploads'][$id];
    }

    public static function init($name, $size) {
        $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
        $size = (int)$size;
        if (!in_array($ext, self::EXTENSIONS, true)) {
            return ['success' => false, 'error' => 'Only MP4, WEBM, MOV, MKV or AVI videos are allowed (MP4 recommended).'];
        }
        if ($size <= 0) {
            return ['success' => false, 'error' => 'The file is empty.'];
        }
        if ($size > self::maxVideoSize()) {
            return ['success' => false, 'error' => 'Video is ' . formatBytes($size) . ', the maximum is ' . formatBytes(self::maxVideoSize()) . ' (Admin → Settings → Video).'];
        }
        $free = @disk_free_space(UPLOAD_DIR);
        if ($free !== false && $free < $size * 2 + 50 * 1024 * 1024) {
            return ['success' => false, 'error' => 'Not enough disk space on the server (' . formatBytes((int)$free) . ' free). Delete old videos or upgrade hosting.'];
        }
        $chunk = self::chunkSize();
        $id = bin2hex(random_bytes(16));
        if (!@mkdir(self::dir($id), 0755, true)) {
            return ['success' => false, 'error' => 'uploads/ folder is not writable (chmod 755).'];
        }
        // Keep at most 5 open uploads per session.
        if (count($_SESSION['chunk_uploads'] ?? []) >= 5) {
            $old = array_key_first($_SESSION['chunk_uploads']);
            self::abort($old);
        }
        $_SESSION['chunk_uploads'][$id] = [
            'ext' => $ext, 'size' => $size, 'chunk' => $chunk, 'total' => (int)ceil($size / $chunk),
            'done' => false, 'file' => null, 'created' => time(),
        ];
        return ['success' => true, 'upload_id' => $id, 'chunk_size' => $chunk, 'total_chunks' => (int)ceil($size / $chunk)];
    }

    public static function chunk($id, $index, $file) {
        $r = &self::rec($id);
        if (!$r || $r['done']) {
            return ['success' => false, 'error' => 'Upload not found or already finished. Start again.'];
        }
        $index = (int)$index;
        if ($index < 0 || $index >= $r['total']) {
            return ['success' => false, 'error' => 'Bad chunk number'];
        }
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'error' => 'Chunk upload failed (code ' . ($file['error'] ?? '?') . ')', 'retry' => true];
        }
        $expected = $index === $r['total'] - 1 ? $r['size'] - $r['chunk'] * ($r['total'] - 1) : $r['chunk'];
        if ((int)$file['size'] !== $expected) {
            return ['success' => false, 'error' => 'Chunk size mismatch', 'retry' => true];
        }
        if (!move_uploaded_file($file['tmp_name'], self::dir($id) . '/' . $index . '.part')) {
            return ['success' => false, 'error' => 'Could not save chunk (disk full or not writable)'];
        }
        return ['success' => true];
    }

    public static function finish($id) {
        $r = &self::rec($id);
        if (!$r) {
            return ['success' => false, 'error' => 'Upload not found. Start again.'];
        }
        if ($r['done']) {
            return ['success' => true, 'size' => $r['size']];
        }
        $dir = self::dir($id);
        for ($i = 0; $i < $r['total']; $i++) {
            if (!is_file("$dir/$i.part")) {
                return ['success' => false, 'error' => "Part $i is missing", 'missing' => $i];
            }
        }
        @set_time_limit(0);
        $filename = Security::generateSecureFilename('video.' . $r['ext']);
        if (!is_dir(VIDEO_DIR)) {
            @mkdir(VIDEO_DIR, 0755, true);
        }
        $dest = VIDEO_DIR . '/' . $filename;
        $out = @fopen($dest, 'wb');
        if (!$out) {
            return ['success' => false, 'error' => 'uploads/videos is not writable'];
        }
        for ($i = 0; $i < $r['total']; $i++) {
            $in = fopen("$dir/$i.part", 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            @unlink("$dir/$i.part");
        }
        fclose($out);
        @rmdir($dir);

        $size = filesize($dest);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($dest);
        if ($size !== $r['size'] || !in_array($mime, self::MIME, true) || ($mime === 'application/octet-stream' && !self::looksLikeVideo($dest))) {
            @unlink($dest);
            unset($_SESSION['chunk_uploads'][$id]);
            return ['success' => false, 'error' => $size !== $r['size'] ? 'Upload incomplete, please try again.' : 'This file is not a valid video (' . $mime . ').'];
        }
        $r['done'] = true;
        $r['file'] = $filename;
        return ['success' => true, 'size' => $size];
    }

    /** MKV/AVI are sometimes reported as octet-stream; check magic bytes. */
    private static function looksLikeVideo($path) {
        $h = (string)file_get_contents($path, false, null, 0, 16);
        return strpos($h, "\x1A\x45\xDF\xA3") === 0          // Matroska / WebM
            || (strpos($h, 'RIFF') === 0 && substr($h, 8, 4) === 'AVI ')
            || substr($h, 4, 4) === 'ftyp';                     // MP4 / MOV
    }

    /** Hand the finished file to the caller exactly once. */
    public static function claim($id) {
        $r = &self::rec($id);
        if (!$r || !$r['done'] || !$r['file'] || !is_file(VIDEO_DIR . '/' . $r['file'])) {
            return null;
        }
        $out = ['filename' => $r['file'], 'path' => VIDEO_DIR . '/' . $r['file'], 'size' => $r['size']];
        unset($_SESSION['chunk_uploads'][$id]);
        return $out;
    }

    public static function abort($id) {
        $r = &self::rec($id);
        if (!$r) {
            return;
        }
        foreach (glob(self::dir($id) . '/*.part') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir(self::dir($id));
        if ($r['done'] && $r['file']) {
            @unlink(VIDEO_DIR . '/' . $r['file']);
        }
        unset($_SESSION['chunk_uploads'][$id]);
    }

    /** Cron: remove part folders of uploads abandoned for more than a day. */
    public static function cleanup() {
        $n = 0;
        foreach (glob(self::tmpRoot() . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            if (filemtime($d) < time() - 86400) {
                foreach (glob($d . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($d) && $n++;
            }
        }
        return $n;
    }
}
