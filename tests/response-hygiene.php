#!/usr/bin/env php
<?php
/**
 * Response hygiene test — MCP responses must not leak server paths, whole
 * pages must be capped, draft-creating tools must tell the model what to do
 * next, and every tool must carry annotations.
 *
 * Usage:
 *   php tests/response-hygiene.php <endpoint-url> <mcp_token> <root_dir> [page_id]
 *
 * <root_dir> is the install's absolute root_dir (from config/config.php);
 * the test asserts that string never appears in any response.
 * The page named by [page_id] (default "demo") gets a block edited as a
 * DRAFT and then the draft is discarded — the live page is untouched.
 */

$url    = $argv[1] ?? '';
$token  = $argv[2] ?? '';
$root   = rtrim($argv[3] ?? '', '/');
$pageId = $argv[4] ?? 'demo';
if ($url === '' || $token === '' || $root === '') {
    fwrite(STDERR, "Usage: php tests/response-hygiene.php <endpoint-url> <mcp-token> <root_dir> [page_id]\n");
    exit(2);
}

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $failures;
    if (!$ok) $failures++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? '  — ' . $detail : '') . "\n";
}

$seq = 0;
function rpc(string $url, string $token, string $method, array $params = []): array {
    global $seq;
    $msg = ['jsonrpc' => '2.0', 'id' => ++$seq, 'method' => $method];
    if ($params !== []) $msg['params'] = $params;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($msg),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-CMS-MCP-TOKEN: ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = (string)curl_exec($ch);
    curl_close($ch);
    return ['raw' => $body, 'json' => json_decode($body, true)];
}
function call(string $url, string $token, string $tool, array $args = []): array {
    $r = rpc($url, $token, 'tools/call', ['name' => $tool, 'arguments' => $args === [] ? new stdClass() : $args]);
    $text = $r['json']['result']['content'][0]['text'] ?? '';
    return ['raw' => $r['raw'], 'text' => $text, 'data' => json_decode($text, true), 'isError' => (bool)($r['json']['result']['isError'] ?? false)];
}

echo "Response hygiene test → {$url}\n";

// 1. tools/list: annotations on every tool
$r = rpc($url, $token, 'tools/list');
$tools = $r['json']['result']['tools'] ?? [];
$missing = [];
foreach ($tools as $t) {
    if (empty($t['annotations']) || !array_key_exists('readOnlyHint', $t['annotations']) || empty($t['title'])) {
        $missing[] = $t['name'];
    }
}
check('tools/list returns tools', count($tools) > 0, count($tools) . ' tools');
check('every tool has title + annotations.readOnlyHint', $missing === [], implode(', ', array_slice($missing, 0, 8)));
check('tools/list has no server paths', strpos($r['raw'], $root) === false);

// 2. read-only calls: no root_dir anywhere
$leaks = [];
$reads = [
    ['list_pages', []],
    ['list_blocks', ['page_id' => $pageId]],
    ['search_blocks', ['search_text' => 'a']],
    ['read_page', ['page_id' => $pageId]],
    ['list_backups', ['page_id' => $pageId]],
    ['list_global_backups', []],
    ['list_files', ['dir' => '']],
    ['list_posts', []],
    ['list_authors', []],
    ['get_page_meta', ['page_id' => $pageId]],
    ['get_usage_tips', []],
    ['search_in_page', ['page_id' => $pageId, 'search' => 'html']],
    ['get_page_region', ['page_id' => $pageId, 'start_line' => 1, 'end_line' => 5]],
];
foreach ($reads as [$tool, $args]) {
    $c = call($url, $token, $tool, $args);
    if (strpos($c['raw'], $root) !== false) $leaks[] = $tool;
}
check('no read-only response contains root_dir', $leaks === [], implode(', ', $leaks));

// 3. list_pages shape
$c = call($url, $token, 'list_pages');
$first = $c['data']['pages'][0] ?? [];
check('list_pages entries have id + url + has_draft, no path', isset($first['id'], $first['url']) && array_key_exists('has_draft', $first) && !isset($first['path']), json_encode($first));

// 4. search_blocks has page_url, no page_path
$c = call($url, $token, 'search_blocks', ['search_text' => 'a']);
$m = $c['data']['matches'][0] ?? [];
check('search_blocks matches have page_url, no page_path', $m === [] || (isset($m['page_url']) && !isset($m['page_path'])));

// 5. read_page truncation
$c = call($url, $token, 'read_page', ['page_id' => $pageId, 'max_chars' => 1000]);
$d = $c['data'] ?? [];
check('read_page honours max_chars', isset($d['content']) && strlen($d['content']) <= 1000 && ($d['truncated'] ?? null) === true && !empty($d['hint']) && isset($d['total_chars']) && !isset($d['path']), 'len=' . strlen($d['content'] ?? '') . ' truncated=' . var_export($d['truncated'] ?? null, true));
check('read_page includes url', isset($d['url']));

// 6. draft hints after update_block (then discard)
$blocks = call($url, $token, 'list_blocks', ['page_id' => $pageId])['data']['blocks'] ?? [];
$target = null;
foreach ($blocks as $b) { if (!empty($b['custom'])) { $target = $b['name']; break; } }
if ($target === null && $blocks) $target = $blocks[0]['name'];
if ($target === null) {
    check('a block exists on page ' . $pageId . ' to test draft hints', false);
} else {
    $orig = call($url, $token, 'read_block', ['page_id' => $pageId, 'name' => $target])['data']['block']['content'] ?? '';
    $u = call($url, $token, 'update_block', ['page_id' => $pageId, 'name' => $target, 'content' => $orig . "\n<!-- hygiene-test -->"]);
    $ud = $u['data'] ?? [];
    check('update_block returns preview_url + next_steps + page_id', !$u['isError'] && !empty($ud['preview_url']) && !empty($ud['next_steps']) && ($ud['page_id'] ?? null) === $pageId, $u['text']);
    check('preview_url points at admin preview with draft=1', str_contains((string)($ud['preview_url'] ?? ''), '/cms/admin/preview.php?page_id=') && str_contains((string)($ud['preview_url'] ?? ''), 'draft=1'));
    $mu = call($url, $token, 'update_page_meta', ['page_id' => $pageId, 'description' => 'hygiene test description ' . time()]);
    check('update_page_meta returns preview_url + next_steps', !$mu['isError'] && !empty($mu['data']['preview_url']) && !empty($mu['data']['next_steps']), $mu['text']);
    $dd = call($url, $token, 'discard_draft', ['page_id' => $pageId]);
    check('discard_draft cleans up', !$dd['isError']);
    $after = call($url, $token, 'read_block', ['page_id' => $pageId, 'name' => $target])['data']['block']['content'] ?? '';
    check('live block unchanged after discard', $after === $orig);
}

// 7. write-path responses: no root_dir either
$lb = call($url, $token, 'list_backups', ['page_id' => $pageId]);
$b0 = $lb['data']['backups'][0] ?? null;
check('list_backups entries have timestamp/date/size, no path', $b0 === null || (isset($b0['timestamp'], $b0['date']) && array_key_exists('size', $b0) && !isset($b0['path'])), json_encode($b0));

// 8. usage tips are recipes
$tips = call($url, $token, 'get_usage_tips')['data'] ?? [];
check('get_usage_tips returns recipes + rules', isset($tips['recipes']['add_article'], $tips['recipes']['add_picture'], $tips['recipes']['edit_site_copy'], $tips['recipes']['change_page_seo'], $tips['rules']));

echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
