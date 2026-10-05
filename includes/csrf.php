<?php
/**
 * CSRF Protection
 * Generate and validate CSRF tokens for form submissions
 */

class CSRF {
    
    /**
     * Generate CSRF token
     */
    public static function generateToken() {
        if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
            $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        }
        return $_SESSION[CSRF_TOKEN_NAME];
    }
    
    /**
     * Get CSRF token
     */
    public static function getToken() {
        return $_SESSION[CSRF_TOKEN_NAME] ?? '';
    }
    
    /**
     * Validate CSRF token
     */
    public static function validateToken($token) {
        if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
            return false;
        }
        
        return hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
    }
    
    /**
     * Validate token from POST request
     */
    public static function validateRequest() {
        $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
        return self::validateToken($token);
    }
    
    /**
     * Generate hidden input field
     */
    public static function getInputField() {
        $token = self::generateToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
    
    /**
     * Get token for AJAX requests
     */
    public static function getTokenForAjax() {
        return self::generateToken();
    }
}
