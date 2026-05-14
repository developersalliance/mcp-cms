<?php
/**
 * Legacy redirect: Blog Templates → Collection Templates.
 *
 * The page was renamed when per-collection templates landed. We keep
 * this stub so bookmarks and old links don't 404.
 */
require_once __DIR__ . '/includes/auth-guard.php';

$qs = http_build_query($_GET);
header('Location: /cms/admin/collection-templates.php' . ($qs ? '?' . $qs : ''), true, 301);
exit;
