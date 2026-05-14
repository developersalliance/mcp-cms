<?php
/**
 * Auth Guard - Require authentication for admin pages
 */

require_once __DIR__ . '/../../core/Auth.php';

$config = require __DIR__ . '/../../config/config.php';
$auth = new Auth(__DIR__ . '/../../config/users.json');

// Check if user is logged in
if (!$auth->isLoggedIn()) {
    // For AJAX / JSON callers (Accept: application/json, ?json=1, or any
    // POST that's clearly an API call), return a JSON 401 instead of an
    // HTML redirect. The redirect form silently breaks fetch().json() in
    // admin UIs that expire mid-session.
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $wantsJson = (isset($_GET['json']) && $_GET['json'] !== '0')
        || stripos($accept, 'application/json') !== false
        || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
    if ($wantsJson) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Not authenticated', 'login_url' => '/cms/admin/login.php']);
        exit;
    }
    header('Location: /cms/admin/login.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
