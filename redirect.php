<?php
/**
 * Public redirect handler — served as /cms/redirect.php.
 *
 * Looks the requested path up in settings/redirects.json and issues the
 * configured 301/302. Anything without a match gets a minimal 404 page.
 *
 * Wire it as the site's 404 handler so unmatched URLs flow through here:
 *   nginx:  error_page 404 = /cms/redirect.php;  (see docs/redirects.md)
 *   Apache: not needed — RedirectManager writes native Redirect lines into
 *           the site's .htaccess; this handler is a fallback only.
 *
 * The path is taken from ?path= when present, otherwise from the original
 * REQUEST_URI (which nginx preserves for error_page handlers).
 */

$configFile = __DIR__ . '/config/config.php';
$config = is_file($configFile) ? require $configFile : [];
if (!is_array($config)) {
    $config = [];
}

$path = (string)($_GET['path'] ?? '');
if ($path === '') {
    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
}

$target = null;
$code = 301;
if ($path !== '' && !empty($config['cms_dir'])) {
    require_once __DIR__ . '/core/RedirectManager.php';
    try {
        $manager = new RedirectManager($config['cms_dir'] . '/settings');
        $hit = $manager->match($path);
        if ($hit !== null) {
            // Re-validate at emit time: same-host relative path or absolute
            // http(s) URL only, and never anything header-injectable.
            $to = (string)$hit['to'];
            $safeRelative = $to !== '' && $to[0] === '/' && strpos($to, '//') !== 0;
            $safeAbsolute = preg_match('#^https?://#i', $to) && filter_var($to, FILTER_VALIDATE_URL) !== false;
            if (($safeRelative || $safeAbsolute) && !preg_match('/[\s\x00-\x1f\x7f]/', $to)) {
                $target = $to;
                $code = in_array((int)$hit['code'], [301, 302], true) ? (int)$hit['code'] : 301;
            }
        }
    } catch (Exception $e) {
        // Unreadable settings must never break the public site — fall through to 404.
    }
}

if ($target !== null) {
    header('Location: ' . $target, true, $code);
    exit;
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
$siteName = htmlspecialchars((string)($config['site_name'] ?? 'This site'));
echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<title>Page not found</title>';
echo '<style>body{font-family:system-ui,sans-serif;max-width:560px;margin:80px auto;padding:0 24px;color:#111}';
echo 'h1{font-size:24px;margin-bottom:8px}p{color:#555;line-height:1.55}a{color:#e64d2e}</style></head><body>';
echo '<h1>Page not found</h1>';
echo '<p>' . $siteName . ' has no page at this address. It may have been moved or removed.</p>';
echo '<p><a href="/">Go to the homepage</a></p>';
echo '</body></html>';
