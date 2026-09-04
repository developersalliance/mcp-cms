#!/usr/bin/env php
<?php
/**
 * Media tools test — exercises the media library MCP tools end to end
 * against a local install:
 *
 *   php tests/media-tools.php <mcp-endpoint-url> <mcp-token> <fixture-image-path> [fixture-base-url]
 *
 * fixture-base-url (optional) is an http server that serves the directory of
 * the fixture image (e.g. `php -S 127.0.0.1:8084 -t /path/to/fixtures`); it is
 * used for upload_image_from_url. Because that server is on a loopback
 * address, the install under test must have `'mcp_allow_private_urls' => true`
 * in config/config.php (never on a real site).
 *
 * Also asserts the SSRF guard refuses loopback / metadata hosts when the flag
 * is off — run once WITHOUT the flag to cover that (pass "--ssrf-only").
 *
 * Read/write: creates and deletes its own images only. Exit 0 = all passed.
 */

$url = $argv[1] ?? '';
$token = $argv[2] ?? '';
$fixture = $argv[3] ?? '';
$fixtureBase = $argv[4] ?? '';
$ssrfOnly = in_array('--ssrf-only', $argv, true);
if ($url === '' || $token === '' || ($fixture === '' && !$ssrfOnly)) {
    fwrite(STDERR, "Usage: php tests/media-tools.php <endpoint> <token> <fixture-image> [fixture-base-url] [--ssrf-only]\n");
    exit(2);
}

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $failures;
    if (!$ok) $failures++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? '  — ' . mb_substr($detail, 0, 160) : '') . "\n";
}

function call(string $tool, array $args): array {
    global $url, $token;
    static $id = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['jsonrpc' => '2.0', 'id' => ++$id, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args ?: new stdClass()]]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CMS-MCP-TOKEN: ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $rpc = json_decode((string)$raw, true);
    $text = $rpc['result']['content'][0]['text'] ?? '';
    $data = json_decode($text, true);
    $isError = !empty($rpc['result']['isError']);
    return ['ok' => !$isError && is_array($data) && ($data['success'] ?? false), 'data' => is_array($data) ? $data : [], 'text' => $text, 'rpc' => $rpc];
}

echo "Media tools test → {$url}\n";

// --- SSRF guard (works with or without the private-url flag: metadata IPs are always refused... unless flag on) ---
$r = call('upload_image_from_url', ['url' => 'file:///etc/passwd']);
check('file:// URL refused', !$r['ok'], $r['text']);
$r = call('upload_image_from_url', ['url' => 'ftp://example.com/x.jpg']);
check('ftp:// URL refused', !$r['ok'], $r['text']);
$r = call('upload_image_from_url', ['url' => 'http://user:pw@example.com/x.jpg']);
check('URL with credentials refused', !$r['ok'], $r['text']);
if ($ssrfOnly) {
    $r = call('upload_image_from_url', ['url' => 'http://127.0.0.1:1/x.jpg']);
    check('loopback host refused (flag off)', !$r['ok'] && stripos($r['text'], 'private') !== false, $r['text']);
    $r = call('upload_image_from_url', ['url' => 'http://169.254.169.254/latest/meta-data/']);
    check('metadata host refused (flag off)', !$r['ok'] && stripos($r['text'], 'private') !== false, $r['text']);
    $r = call('upload_image_from_url', ['url' => 'http://localhost/x.jpg']);
    check('localhost refused (flag off)', !$r['ok'], $r['text']);
    echo $failures === 0 ? "\nSSRF checks passed.\n" : "\n{$failures} check(s) FAILED.\n";
    exit($failures === 0 ? 0 : 1);
}

// --- upload_image (base64) with metadata ---
$b64 = base64_encode(file_get_contents($fixture));
$r = call('upload_image', ['data' => $b64, 'filename' => basename($fixture), 'alt' => 'Test photo alt', 'name' => 'Office team photo', 'caption' => 'Cap']);
check('upload_image succeeds', $r['ok'], $r['text']);
$u1 = $r['data'];
check('upload_image returns flat url/thumb_url/width/height/html', !empty($u1['url']) && !empty($u1['thumb_url']) && !empty($u1['width']) && !empty($u1['html']), json_encode(array_keys($u1)));
check('upload_image html carries alt', strpos($u1['html'] ?? '', 'alt="Test photo alt"') !== false, $u1['html'] ?? '');
check('upload_image catalogued with name', ($u1['name'] ?? '') === 'Office team photo', $u1['name'] ?? '');

