#!/usr/bin/env php
<?php
/**
 * Security / ops checks for the MCP endpoint, run against a LOCAL install:
 *   - update_file_region refuses .php unless mcp_allow_php_edits is on
 *   - update_file_region on .css works
 *   - list_templates / read_template / update_template round-trip creates a
 *     site override that BlogRenderer then uses for a published post
 *   - the MCP activity log records the writes
 *
 * Usage:
 *   php tests/security-ops.php <endpoint-url> <mcp-token> <engine-dir>
 * e.g.
 *   php -S 127.0.0.1:8086 -t demo-site &   (install first)
 *   php tests/security-ops.php http://127.0.0.1:8086/cms/mcp/index.php tok… $PWD
 *
 * Writes into the local install only (site root = config root_dir). Toggles
 * mcp_allow_php_edits in config/config.php and restores it afterwards.
 */

$url = $argv[1] ?? '';
$token = $argv[2] ?? '';
$engine = rtrim($argv[3] ?? dirname(__DIR__), '/');
if ($url === '' || $token === '') {
    fwrite(STDERR, "Usage: php tests/security-ops.php <endpoint-url> <mcp-token> [engine-dir]\n");
    exit(2);
}
$configPath = $engine . '/config/config.php';
$config = require $configPath;
$rootDir = rtrim($config['root_dir'], '/');
$siteBase = preg_replace('#/cms/mcp/index\.php$#', '', $url);

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $failures;
    if (!$ok) $failures++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? '  — ' . $detail : '') . "\n";
}
function call(string $url, string $token, string $tool, array $args = []): array {
    static $id = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['jsonrpc' => '2.0', 'id' => ++$id, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args ?: new stdClass()]]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CMS-MCP-TOKEN: ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $j = json_decode((string)$raw, true);
    $text = $j['result']['content'][0]['text'] ?? '';
    $data = json_decode($text, true);
    return ['ok' => empty($j['result']['isError']), 'text' => $text, 'data' => is_array($data) ? $data : [], 'raw' => $raw];
}
function setPhpEdits(string $configPath, bool $on): void {
    $cfg = require $configPath;
    $cfg['mcp_allow_php_edits'] = $on;
    file_put_contents($configPath, "<?php\n/**\n * Core configuration for flat MCP CMS.\n */\nreturn " . var_export($cfg, true) . ";\n");
    // opcache.revalidate_freq (default 2 s) would otherwise serve the old
    // config to the next request from the built-in server.
    sleep(3);
}

echo "Security/ops test → {$url}\n";
$stamp = date('YmdHis');

// ---------------------------------------------------------------- fixtures
$phpFile = $rootDir . '/mcp-test-' . $stamp . '.php';
$cssFile = $rootDir . '/mcp-test-' . $stamp . '.css';
file_put_contents($phpFile, "<?php\n// line two\necho 'hello';\n");
file_put_contents($cssFile, "body { color: red; }\n/* two */\n");
$phpRel = basename($phpFile);
$cssRel = basename($cssFile);

// 1. PHP edit refused by default
setPhpEdits($configPath, false);
$r = call($url, $token, 'update_file_region', ['path' => $phpRel, 'start_line' => 2, 'end_line' => 2, 'old_region' => '// line two', 'new_region' => '// patched']);
check('update_file_region on .php refused when mcp_allow_php_edits is off', !$r['ok'] && stripos($r['text'], 'disabled') !== false, substr($r['text'], 0, 90));
check('.php file untouched', strpos(file_get_contents($phpFile), '// line two') !== false);

// 2. read/search of PHP still allowed
$r = call($url, $token, 'read_file', ['path' => $phpRel]);
check('read_file on .php still allowed', $r['ok'] && strpos($r['text'], 'line two') !== false);
$r = call($url, $token, 'search_in_file', ['path' => $phpRel, 'query' => 'hello']);
check('search_in_file on .php still allowed', $r['ok'] && ($r['data']['count'] ?? 0) === 1);

// 3. PHP edit allowed with the flag
setPhpEdits($configPath, true);
$r = call($url, $token, 'update_file_region', ['path' => $phpRel, 'start_line' => 2, 'end_line' => 2, 'old_region' => '// line two', 'new_region' => '// patched']);
check('update_file_region on .php works when the flag is on', $r['ok'] && strpos(file_get_contents($phpFile), '// patched') !== false, substr($r['text'], 0, 90));
setPhpEdits($configPath, false);

// 4. CSS edit works without the flag
$r = call($url, $token, 'update_file_region', ['path' => $cssRel, 'start_line' => 1, 'end_line' => 1, 'old_region' => 'body { color: red; }', 'new_region' => 'body { color: blue; }']);
check('update_file_region on .css works', $r['ok'] && strpos(file_get_contents($cssFile), 'blue') !== false, substr($r['text'], 0, 90));

