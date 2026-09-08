<?php
/**
 * Auth Guard - Require authentication for admin pages
 */

require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/Permissions.php';

$config = require __DIR__ . '/../../config/config.php';

// Site hooks (theme/hooks.php) load once per request for every admin page
require_once __DIR__ . '/../../core/Hooks.php';
Hooks::boot((string)($config['root_dir'] ?? ''), (string)($config['cms_dir'] ?? ''));

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

/*
 * Role-based access control.
 *
 * Every admin page includes this guard, so $permissions, $currentRole and the
 * user_can()/require_capability() helpers are available everywhere. Login alone
 * no longer implies full access — sensitive actions must be gated with
 * require_capability() server-side, and UI hidden with user_can().
 */
$permissions = new Permissions(__DIR__ . '/../../config/roles.json');
$currentRole = $currentUser['role'] ?? 'viewer';

if (!function_exists('user_can')) {
    /**
     * Does the currently logged-in user hold the given capability?
     */
    function user_can(string $capability): bool
    {
        global $permissions, $currentRole;
        if (!$permissions instanceof Permissions) {
            return false;
        }
        return $permissions->roleCan($currentRole, $capability);
    }

    /**
     * Abort the request unless the current user holds $capability. Sends a JSON
     * 403 to API/AJAX callers and an HTML 403 otherwise. Call at the top of any
     * destructive or privileged handler.
     */
    function require_capability(string $capability): void
    {
        if (user_can($capability)) {
            return;
        }

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $wantsJson = (isset($_GET['json']) && $_GET['json'] !== '0')
            || stripos($accept, 'application/json') !== false
            || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

        http_response_code(403);
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'You do not have permission to perform this action.']);
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Forbidden</title>';
        echo '<style>body{font-family:system-ui,sans-serif;max-width:560px;margin:80px auto;padding:0 24px;color:#111}';
        echo 'h1{font-size:24px;margin-bottom:8px}p{color:#555;line-height:1.55}a{color:#e64d2e}</style></head><body>';
        echo '<h1>Forbidden</h1>';
        echo '<p>Your account role does not have permission to perform this action.</p>';
        echo '<p><a href="/cms/admin/">Return to the dashboard</a></p>';
        echo '</body></html>';
        exit;
    }
}
