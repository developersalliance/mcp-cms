#!/usr/bin/env php
<?php
/**
 * MCP endpoint smoke test — protocol-level checks any MCP client relies on
 * (Claude Code, Gemini CLI, Cursor, the official SDKs).
 *
 * Usage:
 *   php tests/mcp-smoke.php https://example.com/cms/mcp/index.php <mcp_token>
 *
 * Exit code 0 = all checks passed. Read-only: only list/ping/initialize and
 * a deliberately-missing read_post are called; nothing is written.
 */

$url   = $argv[1] ?? '';
$token = $argv[2] ?? '';
if ($url === '' || $token === '') {
    fwrite(STDERR, "Usage: php tests/mcp-smoke.php <endpoint-url> <mcp-token>\n");
    exit(2);
}

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $failures;
    if (!$ok) $failures++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? '  — ' . $detail : '') . "\n";
}

/** @return array{status:int, headers:array<string,string>, body:string, json:mixed} */
function post(string $url, string $token, string $body, array $extraHeaders = []): array {
    $ch = curl_init($url);
    $headers = array_merge([
        'Content-Type: application/json',
        'Accept: application/json, text/event-stream',
        'X-CMS-MCP-TOKEN: ' . $token,
    ], $extraHeaders);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        return ['status' => 0, 'headers' => [], 'body' => curl_error($ch), 'json' => null];
    }
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headerBlock = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    $hdrs = [];
    foreach (explode("\r\n", $headerBlock) as $line) {
        if (strpos($line, ':') !== false) {
            [$k, $v] = explode(':', $line, 2);
            $hdrs[strtolower(trim($k))] = trim($v);
        }
    }
    return ['status' => $status, 'headers' => $hdrs, 'body' => $body, 'json' => json_decode($body, true)];
}

function rpc(string $method, array $params = [], $id = 1): string {
    $msg = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
    if ($params !== []) $msg['params'] = $params;
    return json_encode($msg);
}

echo "MCP smoke test → {$url}\n";

// 1. initialize + version negotiation
$r = post($url, $token, rpc('initialize', [
    'protocolVersion' => '2025-06-18',
    'capabilities' => new stdClass(),
    'clientInfo' => ['name' => 'mcp-smoke', 'version' => '1.0'],
]));
$res = $r['json']['result'] ?? null;
check('initialize returns 200 JSON', $r['status'] === 200 && is_array($res), 'HTTP ' . $r['status']);
check('protocolVersion negotiated to 2025-06-18', ($res['protocolVersion'] ?? '') === '2025-06-18', (string)($res['protocolVersion'] ?? 'none'));
check('capabilities.tools advertised', isset($res['capabilities']['tools']));
check('serverInfo.name present', !empty($res['serverInfo']['name']));
check('Content-Type is application/json', str_starts_with($r['headers']['content-type'] ?? '', 'application/json'), $r['headers']['content-type'] ?? '');

// Unknown protocol version → server picks its latest
$r = post($url, $token, rpc('initialize', ['protocolVersion' => '1999-01-01', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'x', 'version' => '0']]));
check('unsupported protocolVersion falls back to a supported one', in_array($r['json']['result']['protocolVersion'] ?? '', ['2025-06-18', '2025-03-26', '2024-11-05'], true));

