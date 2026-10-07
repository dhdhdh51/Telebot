<?php
/**
 * Shared setup for admin pages: session, auth, CSRF, flash messages.
 */

define('ADMIN_PAGE', true);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/schema.php';

// The admin panel must never be framed (clickjacking).
setCsp([], "'none'");
header('X-Frame-Options: DENY');

Auth::requireAdmin();
Schema::migrate();

/** Telegram, settings, payment keys and ad scripts are SUPER_ADMIN only. */
function requireSuperAdmin() {
    $admin = Auth::getCurrentAdmin();
    if (!$admin || $admin['role'] !== 'SUPER_ADMIN') {
        flash('error', 'Only a SUPER_ADMIN can open that page.');
        header('Location: /admin/dashboard.php');
        exit;
    }
}

function isSuperAdmin() {
    $admin = Auth::getCurrentAdmin();
    return $admin && $admin['role'] === 'SUPER_ADMIN';
}

function flash($type = null, $message = null) {
    if ($type !== null) {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function renderFlash() {
    $f = flash();
    if ($f) {
        $cls = $f['type'] === 'success' ? 'alert-success' : 'alert-error';
        echo '<div class="alert ' . $cls . '">' . htmlspecialchars($f['message'], ENT_QUOTES, 'UTF-8') . '</div>';
    }
}

function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Abort unless POST with a valid CSRF token. */
function requirePostCsrf() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !CSRF::validateRequest()) {
        flash('error', 'Invalid or expired form. Please try again.');
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
}

/** Abort with a message if the admin role lacks the permission. */
function requirePermission($permission, $redirect = '/admin/dashboard.php') {
    if (!Auth::hasPermission($permission)) {
        flash('error', 'You do not have permission to do that.');
        header('Location: ' . $redirect);
        exit;
    }
}

/**
 * Convert php.ini size strings ("512M") to bytes.
 */
function iniBytes($val) {
    $val = trim((string)$val);
    $num = (int)$val;
    switch (strtolower(substr($val, -1))) {
        case 'g': return $num * 1024 ** 3;
        case 'm': return $num * 1024 ** 2;
        case 'k': return $num * 1024;
    }
    return $num;
}

/** Effective max upload size = smallest of app limit, upload_max_filesize, post_max_size. */
function effectiveUploadLimit() {
    return min(MAX_UPLOAD_SIZE, iniBytes(ini_get('upload_max_filesize')), iniBytes(ini_get('post_max_size')));
}
