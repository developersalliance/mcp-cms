<?php
/**
 * Generate SEO meta (title, description, og_image_alt, json_ld) for a post.
 *
 * Sends post.title + stripped content (4 KB cap) + featured image URL +
 * collection canonical to the AI. Returns a JSON proposal the post editor
 * surfaces in a per-field diff modal. Save is still a manual click.
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/CSRF.php';
require_once __DIR__ . '/../core/AIClient.php';
require_once __DIR__ . '/../core/BlogManager.php';

$config = require __DIR__ . '/../config/config.php';
header('Content-Type: application/json');

function err($code, $msg) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') err(405, 'POST only');
CSRF::verifyOrDie();

$title    = trim((string)($_POST['title'] ?? ''));
$content  = (string)($_POST['content'] ?? '');
$image    = trim((string)($_POST['featured_image'] ?? ''));
$collectionId = (string)($_POST['collection_id'] ?? '');
$slug     = (string)($_POST['slug'] ?? '');
if ($title === '') err(400, 'title is required');

$ai = AIClient::fromConfig($config);
if (!$ai) err(400, 'AI provider not configured. Settings → AI Assistant.');

// Resolve canonical URL hint for the AI
$blog = new BlogManager($config['root_dir'], $config['cms_dir']);
$collection = $collectionId ? $blog->getCollection($collectionId) : null;
$basePath = $collection['base_path'] ?? '';
$baseUrl = rtrim($config['base_url'] ?? '', '/');
$canonical = $baseUrl . '/' . trim($basePath . '/' . $slug, '/') . '/';
$siteName = $config['site_name'] ?? '';

// Strip tags from content for the AI prompt; truncate to 4KB
$plain = trim(preg_replace('/\s+/', ' ', strip_tags($content)));
if (strlen($plain) > 4000) $plain = substr($plain, 0, 4000);

$system = <<<PROMPT
You write SEO metadata for blog posts. Given a post's title and content, produce a single JSON object with these exact keys:

- title: SEO title for the <title> tag. 50–60 characters ideal. Plain text, no quotes.
- description: meta description. 150–160 characters ideal. Plain text, no markup, no quotes.
- og_image_alt: short alt text describing the featured image (if a featured image URL is provided). Plain text. 50–125 chars. Omit/empty if no image.
- json_ld: a schema.org BlogPosting object as a single JSON object (not an array). MUST include @context, @type, headline, description, image (if available), datePublished, dateModified (use same as datePublished if unknown), mainEntityOfPage, author.

Rules:
- Strings must NEVER contain "</" (forbidden — even inside URLs).
- json_ld.@context must be exactly "https://schema.org".
- json_ld.@type must be exactly "BlogPosting".
- Return ONLY the JSON object. No prose. No markdown fences.
PROMPT;

$user = "POST TITLE: " . $title . "\n";
if ($siteName !== '') $user .= "SITE NAME: " . $siteName . "\n";
$user .= "CANONICAL: " . $canonical . "\n";
if ($image !== '') $user .= "FEATURED IMAGE: " . $image . "\n";
$user .= "\nPOST CONTENT (stripped):\n" . $plain;

try {
    $raw = $ai->complete($user, $system, true, 2048);
} catch (Throwable $e) {
    err(500, 'AI error: ' . $e->getMessage());
}

$proposal = json_decode($raw, true);
if (!is_array($proposal)) err(500, 'AI returned non-JSON: ' . substr($raw, 0, 200));

// Defense-in-depth: reject any string field containing "</"
$walk = function ($v) use (&$walk) {
    if (is_string($v)) return strpos($v, '</') !== false;
    if (is_array($v))  { foreach ($v as $vv) if ($walk($vv)) return true; }
    return false;
};
if ($walk($proposal)) {
    err(400, 'AI proposal contains </ — rejected as unsafe. Try again or edit manually.');
}

// Clamp length hints client-side; server just trusts the model and returns
echo json_encode(['success' => true, 'proposal' => $proposal]);