// 2. notifications/initialized → 202, empty body
$r = post($url, $token, json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
check('notifications/initialized → HTTP 202 with empty body', $r['status'] === 202 && trim($r['body']) === '', 'HTTP ' . $r['status'] . ' body=' . substr($r['body'], 0, 40));

// 3. ping
$r = post($url, $token, rpc('ping', [], 'p1'));
check('ping → {} with matching id', $r['status'] === 200 && ($r['json']['id'] ?? null) === 'p1' && isset($r['json']['result']) && !isset($r['json']['error']));

// 4. optional capability lists answered gracefully
foreach (['resources/list' => 'resources', 'prompts/list' => 'prompts'] as $m => $key) {
    $r = post($url, $token, rpc($m));
    check("$m → list (may be empty), not an error", isset($r['json']['result'][$key]) && is_array($r['json']['result'][$key]) && !isset($r['json']['error']));
}

// 5. tools/list + schema hygiene (Gemini rejects several JSON-Schema shapes)
$r = post($url, $token, rpc('tools/list'));
$tools = $r['json']['result']['tools'] ?? [];
check('tools/list returns tools', count($tools) > 0, count($tools) . ' tools');
$schemaProblems = [];
$walk = function ($schema, string $path) use (&$walk, &$schemaProblems) {
    if (!is_array($schema)) return;
    foreach (['$schema', 'additionalProperties', 'default', 'examples', 'const', 'oneOf', 'anyOf', 'allOf', '$ref'] as $bad) {
        if (array_key_exists($bad, $schema)) $schemaProblems[] = "$path uses $bad";
    }
    $type = $schema['type'] ?? null;
    if ($type === 'object' && !array_key_exists('properties', $schema)) $schemaProblems[] = "$path object without properties";
    if ($type === 'array' && !array_key_exists('items', $schema)) $schemaProblems[] = "$path array without items";
    if (isset($schema['required']) && $schema['required'] === []) $schemaProblems[] = "$path has empty required";
    foreach (($schema['properties'] ?? []) as $k => $v) $walk($v, "$path.$k");
    if (isset($schema['items'])) $walk($schema['items'], $path . '[]');
};
foreach ($tools as $t) {
    if (!isset($t['name'], $t['description'], $t['inputSchema'])) { $schemaProblems[] = 'tool missing name/description/inputSchema'; continue; }
    if (($t['inputSchema']['type'] ?? '') !== 'object') $schemaProblems[] = $t['name'] . ' inputSchema.type != object';
    $walk($t['inputSchema'], $t['name']);
}
check('all inputSchemas are Gemini/Claude-safe', $schemaProblems === [], implode('; ', array_slice($schemaProblems, 0, 5)));

// 6. tools/call success shape
$r = post($url, $token, rpc('tools/call', ['name' => 'get_usage_tips', 'arguments' => new stdClass()]));
$content = $r['json']['result']['content'][0] ?? null;
check('tools/call get_usage_tips → content[] text block', ($content['type'] ?? '') === 'text' && !empty($content['text']) && empty($r['json']['result']['isError']));

// 7. tools/call tool-level failure → isError result, not a JSON-RPC error
$r = post($url, $token, rpc('tools/call', ['name' => 'read_post', 'arguments' => ['slug' => 'mcp-smoke-does-not-exist-' . time()]]));
check('tool failure surfaces as result.isError=true', ($r['json']['result']['isError'] ?? false) === true && !isset($r['json']['error']), substr($r['body'], 0, 120));

// 8. unknown tool / unknown method → JSON-RPC errors
$r = post($url, $token, rpc('tools/call', ['name' => 'no_such_tool', 'arguments' => new stdClass()]));
check('unknown tool → JSON-RPC error -32602', ($r['json']['error']['code'] ?? 0) === -32602);
$r = post($url, $token, rpc('no/such/method'));
check('unknown method → JSON-RPC error -32601', ($r['json']['error']['code'] ?? 0) === -32601);

// 9. parse error
$r = post($url, $token, '{not json');
check('invalid JSON → -32700', ($r['json']['error']['code'] ?? 0) === -32700);

// 10. batch
$r = post($url, $token, json_encode([
    ['jsonrpc' => '2.0', 'id' => 'b1', 'method' => 'ping'],
    ['jsonrpc' => '2.0', 'method' => 'notifications/progress'],
    ['jsonrpc' => '2.0', 'id' => 'b2', 'method' => 'tools/call', 'params' => ['name' => 'get_usage_tips', 'arguments' => new stdClass()]],
]));
$batch = $r['json'];
check('batch → array of 2 responses (notification skipped)', is_array($batch) && array_is_list($batch) && count($batch) === 2, substr($r['body'], 0, 80));

// 11. auth
$r = post($url, 'wrong-token', rpc('ping'));
check('wrong token → 401', $r['status'] === 401);
$r = post($url, '', rpc('ping'), ['Authorization: Bearer ' . $token]);
check('Authorization: Bearer <token> accepted', $r['status'] === 200 && isset($r['json']['result']));

// 12. GET (SSE probe) → 405
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['X-CMS-MCP-TOKEN: ' . $token, 'Accept: text/event-stream']]);
curl_exec($ch);
$getStatus = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
check('GET → 405 (no SSE stream offered)', $getStatus === 405, 'HTTP ' . $getStatus);

echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
