#!/usr/bin/env php
<?php
/**
 * End-to-end test of the blog post / category / revision MCP tools.
 *
 * Usage: php tests/post-tools.php <endpoint-url> <mcp-token>
 * Writes and then deletes test posts + categories (slugs prefixed "mcptest-").
 */

$url = $argv[1] ?? ''; $token = $argv[2] ?? '';
if ($url === '' || $token === '') { fwrite(STDERR, "Usage: php tests/post-tools.php <endpoint-url> <mcp-token>\n"); exit(2); }

$failures = 0; $n = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $failures;
    if (!$ok) $failures++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? '  — ' . $detail : '') . "\n";
}
function call(string $tool, array $args = []): array {
    global $url, $token, $n;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['jsonrpc' => '2.0', 'id' => ++$n, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args ?: new stdClass()]]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CMS-MCP-TOKEN: ' . $token],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch); curl_close($ch);
    $j = json_decode((string)$raw, true);
    $text = $j['result']['content'][0]['text'] ?? '';
    $data = json_decode($text, true);
    return ['ok' => empty($j['result']['isError']) && isset($j['result']), 'data' => is_array($data) ? $data : [], 'text' => $text];
}

$suffix = substr(md5((string)microtime(true)), 0, 6);
$slug = "mcptest-post-{$suffix}";
$catA = "MCP Test Cat A {$suffix}";
$catB = "MCP Test Cat B {$suffix}";
echo "Post tools test → {$url}\n";

// tools present
$r = call('list_categories');
check('list_categories works', $r['ok'] && isset($r['data']['categories']));

// create with markdown, categories by name, published in one call
$md = "# Ignored H1\n\nIntro with **bold** and a [link](https://example.com).\n\n## Section\n\n- one\n- two\n\n![Pic](/assets/content/x.jpg)\n\n<p style=\"color:red\">styled</p>";
$r = call('create_post', ['slug' => $slug, 'title' => 'MCP Test Post', 'content' => $md, 'content_format' => 'markdown',
    'categories' => [$catA, $catB], 'tags' => ['t1'], 'status' => 'published', 'published_at' => '2026-01-02',
    'excerpt' => 'Excerpt', 'seo' => ['title' => 'SEO T', 'keywords' => 'k1, k2']]);
check('create_post (markdown, categories, published)', $r['ok'] && ($r['data']['status'] ?? '') === 'published', $r['text']);
check('  returns public_url + preview_url', !empty($r['data']['public_url']) && !empty($r['data']['preview_url']));
check('  categories are objects with id/slug/name_snapshot', isset($r['data']['categories'][0]['id'], $r['data']['categories'][0]['slug'], $r['data']['categories'][0]['name_snapshot']) && $r['data']['categories'][0]['id'] !== null, json_encode($r['data']['categories'] ?? null));
check('  derived category string = first category', ($r['data']['category'] ?? '') === $catA);
check('  stripped reports style attribute', in_array('p[style]', $r['data']['stripped'] ?? [], true), json_encode($r['data']['stripped'] ?? null));
check('  next_steps present', !empty($r['data']['next_steps']));

$r = call('read_post', ['slug' => $slug]);
$post = $r['data']['post'] ?? [];
check('read_post: markdown converted to HTML', str_contains($post['content'] ?? '', '<h2>Section</h2>') && str_contains($post['content'] ?? '', '<strong>bold</strong>') && str_contains($post['content'] ?? '', '<ul><li>one</li>'));
check('  published_at honoured', ($post['published_at'] ?? '') === '2026-01-02', (string)($post['published_at'] ?? ''));
check('  seo flattened with title + keywords', ($post['seo']['title'] ?? '') === 'SEO T' && ($post['seo']['keywords'] ?? '') === 'k1, k2', json_encode($post['seo'] ?? null));
check('  public page renders (HTTP 200)', (function () use ($post) {
    if (empty($post['public_url'])) return false;
    $ch = curl_init($post['public_url']); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 15]);
    curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch); return $code === 200;
})(), (string)($post['public_url'] ?? ''));

// list filter by category NAME / slug
$r = call('list_posts', ['category' => $catA]);
check('list_posts filter by category name', count(array_filter($r['data']['posts'] ?? [], fn($p) => $p['slug'] === $slug)) === 1, 'count=' . ($r['data']['count'] ?? '?'));
$slugA = $post['categories'][0]['slug'] ?? '';
$r = call('list_posts', ['category' => $slugA]);
check('list_posts filter by category slug', count(array_filter($r['data']['posts'] ?? [], fn($p) => $p['slug'] === $slug)) === 1);

