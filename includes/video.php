<?php
/**
 * Video Management Class
 * Handle video operations, upload, and streaming
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/functions.php';

class Video {
    
    /**
     * Upload video file
     */
    public static function uploadVideo($file) {
        // Validate upload
        $allowedTypes = [
            'video/mp4',
            'video/x-matroska',
            'video/x-msvideo',
            'video/avi',
            'video/quicktime',
            'video/webm'
        ];
        
        $validation = Security::validateFileUpload($file, $allowedTypes, MAX_UPLOAD_SIZE);
        
        if (!$validation['success']) {
            return $validation;
        }
        
        // Generate secure filename
        $filename = Security::generateSecureFilename($file['name']);
        $destination = VIDEO_DIR . '/' . $filename;
        
        // Ensure directory exists
        if (!is_dir(VIDEO_DIR)) {
            mkdir(VIDEO_DIR, 0755, true);
        }
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return ['success' => false, 'error' => 'Failed to move uploaded file'];
        }
        
        // Get video duration and details
        $videoInfo = self::getVideoInfo($destination);
        
        return [
            'success' => true,
            'filename' => $filename,
            'path' => $destination,
            'size' => filesize($destination),
            'duration' => $videoInfo['duration'] ?? 0,
            'dimensions' => $videoInfo['dimensions'] ?? null
        ];
    }
    
    /**
     * Upload thumbnail
     */
    public static function uploadThumbnail($file) {
        // Validate upload
        $allowedTypes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp'
        ];
        
        $validation = Security::validateFileUpload($file, $allowedTypes, MAX_THUMBNAIL_SIZE);
        
        if (!$validation['success']) {
            return $validation;
        }
        
        // Generate secure filename
        $filename = Security::generateSecureFilename($file['name']);
        $destination = THUMBNAIL_DIR . '/' . $filename;
        
        // Ensure directory exists
        if (!is_dir(THUMBNAIL_DIR)) {
            mkdir(THUMBNAIL_DIR, 0755, true);
        }
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return ['success' => false, 'error' => 'Failed to move uploaded file'];
        }
        
        // Resize/optimize thumbnail if needed
        self::optimizeThumbnail($destination);
        
        return [
            'success' => true,
            'filename' => $filename,
            'path' => $destination,
            'size' => filesize($destination)
        ];
    }
    
    /**
     * Get video information using ffprobe (if available)
     */
    private static function getVideoInfo($videoPath) {
        // Check if ffprobe is available
        if (!function_exists('shell_exec')) {
            return ['duration' => 0];
        }
        
        $ffprobe = shell_exec("which ffprobe 2>/dev/null");
        if (!$ffprobe) {
            return ['duration' => 0];
        }
        
        $ffprobe = trim($ffprobe);
        $command = escapeshellcmd($ffprobe) . " -v quiet -print_format json -show_format -show_streams " . escapeshellarg($videoPath);
        $output = shell_exec($command);
        
        if (!$output) {
            return ['duration' => 0];
        }
        
        $info = json_decode($output, true);
        
        $duration = 0;
        if (isset($info['format']['duration'])) {
            $duration = (int)$info['format']['duration'];
        }
        
        $dimensions = null;
        if (isset($info['streams'])) {
            foreach ($info['streams'] as $stream) {
                if ($stream['codec_type'] === 'video') {
                    $dimensions = [
                        'width' => $stream['width'] ?? 0,
                        'height' => $stream['height'] ?? 0
                    ];
                    break;
                }
            }
        }
        
        return [
            'duration' => $duration,
            'dimensions' => $dimensions
        ];
    }
    
    /**
     * Optimize thumbnail image
     */
    private static function optimizeThumbnail($imagePath, $maxWidth = 1280, $maxHeight = 720) {
        $imageInfo = getimagesize($imagePath);
        if (!$imageInfo) {
            return;
        }
        
        list($width, $height, $type) = $imageInfo;
        
        // Skip if already smaller
        if ($width <= $maxWidth && $height <= $maxHeight) {
            return;
        }
        
        // Calculate new dimensions
        $ratio = min($maxWidth / $width, $maxHeight / $height);
        $newWidth = (int)($width * $ratio);
        $newHeight = (int)($height * $ratio);
        
        // Create image resource based on type
        switch ($type) {
            case IMAGETYPE_JPEG:
                $source = imagecreatefromjpeg($imagePath);
                break;
            case IMAGETYPE_PNG:
                $source = imagecreatefrompng($imagePath);
                break;
            case IMAGETYPE_GIF:
                $source = imagecreatefromgif($imagePath);
                break;
            case IMAGETYPE_WEBP:
                $source = imagecreatefromwebp($imagePath);
                break;
            default:
                return;
        }
        
        if (!$source) {
            return;
        }
        
        // Create new image
        $newImage = imagecreatetruecolor($newWidth, $newHeight);
        
        // Preserve transparency for PNG and GIF
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF) {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
            imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
        }
        
        // Resize
        imagecopyresampled($newImage, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        
        // Save optimized image
        switch ($type) {
            case IMAGETYPE_JPEG:
                imagejpeg($newImage, $imagePath, 85);
                break;
            case IMAGETYPE_PNG:
                imagepng($newImage, $imagePath, 8);
                break;
            case IMAGETYPE_GIF:
                imagegif($newImage, $imagePath);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($newImage, $imagePath, 85);
                break;
        }
        
        imagedestroy($source);
        imagedestroy($newImage);
    }
    
    /**
     * Create video record
     */
    public static function create($data) {
        $db = db();
        
        // Generate slug
        $slug = Security::createSlug($data['title']);
        
        // Ensure unique slug
        $counter = 1;
        $originalSlug = $slug;
        while (self::slugExists($slug)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }
        
        // Insert video
        $db->execute(
            "INSERT INTO videos (title, slug, description, category_id, thumbnail, video_path, hls_path, duration, file_size, access_type, status, tags, published_at) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['title'],
                $slug,
                $data['description'] ?? null,
                $data['category_id'],
                $data['thumbnail'],
                $data['video_path'],
                $data['hls_path'] ?? null,
                $data['duration'] ?? 0,
                $data['file_size'] ?? 0,
                $data['access_type'] ?? 'FREE',
                $data['status'] ?? 'DRAFT',
                $data['tags'] ?? null,
                ($data['status'] ?? 'DRAFT') === 'PUBLISHED' ? date('Y-m-d H:i:s') : null
            ]
        );
        
        $videoId = $db->lastInsertId();
        
        // Log audit
        logAudit('VIDEO_CREATED', "Created video: {$data['title']}", 'video', $videoId, null, $data);
        
        return $videoId;
    }
    
    /**
     * Update video
     */
    public static function update($id, $data) {
        $db = db();
        
        // Get old video data
        $oldVideo = self::getById($id);
        if (!$oldVideo) {
            return false;
        }
        
        // Build update query
        $updates = [];
        $params = [];
        
        $allowedFields = ['title', 'description', 'category_id', 'thumbnail', 'access_type', 'status', 'tags'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        
        // Update slug if title changed
        if (isset($data['title']) && $data['title'] !== $oldVideo['title']) {
            $slug = Security::createSlug($data['title']);
            
            // Ensure unique slug
            $counter = 1;
            $originalSlug = $slug;
            while (self::slugExists($slug, $id)) {
                $slug = $originalSlug . '-' . $counter;
                $counter++;
            }
            
            $updates[] = "slug = ?";
            $params[] = $slug;
        }
        
        // Update published_at if status changes to PUBLISHED
        if (isset($data['status']) && $data['status'] === 'PUBLISHED' && $oldVideo['status'] !== 'PUBLISHED') {
            $updates[] = "published_at = NOW()";
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $params[] = $id;
        $sql = "UPDATE videos SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?";
        
        $result = $db->execute($sql, $params);
        
        // Log audit
        logAudit('VIDEO_UPDATED', "Updated video: {$oldVideo['title']}", 'video', $id, $oldVideo, $data);
        
        return $result > 0;
    }
    
    /**
     * Delete video
     */
    public static function delete($id) {
        $db = db();
        
        $video = self::getById($id);
        if (!$video) {
            return false;
        }
        
        // Delete files
        $videoPath = VIDEO_DIR . '/' . $video['video_path'];
        $thumbnailPath = THUMBNAIL_DIR . '/' . $video['thumbnail'];
        
        if (file_exists($videoPath)) {
            unlink($videoPath);
        }
        
        if (file_exists($thumbnailPath)) {
            unlink($thumbnailPath);
        }
        
        // Delete from database
        $result = $db->execute("DELETE FROM videos WHERE id = ?", [$id]);
        
        // Log audit
        logAudit('VIDEO_DELETED', "Deleted video: {$video['title']}", 'video', $id, $video, null);
        
        return $result > 0;
    }
    
    /**
     * Get video by ID
     */
    public static function getById($id) {
        $db = db();
        
        return $db->fetchOne(
            "SELECT v.*, c.name as category_name, c.slug as category_slug 
             FROM videos v 
             LEFT JOIN categories c ON v.category_id = c.id 
             WHERE v.id = ?",
            [$id]
        );
    }
    
    /**
     * Get video by slug
     */
    public static function getBySlug($slug) {
        $db = db();
        
        return $db->fetchOne(
            "SELECT v.*, c.name as category_name, c.slug as category_slug 
             FROM videos v 
             LEFT JOIN categories c ON v.category_id = c.id 
             WHERE v.slug = ?",
            [$slug]
        );
    }
    
    /**
     * Check if slug exists
     */
    private static function slugExists($slug, $excludeId = null) {
        $db = db();
        
        $sql = "SELECT id FROM videos WHERE slug = ?";
        $params = [$slug];
        
        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        
        return $db->fetchOne($sql, $params) !== false;
    }
    
    /**
     * Increment view count
     */
    public static function incrementViews($videoId, $userId) {
        $db = db();
        
        // Re-opening the same video within 30 minutes is the same view
        // (otherwise refreshing the page inflates "views" without limit).
        $recent = $db->fetchOne(
            "SELECT id FROM video_views WHERE video_id = ? AND user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)",
            [$videoId, $userId]
        );
        if ($recent) {
            return false;
        }
        
        // Unique = first view by this user within 24 hours
        $lastDay = $db->fetchOne(
            "SELECT id FROM video_views WHERE video_id = ? AND user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            [$videoId, $userId]
        );
        $isUnique = !$lastDay;
        
        $db->execute(
            "UPDATE videos SET views = views + 1" . ($isUnique ? ", unique_views = unique_views + 1" : "") . " WHERE id = ?",
            [$videoId]
        );
        if ($isUnique) {
            $db->execute("UPDATE users SET total_videos_watched = total_videos_watched + 1 WHERE id = ?", [$userId]);
        }
        
        $db->execute(
            "INSERT INTO video_views (video_id, user_id, ip_address, user_agent) VALUES (?, ?, ?, ?)",
            [$videoId, $userId, Security::getClientIP(), Security::getUserAgent()]
        );
        return true;
    }
    
    /**
     * Update watch progress.
     *
     * Watch time is credited from the server clock: the seconds elapsed since this
     * user's previous update for this video, capped at 30s per update (the player
     * reports every 10s). A client cannot claim more watch time than real time,
     * which matters because referral rewards depend on total_watch_time.
     *
     * @return int seconds credited
     */
    public static function updateWatchProgress($videoId, $userId, $position, $completed = false) {
        $db = db();
        
        $history = $db->fetchOne(
            "SELECT TIMESTAMPDIFF(SECOND, last_watched_at, NOW()) AS elapsed FROM watch_history WHERE user_id = ? AND video_id = ?",
            [$userId, $videoId]
        );
        
        $credited = 0;
        if ($history) {
            $credited = max(0, min((int)$history['elapsed'], 30));
            $db->execute(
                "UPDATE watch_history SET last_position = ?, watch_duration = watch_duration + ?, completed = ?, last_watched_at = NOW() WHERE user_id = ? AND video_id = ?",
                [$position, $credited, $completed ? 1 : 0, $userId, $videoId]
            );
        } else {
            $db->execute(
                "INSERT INTO watch_history (user_id, video_id, last_position, watch_duration, completed, last_watched_at) VALUES (?, ?, ?, 0, ?, NOW())",
                [$userId, $videoId, $position, $completed ? 1 : 0]
            );
        }
        
        if ($credited > 0) {
            $db->execute("UPDATE users SET total_watch_time = total_watch_time + ? WHERE id = ?", [$credited, $userId]);
            $db->execute("UPDATE videos SET total_watch_time = total_watch_time + ? WHERE id = ?", [$credited, $videoId]);
        }
        
        // Keep the latest view row in sync so analytics (watch time, completion) aren't always 0.
        $db->execute(
            "UPDATE video_views SET watch_duration = watch_duration + ?, completed = GREATEST(completed, ?)
             WHERE video_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1",
            [$credited, $completed ? 1 : 0, $videoId, $userId]
        );
        
        return $credited;
    }
}