// ---------------------------------------------------------------- templates
// Templates are PHP: update_template needs the same owner flag.
$r = call($url, $token, 'update_template', ['name' => 'blog-detail', 'content' => "<?php echo 'x';"]);
check('update_template refused while mcp_allow_php_edits is off', !$r['ok'] && stripos($r['text'], 'disabled') !== false, substr($r['text'], 0, 90));
setPhpEdits($configPath, true);
$r = call($url, $token, 'list_templates');
$names = array_column($r['data']['templates'] ?? [], 'name');
check('list_templates lists engine defaults', $r['ok'] && in_array('default-detail', $names, true) && in_array('default-list', $names, true), implode(',', $names));
check('list_templates reports the active template per collection', !empty($r['data']['active'][0]['template']['name']), json_encode($r['data']['active'][0] ?? null));
check('list_templates includes the variable cheatsheet', isset($r['data']['variables']['detail']['$post']));

$r = call($url, $token, 'read_template', ['name' => 'blog-detail']);
check('read_template("blog-detail") falls back to default-detail engine file', $r['ok'] && ($r['data']['scope'] ?? '') === 'engine' && strpos($r['data']['content'] ?? '', '<?php') !== false, json_encode(['scope' => $r['data']['scope'] ?? null]));
$engineSource = (string)($r['data']['content'] ?? '');

$r = call($url, $token, 'read_template', ['name' => 'nope']);
check('read_template rejects a bad name', !$r['ok']);

$bad = call($url, $token, 'update_template', ['name' => 'blog-detail', 'content' => "<?php\nif ( {\n"]);
check('update_template refuses a template that fails php -l', !$bad['ok'] && stripos($bad['text'], 'syntax') !== false, substr($bad['text'], 0, 90));
check('no override written on lint failure', !is_file($rootDir . '/theme/collection-templates/blog-detail.php'));

$marker = 'MCP-TEMPLATE-OVERRIDE-' . $stamp;
$override = str_replace('<?php', "<?php\n// {$marker}", $engineSource, $count);
if ($count === 0) $override = "<?php // {$marker} ?>\n" . $engineSource;
$override = str_replace('</body>', "<!-- {$marker} -->\n</body>", $override);
if (strpos($override, $marker . ' -->') === false) $override .= "\n<!-- {$marker} -->\n";
$r = call($url, $token, 'update_template', ['name' => 'blog-detail', 'content' => $override]);
$overridePath = $rootDir . '/theme/collection-templates/blog-detail.php';
check('update_template creates the site override', $r['ok'] && ($r['data']['created_override'] ?? false) && is_file($overridePath), substr($r['text'], 0, 120));

$r = call($url, $token, 'read_template', ['name' => 'blog-detail']);
check('read_template now returns the site override', $r['ok'] && ($r['data']['scope'] ?? '') === 'site' && strpos($r['data']['content'] ?? '', $marker) !== false);

$r = call($url, $token, 'update_template', ['name' => 'blog-detail', 'content' => $override . "\n"]);
check('second update_template backs up the previous override', $r['ok'] && !empty($r['data']['backup']) && is_file($engine . '/' . $r['data']['backup']), (string)($r['data']['backup'] ?? ''));

// Render check: publish a post and fetch it
$slug = 'mcp-tpl-test-' . strtolower($stamp);
$r = call($url, $token, 'create_post', ['slug' => $slug, 'title' => 'Template test', 'content' => '<p>Template override render check.</p>']);
check('create_post for render check', $r['ok'], substr($r['text'], 0, 80));
$r = call($url, $token, 'publish_post', ['slug' => $slug]);
check('publish_post for render check', $r['ok'], substr($r['text'], 0, 80));
$html = @file_get_contents($siteBase . '/blog/' . $slug . '/');
check('published post renders through the site override', is_string($html) && strpos($html, $marker) !== false && strpos($html, 'Template override render check') !== false, is_string($html) ? ('len=' . strlen($html)) : 'fetch failed');

// ---------------------------------------------------------------- activity log
$logPath = $engine . '/logs/mcp-activity.jsonl';
$log = is_file($logPath) ? file_get_contents($logPath) : '';
check('activity log records update_template', strpos($log, '"tool":"update_template"') !== false);
check('activity log records the refused PHP edit as a failure', preg_match('/"tool":"update_file_region".*"ok":false/', $log) === 1);
check('activity log records create_post / publish_post', strpos($log, '"tool":"create_post"') !== false && strpos($log, '"tool":"publish_post"') !== false);

// ---------------------------------------------------------------- cleanup
setPhpEdits($configPath, false);
call($url, $token, 'delete_post', ['slug' => $slug]);
@unlink($phpFile); @unlink($cssFile);
@unlink($overridePath);
foreach (glob($engine . '/backups/templates/blog-detail.*.php') ?: [] as $b) @unlink($b);
@rmdir($rootDir . '/theme/collection-templates'); @rmdir($rootDir . '/theme');
foreach (glob($engine . '/backups/mcp-test-*') ?: [] as $b) { if (is_dir($b)) { array_map('unlink', glob("$b/*") ?: []); @rmdir($b); } }

echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