// categories listing has counts
$r = call('list_categories');
$catRec = null; foreach ($r['data']['categories'] ?? [] as $c) if ($c['name'] === $catA) $catRec = $c;
check('list_categories shows created category with post_count 1', $catRec !== null && ($catRec['post_count'] ?? 0) === 1, json_encode($catRec));

// update (html) → revision, stripped feedback, re-publish
$r = call('update_post', ['slug' => $slug, 'content' => '<p>v2 <span onclick="x()">x</span></p><script>bad()</script>', 'category' => $catB]);
check('update_post ok', $r['ok'] && ($r['data']['status'] ?? '') === 'published', $r['text']);
check('  stripped reports <script> and onclick', in_array('<script>', $r['data']['stripped'] ?? [], true) && in_array('span[onclick]', $r['data']['stripped'] ?? [], true), json_encode($r['data']['stripped'] ?? null));
check('  category switched to B (first)', ($r['data']['category'] ?? '') === $catB);

$r = call('list_post_revisions', ['slug' => $slug]);
$revs = $r['data']['revisions'] ?? [];
check('list_post_revisions has snapshots', count($revs) >= 1, 'count=' . count($revs));
$older = null;
foreach ($revs as $rv) { $older = $rv; } // oldest = the create snapshot (v1 content)
$r = call('restore_post_revision', ['slug' => $slug, 'timestamp' => $older['timestamp'] ?? '']);
check('restore_post_revision ok', $r['ok'], $r['text']);
$r = call('read_post', ['slug' => $slug]);
check('  content rolled back to markdown version', str_contains($r['data']['post']['content'] ?? '', '<h2>Section</h2>'));
check('  still published after restore', ($r['data']['post']['status'] ?? '') === 'published');

// category CRUD
$r = call('create_category', ['name' => $catA]);
check('create_category rejects duplicate name', !$r['ok'] || ($r['data']['success'] ?? true) === false);
$r = call('create_category', ['name' => "MCP Child {$suffix}", 'parent' => $catA, 'description' => 'child']);
check('create_category nested under parent', $r['ok'] && ($r['data']['category']['parent_id'] ?? null) === ($catRec['id'] ?? 'x'));
$childId = $r['data']['category']['id'] ?? '';
$r = call('update_category', ['id_or_slug' => $childId, 'name' => "MCP Child Renamed {$suffix}", 'parent' => '']);
check('update_category rename + move to root', $r['ok'] && array_key_exists('parent_id', $r['data']['category'] ?? []) && $r['data']['category']['parent_id'] === null && str_starts_with($r['data']['category']['name'] ?? '', 'MCP Child Renamed'));
$r = call('update_category', ['id_or_slug' => $catRec['id'] ?? '', 'name' => "{$catA} Renamed"]);
check('update_category renames parent', $r['ok']);
$r = call('read_post', ['slug' => $slug]);
$names = array_column($r['data']['post']['categories'] ?? [], 'name_snapshot');
check('  rename swept into post name_snapshot', in_array("{$catA} Renamed", $names, true), json_encode($names));
$r = call('delete_category', ['id_or_slug' => $childId]);
check('delete_category (child)', $r['ok'] && !empty($r['data']['deleted']));
$r = call('delete_category', ['id_or_slug' => $catRec['id'] ?? '']);
check('delete_category removes it from posts', $r['ok'] && ($r['data']['posts_touched'] ?? 0) === 1, $r['text']);
$r = call('read_post', ['slug' => $slug]);
$names = array_column($r['data']['post']['categories'] ?? [], 'name_snapshot');
check('  post now only has category B', $names === [$catB], json_encode($names));

// create_missing=false keeps slug-only refs, no new category
$r = call('create_post', ['slug' => $slug . '-b', 'title' => 'No create', 'content' => '<p>x</p>', 'categories' => ["Ghost {$suffix}"], 'create_missing' => false]);
check('create_post create_missing=false → slug-only category', $r['ok'] && array_key_exists('id', $r['data']['categories'][0] ?? []) && $r['data']['categories'][0]['id'] === null && ($r['data']['category'] ?? '') === "Ghost {$suffix}", $r['text']);
check('  draft has preview_url and no public_url', !empty($r['data']['preview_url']) && empty($r['data']['public_url']));

// cleanup
call('delete_post', ['slug' => $slug]);
call('delete_post', ['slug' => $slug . '-b']);
$r = call('list_categories');
foreach ($r['data']['categories'] ?? [] as $c) {
    if (str_contains($c['name'], $suffix)) call('delete_category', ['id_or_slug' => $c['id']]);
}
$r = call('list_posts');
check('cleanup: test posts removed', count(array_filter($r['data']['posts'] ?? [], fn($p) => str_starts_with($p['slug'], 'mcptest-'))) === 0);

echo $failures === 0 ? "\nAll post-tool checks passed.\n" : "\n{$failures} check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
