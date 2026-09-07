<?php
/**
 * Public newsletter endpoint — served as /cms/subscribe.php.
 *
 * POST email=…              store a pending subscription + send the confirm mail
 *                           (honeypot field "website" must be empty; per-IP
 *                           rate limit of 5 signups per hour)
 * GET  ?confirm=<token>     confirm a pending subscription
 * GET  ?unsubscribe=<token> remove a subscriber
 *
 * A site embeds a plain form:
 *   <form method="post" action="/cms/subscribe.php">
 *     <input type="email" name="email" required>
 *     <input type="text" name="website" style="display:none" tabindex="-1" autocomplete="off">
 *     <button>Subscribe</button>
 *   </form>
 *
 * Responses are minimal human-readable HTML. Same-origin form posts only —
 * no CORS headers are emitted on purpose.
 */

$configFile = __DIR__ . '/config/config.php';
$config = is_file($configFile) ? require $configFile : [];
if (!is_array($config) || empty($config['cms_dir'])) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><p>The CMS is not installed yet.</p>';
    exit;
}

require_once __DIR__ . '/core/SubscriberManager.php';

$settingsDir = rtrim($config['cms_dir'], '/') . '/settings';
$manager = new SubscriberManager($settingsDir, $config);
$siteName = (string)($config['site_name'] ?? 'this site');

/** Render a minimal response page and exit. */
function subscribe_page(string $title, string $message, int $status = 200): void
{
    global $siteName;
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . htmlspecialchars($title) . '</title>';
    echo '<style>body{font-family:system-ui,sans-serif;max-width:560px;margin:80px auto;padding:0 24px;color:#111}';
    echo 'h1{font-size:24px;margin-bottom:8px}p{color:#555;line-height:1.55}a{color:#e64d2e}</style></head><body>';
    echo '<h1>' . htmlspecialchars($title) . '</h1>';
    echo '<p>' . htmlspecialchars($message) . '</p>';
    echo '<p><a href="/">Back to ' . htmlspecialchars($siteName) . '</a></p>';
    echo '</body></html>';
    exit;
}

/**
 * Sliding-window rate limit: at most $max signups per $windowSeconds per IP,
 * tracked in a settings/ scratch file. Fails open if the file is unwritable.
 */
function subscribe_rate_limited(string $settingsDir, string $ip, int $max = 5, int $windowSeconds = 3600): bool
{
    $file = $settingsDir . '/.subscribe-rate.json';
    $now = time();
    $data = [];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $decoded = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($decoded)) $data = $decoded;
    }
    // Prune expired windows for every IP so the file cannot grow unbounded
    foreach ($data as $key => $stamps) {
        $fresh = array_values(array_filter(is_array($stamps) ? $stamps : [], fn($t) => is_int($t) && $t > $now - $windowSeconds));
        if ($fresh === []) { unset($data[$key]); } else { $data[$key] = $fresh; }
    }
    $hits = $data[$ip] ?? [];
    if (count($hits) >= $max) {
        return true;
    }
    $hits[] = $now;
    $data[$ip] = $hits;
    @file_put_contents($file, json_encode($data), LOCK_EX);
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Honeypot: real visitors never fill this field; bots that do get the
    // success page and no stored subscription.
    if (trim((string)($_POST['website'] ?? '')) !== '') {
        subscribe_page('Almost there', 'Check your inbox for a confirmation mail to complete your subscription.');
    }

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (subscribe_rate_limited($settingsDir, $ip)) {
        subscribe_page('Too many attempts', 'Too many signup attempts from your address. Please try again in an hour.', 429);
    }

    try {
        $result = $manager->subscribe((string)($_POST['email'] ?? ''));
    } catch (Exception $e) {
        subscribe_page('Something went wrong', $e->getMessage(), 400);
    }

    $subscriber = $result['subscriber'];
    if (($subscriber['status'] ?? '') === 'pending') {
        $manager->sendConfirmationMail($subscriber);
    }
    // Same message whether the address was new, pending or already confirmed —
    // the endpoint must not leak who is subscribed.
    subscribe_page('Almost there', 'Check your inbox for a confirmation mail to complete your subscription.');
}

if (isset($_GET['confirm'])) {
    $subscriber = $manager->confirm((string)$_GET['confirm']);
    if ($subscriber !== null) {
        subscribe_page('Subscription confirmed', 'You are subscribed to updates from ' . $siteName . '. Every mail includes an unsubscribe link.');
    }
    subscribe_page('Link not valid', 'This confirmation link is not valid or was already used.', 404);
}

if (isset($_GET['unsubscribe'])) {
    if ($manager->unsubscribe((string)$_GET['unsubscribe'])) {
        subscribe_page('Unsubscribed', 'You have been unsubscribed from ' . $siteName . '. Sorry to see you go.');
    }
    subscribe_page('Link not valid', 'This unsubscribe link is not valid or the address was already removed.', 404);
}

subscribe_page('Newsletter', 'Use the subscription form on ' . $siteName . ' to sign up for updates.', 400);
