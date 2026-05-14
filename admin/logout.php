<?php
/**
 * Admin Logout
 */

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/CSRF.php';

$config = is_file(__DIR__ . '/../config/config.php') ? require __DIR__ . '/../config/config.php' : [];
$adminPath = rtrim($config['admin_path'] ?? '/cms/admin/', '/') . '/';

// POST + CSRF so a remote site can't force-logout an admin via <img> tag
// or background fetch. Anonymous GET on logout was a LOW audit finding.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'POST only — use the Sign Out button in the admin header.';
    exit;
}
CSRF::verifyOrDie();

$auth = new Auth(__DIR__ . '/../config/users.json');
$auth->logout();

header('Location: ' . $adminPath . 'login.php');
exit;
