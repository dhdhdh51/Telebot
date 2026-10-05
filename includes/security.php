<?php
/**
 * Security Functions
 * Input validation, XSS prevention, and security utilities
 */

class Security {
    
    /**
     * Sanitize input string
     */
    public static function sanitize($input) {
        if (is_array($input)) {
            return array_map([self::class, 'sanitize'], $input);
        }
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Validate email
     */
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Validate integer
     */
    public static function validateInt($value, $min = null, $max = null) {
        if (!is_numeric($value)) {
            return false;
        }
        
        $value = (int)$value;
        
        if ($min !== null && $value < $min) {
            return false;
        }
        
        if ($max !== null && $value > $max) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate decimal/float
     */
    public static function validateDecimal($value, $min = null, $max = null) {
        if (!is_numeric($value)) {
            return false;
        }
        
        $value = (float)$value;
        
        if ($min !== null && $value < $min) {
            return false;
        }
        
        if ($max !== null && $value > $max) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Generate secure random token
     */
    public static function generateToken($length = 32) {
        return bin2hex(random_bytes($length));
    }
    
    /**
     * Generate unique reference ID
     */
    public static function generateReferenceId($prefix = '') {
        return $prefix . uniqid() . bin2hex(random_bytes(8));
    }
    
    /**
     * Hash password
     */
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }
    
    /**
     * Verify password
     */
    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    /**
     * Get client IP address
     */
    public static function getClientIP() {
        // Only REMOTE_ADDR is trustworthy. Client-IP / X-Forwarded-For headers are
        // attacker-controlled and would let anyone bypass IP-based rate limiting.
        // If you sit behind Cloudflare, use mod_remoteip so REMOTE_ADDR is correct.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }
    
    /**
     * Get user agent
     */
    public static function getUserAgent() {
        return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    }
    
    /**
     * Validate file upload
     */
    public static function validateFileUpload($file, $allowedTypes, $maxSize) {
        if (!isset($file['error']) || is_array($file['error'])) {
            return ['success' => false, 'error' => 'Invalid file upload'];
        }
        
        // Check for upload errors
        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return ['success' => false, 'error' => 'File size exceeds limit'];
            case UPLOAD_ERR_NO_FILE:
                return ['success' => false, 'error' => 'No file uploaded'];
            default:
                return ['success' => false, 'error' => 'Upload error occurred'];
        }
        
        // Check file size
        if ($file['size'] > $maxSize) {
            return ['success' => false, 'error' => 'File size exceeds maximum allowed size'];
        }
        
        // Verify MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        
        if (!in_array($mimeType, $allowedTypes)) {
            return ['success' => false, 'error' => 'Invalid file type'];
        }
        
        // Verify file extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = [];
        
        foreach ($allowedTypes as $type) {
            if (strpos($type, 'video/') === 0) {
                $allowedExtensions = array_merge($allowedExtensions, ['mp4', 'mkv', 'avi', 'mov', 'webm']);
            } elseif (strpos($type, 'image/') === 0) {
                $allowedExtensions = array_merge($allowedExtensions, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
            }
        }
        
        if (!in_array($extension, $allowedExtensions)) {
            return ['success' => false, 'error' => 'Invalid file extension'];
        }
        
        return ['success' => true];
    }
    
    /**
     * Generate secure filename
     */
    public static function generateSecureFilename($originalName) {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        return uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    }
    
    /**
     * Rate limiting check
     */
    public static function checkRateLimit($key, $maxAttempts, $timeWindow) {
        $cacheFile = sys_get_temp_dir() . '/bharatplay_rate_' . md5($key) . '.tmp';
        
        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true);
            
            if ($data && isset($data['count']) && isset($data['timestamp'])) {
                if (time() - $data['timestamp'] < $timeWindow) {
                    if ($data['count'] >= $maxAttempts) {
                        return ['allowed' => false, 'retry_after' => $timeWindow - (time() - $data['timestamp'])];
                    }
                    $data['count']++;
                } else {
                    $data = ['count' => 1, 'timestamp' => time()];
                }
            } else {
                $data = ['count' => 1, 'timestamp' => time()];
            }
        } else {
            $data = ['count' => 1, 'timestamp' => time()];
        }
        
        file_put_contents($cacheFile, json_encode($data));
        return ['allowed' => true];
    }
    
    /**
     * Authenticated encryption for sensitive data (withdrawal account details).
     * AES-256-GCM with a random 12-byte IV per message; output = base64(iv|tag|ciphertext).
     * (A fixed IV would make identical UPI IDs produce identical ciphertexts.)
     */
    public static function encrypt($data) {
        $key = hash('sha256', SECRET_KEY, true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed');
        }
        return base64_encode($iv . $tag . $cipher);
    }
    
    /**
     * Decrypt data produced by encrypt(). Returns false if tampered or wrong key.
     */
    public static function decrypt($data) {
        $raw = base64_decode($data, true);
        if ($raw === false || strlen($raw) < 29) {
            return false;
        }
        $key = hash('sha256', SECRET_KEY, true);
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        return openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    }

    /**
     * Money helpers. All amounts are handled as integer paise internally so no
     * floating-point arithmetic ever touches balances. DB columns are DECIMAL.
     */
    public static function toPaise($amount): int {
        $s = trim((string)$amount);
        if (!preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', $s, $m)) {
            throw new InvalidArgumentException('Invalid money amount');
        }
        $paise = (int)$m[2] * 100 + (int)str_pad($m[3] ?? '0', 2, '0');
        return $m[1] === '-' ? -$paise : $paise;
    }

    public static function fromPaise(int $paise): string {
        $sign = $paise < 0 ? '-' : '';
        $abs = abs($paise);
        return $sign . intdiv($abs, 100) . '.' . str_pad((string)($abs % 100), 2, '0', STR_PAD_LEFT);
    }
    
    /**
     * Create slug from string
     */
    public static function createSlug($string) {
        $slug = strtolower(trim($string));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        // Titles in Hindi/other scripts produce no ASCII characters at all.
        return $slug !== '' ? substr($slug, 0, 200) : 'video-' . bin2hex(random_bytes(4));
    }
    
    /**
     * Validate URL
     */
    public static function validateURL($url) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
