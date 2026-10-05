<?php
/**
 * Authentication Handler
 * Admin authentication and session management
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/security.php';

class Auth {
    
    /**
     * Admin login
     */
    public static function adminLogin($username, $password) {
        $db = db();
        
        // Check rate limiting
        $rateLimitKey = 'admin_login_' . Security::getClientIP();
        $rateLimit = Security::checkRateLimit($rateLimitKey, 5, 900); // 5 attempts per 15 minutes
        
        if (!$rateLimit['allowed']) {
            return [
                'success' => false,
                'error' => 'Too many login attempts. Please try again in ' . ceil($rateLimit['retry_after'] / 60) . ' minutes.'
            ];
        }
        
        // Get admin
        $admin = $db->fetchOne(
            "SELECT * FROM admins WHERE (username = ? OR email = ?) AND status = 'ACTIVE'",
            [$username, $username]
        );
        
        if (!$admin) {
            // Burn the same bcrypt time as a real check so response timing
            // doesn't reveal which usernames exist.
            password_verify($password, '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            return ['success' => false, 'error' => 'Invalid credentials'];
        }
        
        // Check if account is locked
        if ($admin['locked_until'] && strtotime($admin['locked_until']) > time()) {
            $remaining = ceil((strtotime($admin['locked_until']) - time()) / 60);
            return [
                'success' => false,
                'error' => 'Account is locked. Please try again in ' . $remaining . ' minutes.'
            ];
        }
        
        // Verify password
        if (!Security::verifyPassword($password, $admin['password'])) {
            // Increment login attempts
            self::incrementLoginAttempts($admin['id']);
            return ['success' => false, 'error' => 'Invalid credentials'];
        }
        
        // Reset login attempts
        $db->execute(
            "UPDATE admins SET login_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?",
            [$admin['id']]
        );
        
        // Create session
        $sessionId = Security::generateToken(64);
        $expiresAt = date('Y-m-d H:i:s', time() + 86400); // 24 hours
        
        $db->execute(
            "INSERT INTO admin_sessions (admin_id, session_id, ip_address, user_agent, expires_at) 
             VALUES (?, ?, ?, ?, ?)",
            [
                $admin['id'],
                $sessionId,
                Security::getClientIP(),
                Security::getUserAgent(),
                $expiresAt
            ]
        );
        
        // New session id on privilege change (prevents session fixation)
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_session_id'] = $sessionId;
        $_SESSION['admin_username'] = $admin['username'];
        $_SESSION['admin_role'] = $admin['role'];
        
        return ['success' => true, 'admin' => $admin];
    }
    
    /**
     * Increment login attempts
     */
    private static function incrementLoginAttempts($adminId) {
        $db = db();
        
        $admin = $db->fetchOne("SELECT login_attempts FROM admins WHERE id = ?", [$adminId]);
        $attempts = $admin['login_attempts'] + 1;
        
        $sql = "UPDATE admins SET login_attempts = ? WHERE id = ?";
        $params = [$attempts, $adminId];
        
        // Lock account if max attempts reached
        $maxAttempts = 5;
        if ($attempts >= $maxAttempts) {
            $lockDuration = 1800; // 30 minutes
            $lockedUntil = date('Y-m-d H:i:s', time() + $lockDuration);
            $sql = "UPDATE admins SET login_attempts = ?, locked_until = ? WHERE id = ?";
            $params = [$attempts, $lockedUntil, $adminId];
        }
        
        $db->execute($sql, $params);
    }
    
    /**
     * Check if admin is authenticated
     */
    public static function isAdminAuthenticated() {
        if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_session_id'])) {
            return false;
        }
        
        $db = db();
        
        // Also re-check admin status, so suspending an admin ends their sessions immediately.
        $session = $db->fetchOne(
            "SELECT s.id FROM admin_sessions s JOIN admins a ON a.id = s.admin_id
             WHERE s.session_id = ? AND s.admin_id = ? AND s.expires_at > NOW() AND a.status = 'ACTIVE'",
            [$_SESSION['admin_session_id'], $_SESSION['admin_id']]
        );
        
        return $session !== false;
    }
    
    /**
     * Get current admin
     */
    public static function getCurrentAdmin() {
        if (!self::isAdminAuthenticated()) {
            return null;
        }
        
        $db = db();
        return $db->fetchOne("SELECT * FROM admins WHERE id = ?", [$_SESSION['admin_id']]);
    }
    
    /**
     * Admin logout
     */
    public static function adminLogout() {
        if (isset($_SESSION['admin_session_id'])) {
            $db = db();
            $db->execute("DELETE FROM admin_sessions WHERE session_id = ?", [$_SESSION['admin_session_id']]);
        }
        
        unset($_SESSION['admin_id']);
        unset($_SESSION['admin_session_id']);
        unset($_SESSION['admin_username']);
        unset($_SESSION['admin_role']);
        
        return true;
    }
    
    /**
     * Require admin authentication
     */
    public static function requireAdmin() {
        if (!self::isAdminAuthenticated()) {
            header('Location: /admin/login.php');
            exit;
        }
    }
    
    /**
     * Check admin permission
     */
    public static function hasPermission($permission) {
        $admin = self::getCurrentAdmin();
        if (!$admin) {
            return false;
        }
        
        // Super admin has all permissions
        if ($admin['role'] === 'SUPER_ADMIN') {
            return true;
        }
        
        // Define role permissions
        $rolePermissions = [
            'ADMIN' => ['view', 'create', 'edit', 'delete'],
            'MODERATOR' => ['view', 'create', 'edit']
        ];
        
        $role = $admin['role'];
        return isset($rolePermissions[$role]) && in_array($permission, $rolePermissions[$role]);
    }
}
