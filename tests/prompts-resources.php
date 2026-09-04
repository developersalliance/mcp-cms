#!/usr/bin/env php
<?php
/**
 * Smoke test for MCP prompts + resources (mcp/prompts-resources.php).
 *
 * Usage: php tests/prompts-resources.php <endpoint-url> <mcp-token>
 * Read-only. Exit 0 when every check passes.
 */

$url   = $argv[1] ?? '';
$token = $argv[2] ?? '';
if ($url === '' || $token === '') {
    fwrite(STDERR, "Usage: php tests/prompts-resources.php <endpoint-url> <mcp-token>\n");
    exit(2);
}

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $failures;
    if (!$ok) $failures++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? '  — ' . $detail : '') . "\n";
}
function rpc(string $url, string $token, string $method, array $params = [], $id = 1) {
    $msg = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
    if ($params !== []) $msg['params'] = $params;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($msg),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'X-CMS-MCP-TOKEN: ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    return json_decode((string)$raw, true);
}

echo "Prompts/resources test → {$url}\n";

$init = rpc($url, $token, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'pr-test', 'version' => '1']]);
$caps = $init['result']['capabilities'] ?? [];
check('initialize advertises prompts capability', isset($caps['prompts']));
check('initialize advertises resources capability', isset($caps['resources']));

// prompts
$pl = rpc($url, $token, 'prompts/list');
$prompts = $pl['result']['prompts'] ?? [];
$names = array_column($prompts, 'name');
check('prompts/list returns 4 prompts', count($prompts) === 4, implode(',', $names));
foreach (['new_blog_post', 'add_picture_to_post', 'edit_site_copy', 'page_seo_review'] as $p) {
    check("prompt {$p} listed with title + arguments", in_array($p, $names, true) && !empty($prompts[array_search($p, $names, true)]['arguments']));
}
$argsFor = [
    'new_blog_post' => ['topic' => 'Why headless commerce is overrated', 'audience' => 'store owners', 'tone' => 'plain', 'length' => 'short'],
    'add_picture_to_post' => ['slug' => 'hello-world', 'image_source' => 'https://example.com/photo.jpg', 'placement' => 'featured'],
    'edit_site_copy' => ['page_hint' => 'about page', 'change' => 'update the opening hours'],
    'page_seo_review' => ['page_id' => 'index'],
];
foreach ($argsFor as $p => $args) {
    $g = rpc($url, $token, 'prompts/get', ['name' => $p, 'arguments' => $args]);
    $msgs = $g['result']['messages'] ?? [];
    $text = $msgs[0]['content']['text'] ?? '';
    $ok = isset($g['result']['description']) && count($msgs) === 1 && ($msgs[0]['role'] ?? '') === 'user' && ($msgs[0]['content']['type'] ?? '') === 'text' && strlen($text) > 200;
    check("prompts/get {$p} → one user text message", $ok, substr(json_encode($g['error'] ?? ''), 0, 80));
    foreach ($args as $k => $v) {
        if (in_array($k, ['topic', 'slug', 'change', 'page_id', 'image_source'], true)) {
            check("  {$p}: argument {$k} appears in text", strpos($text, $v) !== false);
        }
    }
    check("  {$p}: mentions preview_url and a publish tool", strpos($text, 'preview_url') !== false && preg_match('/publish_(post|page)/', $text) === 1);
}
$bad = rpc($url, $token, 'prompts/get', ['name' => 'new_blog_post', 'arguments' => []]);
check('prompts/get without required argument → JSON-RPC error', isset($bad['error']));
$bad = rpc($url, $token, 'prompts/get', ['name' => 'nope']);
check('prompts/get unknown prompt → JSON-RPC error', isset($bad['error']));

// resources
$rl = rpc($url, $token, 'resources/list');
$resources = $rl['result']['resources'] ?? [];
$uris = array_column($resources, 'uri');
check('resources/list returns 5 resources', count($resources) === 5, implode(',', $uris));
foreach ($resources as $r) {
    check("  {$r['uri']} has name/title/mimeType", !empty($r['name']) && !empty($r['title']) && !empty($r['mimeType']));
}
$rt = rpc($url, $token, 'resources/templates/list');
$templates = array_column($rt['result']['resourceTemplates'] ?? [], 'uriTemplate');
check('resources/templates/list has posts/{slug} and pages/{page_id}/blocks', in_array('cms://posts/{slug}', $templates, true) && in_array('cms://pages/{page_id}/blocks', $templates, true));

foreach (['cms://pages', 'cms://posts', 'cms://media', 'cms://categories'] as $uri) {
    $rr = rpc($url, $token, 'resources/read', ['uri' => $uri]);
    $c = $rr['result']['contents'][0] ?? null;
    $decoded = $c ? json_decode((string)($c['text'] ?? ''), true) : null;
    check("resources/read {$uri} → JSON array", $c !== null && ($c['mimeType'] ?? '') === 'application/json' && is_array($decoded), substr(json_encode($rr['error'] ?? ''), 0, 80));
    if ($uri === 'cms://media' && is_array($decoded) && $decoded !== []) {
        // The media index (not the directory fallback) is in use: every row carries alt/name keys.
        check('  cms://media rows come from the media index (alt + name keys present)', count(array_filter($decoded, fn($r) => array_key_exists('alt', $r) && array_key_exists('name', $r))) === count($decoded));
    }
    if ($uri === 'cms://pages' && is_array($decoded)) {
        $leak = false;
        foreach ($decoded as $row) { if (isset($row['path']) || (isset($row['url']) && strpos($row['url'], '/var/') !== false)) $leak = true; }
        check('  cms://pages has no filesystem paths and every row has id+url', !$leak && count(array_filter($decoded, fn($r) => isset($r['id'], $r['url'], $r['has_draft']))) === count($decoded));
    }
}
$ug = rpc($url, $token, 'resources/read', ['uri' => 'cms://usage-guide']);
$ugc = $ug['result']['contents'][0] ?? [];
check('resources/read cms://usage-guide → markdown with the four recipes', ($ugc['mimeType'] ?? '') === 'text/markdown' && preg_match_all('/^## (Add an article|Add a picture to a post|Edit site copy|Review page SEO)/m', (string)($ugc['text'] ?? '')) === 4);

// template: pick a real page
$pages = json_decode((string)(rpc($url, $token, 'resources/read', ['uri' => 'cms://pages'])['result']['contents'][0]['text'] ?? '[]'), true) ?: [];
$pid = $pages[0]['id'] ?? 'index';
$pb = rpc($url, $token, 'resources/read', ['uri' => 'cms://pages/' . rawurlencode($pid) . '/blocks']);
$pbd = json_decode((string)($pb['result']['contents'][0]['text'] ?? ''), true);
check("resources/read cms://pages/{$pid}/blocks → blocks list", is_array($pbd) && isset($pbd['blocks']) && is_array($pbd['blocks']), substr(json_encode($pb['error'] ?? ''), 0, 80));
$missing = rpc($url, $token, 'resources/read', ['uri' => 'cms://posts/this-post-does-not-exist']);
check('resources/read missing post → JSON-RPC error', isset($missing['error']));
$unknown = rpc($url, $token, 'resources/read', ['uri' => 'cms://nope']);
check('resources/read unknown uri → JSON-RPC error', isset($unknown['error']) && strpos((string)$unknown['error']['message'], 'Unknown resource') !== false);

echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