// --- list_media ---
$r = call('list_media', []);
check('list_media succeeds', $r['ok'] && isset($r['data']['media']), $r['text']);
$found = array_values(array_filter($r['data']['media'] ?? [], fn($m) => ($m['url'] ?? '') === ($u1['url'] ?? '-')));
check('list_media contains the upload with alt + name', $found && $found[0]['alt'] === 'Test photo alt' && $found[0]['name'] === 'Office team photo', json_encode($found[0] ?? null));
check('list_media items expose no filesystem paths', strpos($r['text'], '/Users/') === false && strpos($r['text'], '/var/www') === false);
$r = call('list_media', ['query' => 'office team']);
check('list_media search by name', ($r['data']['count'] ?? 0) >= 1 && ($r['data']['media'][0]['url'] ?? '') === $u1['url'], $r['text']);
$r = call('list_media', ['query' => 'zzz-no-such-picture']);
check('list_media no-match returns empty', $r['ok'] && ($r['data']['count'] ?? -1) === 0, $r['text']);

// --- update_media ---
$r = call('update_media', ['url' => $u1['url'], 'alt' => 'Renamed alt', 'caption' => 'New caption']);
check('update_media succeeds', $r['ok'] && ($r['data']['media']['alt'] ?? '') === 'Renamed alt', $r['text']);
$r = call('list_media', ['query' => 'renamed alt']);
check('update_media reflected in search by alt', ($r['data']['count'] ?? 0) >= 1, $r['text']);
$r = call('update_media', ['url' => '/assets/content/nope.jpg', 'alt' => 'x']);
check('update_media unknown url → error', !$r['ok'], $r['text']);

// --- upload_image_from_url ---
if ($fixtureBase !== '') {
    $r = call('upload_image_from_url', ['url' => rtrim($fixtureBase, '/') . '/' . basename($fixture), 'alt' => 'From URL', 'name' => 'Linked picture']);
    check('upload_image_from_url succeeds (private flag on)', $r['ok'], $r['text']);
    $u2 = $r['data'];
    check('upload_image_from_url returns url/html/source_url', !empty($u2['url']) && !empty($u2['html']) && !empty($u2['source_url']), json_encode(array_keys($u2)));
    check('upload_image_from_url resized to configured max', ($u2['width'] ?? 0) <= 1920 && ($u2['height'] ?? 0) <= 1080, ($u2['width'] ?? '?') . 'x' . ($u2['height'] ?? '?'));
    $r = call('upload_image_from_url', ['url' => rtrim($fixtureBase, '/') . '/not-an-image.txt']);
    check('upload_image_from_url non-image rejected', !$r['ok'], $r['text']);
    $r = call('upload_image_from_url', ['url' => rtrim($fixtureBase, '/') . '/does-not-exist.jpg']);
    check('upload_image_from_url 404 rejected', !$r['ok'], $r['text']);
    if (!empty($u2['url'])) {
        $r = call('delete_media', ['url' => $u2['url']]);
        check('delete_media (url upload) succeeds', $r['ok'] && ($r['data']['deleted'] ?? 0) >= 2, $r['text']);
    }
}

// --- generate_image error path (no live provider call expected on a bare install) ---
$r = call('generate_image', ['prompt' => 'a red circle']);
check('generate_image without provider → clear error', !$r['ok'] && (stripos($r['text'], 'provider') !== false), $r['text']);
$r = call('generate_image', ['prompt' => '']);
check('generate_image empty prompt → error', !$r['ok'], $r['text']);

// --- delete_media ---
$r = call('delete_media', ['url' => $u1['url']]);
check('delete_media removes files', $r['ok'] && ($r['data']['deleted'] ?? 0) >= 2, $r['text']);
$r = call('list_media', ['query' => 'renamed alt']);
check('deleted image gone from index', ($r['data']['count'] ?? -1) === 0, $r['text']);
$r = call('delete_media', ['url' => '/etc/passwd']);
check('delete_media outside uploads refused', !$r['ok'], $r['text']);

echo $failures === 0 ? "\nAll media checks passed.\n" : "\n{$failures} check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
