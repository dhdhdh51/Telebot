<?php
/**
 * Admin Logout (POST + CSRF token, so a third-party page can't log admins out)
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && CSRF::validateRequest()) {
    Auth::adminLogout();
}

header('Location: /admin/login.php');
exit;
