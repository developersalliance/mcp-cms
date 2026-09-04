#!/usr/bin/env php
<?php
/**
 * End-to-end test of the MCP OAuth 2.1 flow against a running install.
 *
 * Usage: php tests/oauth-flow.php <base-url> <admin-username> <admin-password>
 *   e.g. php tests/oauth-flow.php http://127.0.0.1:8082 admin secret
 *
 * Steps: discovery (401 pointer, RFC 9728, RFC 8414 both well-known forms),
 * dynamic client registration, authorize (admin login via cookie jar, consent
 * approve), token exchange with PKCE, tools/list with the bearer token,
 * refresh rotation (old refresh token rejected), revoke check, and negative
 * cases (bad PKCE, reused code, deny).
 * Exit 0 = all checks passed. Leaves one revoked grant behind (cleaned up).
 */

[$_, $base, $user, $pass] = array_pad($argv, 4, '');
if ($base === '' || $user === '' || $pass === '') {
    fwrite(STDERR, "Usage: php tests/oauth-flow.php <base-url> <admin-username> <admin-password>\n");
    exit(2);
}
$base = rtrim($base, '/');
$mcp = $base . '/cms/mcp/index.php';
$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $failures;
    if (!$ok) $failures++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? '  — ' . $detail : '') . "\n";
}

/** @return array{status:int,headers:array<string,string>,body:string,json:mixed} */
function http(string $method, string $url, $body = null, array $headers = [], ?string $cookieJar = null, bool $follow = false): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
    if ($cookieJar) { $opts[CURLOPT_COOKIEJAR] = $cookieJar; $opts[CURLOPT_COOKIEFILE] = $cookieJar; }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) return ['status' => 0, 'headers' => [], 'body' => curl_error($ch), 'json' => null];
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $hdrBlock = substr($raw, 0, $hs);
    $bodyStr = substr($raw, $hs);
    $hdrs = [];
    // keep the LAST header block (redirect chains)
    foreach (array_filter(explode("\r\n\r\n", trim($hdrBlock))) as $block) {
        $hdrs = [];
        foreach (explode("\r\n", $block) as $line) {
            if (strpos($line, ':') !== false) { [$k, $v] = explode(':', $line, 2); $hdrs[strtolower(trim($k))] = trim($v); }
        }
    }
    return ['status' => $status, 'headers' => $hdrs, 'body' => $bodyStr, 'json' => json_decode($bodyStr, true)];
}
function b64url(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function rpc(string $method, array $params = [], $id = 1): string {
    $m = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method]; if ($params) $m['params'] = $params; return json_encode($m);
}

echo "OAuth flow test → {$base}\n";

// 1. 401 challenge points at protected resource metadata
$r = http('POST', $mcp, rpc('ping'), ['Content-Type: application/json']);
$www = $r['headers']['www-authenticate'] ?? '';
check('unauthenticated call → 401 with WWW-Authenticate', $r['status'] === 401 && $www !== '', 'HTTP ' . $r['status']);
preg_match('/resource_metadata="([^"]+)"/', $www, $m);
$prmUrl = $m[1] ?? '';
check('WWW-Authenticate carries resource_metadata', $prmUrl !== '', $www);

// 2. RFC 9728
$r = http('GET', $prmUrl);
$prm = $r['json'];
check('protected resource metadata is JSON with authorization_servers', is_array($prm) && !empty($prm['authorization_servers'][0]), substr($r['body'], 0, 100));
check('resource == MCP endpoint URL', ($prm['resource'] ?? '') === $mcp, (string)($prm['resource'] ?? ''));
$issuer = (string)($prm['authorization_servers'][0] ?? '');

// 3. RFC 8414 (both forms)
$meta = null;
foreach (['/.well-known/oauth-authorization-server', '/.well-known/openid-configuration'] as $wk) {
    $r = http('GET', $issuer . $wk);
    $ok = is_array($r['json']) && ($r['json']['issuer'] ?? '') === $issuer;
    check("issuer{$wk} → metadata with matching issuer", $ok, 'HTTP ' . $r['status']);
    if ($ok) $meta = $r['json'];
}
// root-level form (only works when the web server rewrite is installed)
$issPath = parse_url($issuer, PHP_URL_PATH);
$r = http('GET', $base . '/.well-known/oauth-authorization-server' . $issPath);
$rootOk = is_array($r['json']) && (($r['json']['issuer'] ?? '') === $issuer);
echo "  info root-level /.well-known/oauth-authorization-server{$issPath} → " . ($rootOk ? 'served (root rewrite installed)' : 'not served (no root rewrite; path-appended form is used)') . "\n";
if (!$meta) { echo "\nCannot continue without metadata.\n"; exit(1); }
check('metadata advertises S256 + authorization_code + registration_endpoint',
    in_array('S256', $meta['code_challenge_methods_supported'] ?? [], true)
    && in_array('authorization_code', $meta['grant_types_supported'] ?? [], true)
    && !empty($meta['registration_endpoint']));

// 4. Dynamic client registration
$redirectUri = 'http://localhost:43111/callback';
$r = http('POST', $meta['registration_endpoint'], json_encode([
    'client_name' => 'oauth-flow test', 'redirect_uris' => [$redirectUri], 'token_endpoint_auth_method' => 'none',
    'grant_types' => ['authorization_code', 'refresh_token'],
]), ['Content-Type: application/json']);
$client = $r['json'];
check('DCR → 201 with client_id', $r['status'] === 201 && !empty($client['client_id']), 'HTTP ' . $r['status'] . ' ' . substr($r['body'], 0, 120));
$clientId = (string)($client['client_id'] ?? '');
$r = http('POST', $meta['registration_endpoint'], json_encode(['client_name' => 'bad', 'redirect_uris' => ['http://evil.example/cb']]), ['Content-Type: application/json']);
check('DCR rejects non-loopback http redirect', $r['status'] === 400 && ($r['json']['error'] ?? '') === 'invalid_redirect_uri');

// 5. Authorize: unauthenticated → login redirect
$verifier = b64url(random_bytes(48));
$challenge = b64url(hash('sha256', $verifier, true));
$state = bin2hex(random_bytes(8));
$authUrl = $meta['authorization_endpoint'] . '?' . http_build_query([
    'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirectUri,
    'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => $state, 'scope' => 'cms', 'resource' => $mcp,
]);
$jar = tempnam(sys_get_temp_dir(), 'oauthjar');
$r = http('GET', $authUrl, null, [], $jar);
$loc = $r['headers']['location'] ?? '';
check('authorize without session → 302 to admin login with redirect param', $r['status'] === 302 && str_contains($loc, '/cms/admin/login.php?redirect='), 'HTTP ' . $r['status'] . ' ' . $loc);

// log in (POST to login.php with the redirect carried)
$loginUrl = $base . '/cms/admin/login.php';
$r = http('POST', $loginUrl, http_build_query(['username' => $user, 'password' => $pass, 'redirect' => parse_url($authUrl, PHP_URL_PATH) . '?' . parse_url($authUrl, PHP_URL_QUERY)]), ['Content-Type: application/x-www-form-urlencoded'], $jar);
$loc = $r['headers']['location'] ?? '';
check('login → 302 back to authorize', $r['status'] === 302 && str_contains($loc, '/cms/mcp/oauth/authorize.php'), 'HTTP ' . $r['status'] . ' ' . $loc);

// consent page
$r = http('GET', $authUrl, null, [], $jar);
check('consent page renders (200, names the client)', $r['status'] === 200 && str_contains($r['body'], 'oauth-flow test'), 'HTTP ' . $r['status']);
preg_match('/name="csrf_token" value="([^"]+)"/', $r['body'], $m);
$csrf = $m[1] ?? '';
check('consent page has CSRF token', $csrf !== '');

// deny first
$fields = ['csrf_token' => $csrf, 'client_id' => $clientId, 'redirect_uri' => $redirectUri, 'state' => $state,
    'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'scope' => 'cms', 'resource' => $mcp, 'response_type' => 'code'];
$r = http('POST', $meta['authorization_endpoint'], http_build_query($fields + ['decision' => 'deny']), ['Content-Type: application/x-www-form-urlencoded'], $jar);
$loc = $r['headers']['location'] ?? '';
check('deny → redirect with error=access_denied', $r['status'] === 302 && str_contains($loc, 'error=access_denied') && str_contains($loc, 'state=' . $state), $loc);

// approve
$r = http('POST', $meta['authorization_endpoint'], http_build_query($fields + ['decision' => 'approve']), ['Content-Type: application/x-www-form-urlencoded'], $jar);
$loc = $r['headers']['location'] ?? '';
parse_str((string)parse_url($loc, PHP_URL_QUERY), $q);
check('approve → redirect with code + state', $r['status'] === 302 && !empty($q['code']) && ($q['state'] ?? '') === $state, $loc);
$code = (string)($q['code'] ?? '');

// wrong redirect_uri must not redirect at all
$r = http('GET', $meta['authorization_endpoint'] . '?' . http_build_query(['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => 'https://attacker.example/cb', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'x']), null, [], $jar);
check('unregistered redirect_uri → 400 page, no redirect', $r['status'] === 400 && empty($r['headers']['location']));

// 6. Token exchange
$tokenHdr = ['Content-Type: application/x-www-form-urlencoded'];
$r = http('POST', $meta['token_endpoint'], http_build_query(['grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => 'wrong-verifier-' . str_repeat('x', 40), 'redirect_uri' => $redirectUri, 'client_id' => $clientId]), $tokenHdr);
check('bad PKCE verifier → invalid_grant (code consumed)', $r['status'] === 400 && ($r['json']['error'] ?? '') === 'invalid_grant', substr($r['body'], 0, 120));

// get a fresh code (code was consumed by the failed attempt — single use)
$r = http('GET', $authUrl, null, [], $jar);
preg_match('/name="csrf_token" value="([^"]+)"/', $r['body'], $m); $fields['csrf_token'] = $m[1] ?? '';
$r = http('POST', $meta['authorization_endpoint'], http_build_query($fields + ['decision' => 'approve']), ['Content-Type: application/x-www-form-urlencoded'], $jar);
parse_str((string)parse_url($r['headers']['location'] ?? '', PHP_URL_QUERY), $q);
$code = (string)($q['code'] ?? '');
check('second approval issues a new code', $code !== '');

$r = http('POST', $meta['token_endpoint'], http_build_query(['grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $redirectUri, 'client_id' => $clientId]), $tokenHdr);
$tok = $r['json'];
check('token exchange → access_token + refresh_token (Bearer)', $r['status'] === 200 && !empty($tok['access_token']) && !empty($tok['refresh_token']) && ($tok['token_type'] ?? '') === 'Bearer', substr($r['body'], 0, 120));
check('Cache-Control: no-store on token response', str_contains(strtolower($r['headers']['cache-control'] ?? ''), 'no-store'));
$r = http('POST', $meta['token_endpoint'], http_build_query(['grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $redirectUri, 'client_id' => $clientId]), $tokenHdr);
check('reusing the code → invalid_grant', ($r['json']['error'] ?? '') === 'invalid_grant');

// 7. Use the bearer token on the MCP endpoint
$access = (string)($tok['access_token'] ?? '');
$r = http('POST', $mcp, rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'oauth-flow', 'version' => '1']]), ['Content-Type: application/json', 'Authorization: Bearer ' . $access]);
check('initialize with OAuth bearer → 200', $r['status'] === 200 && isset($r['json']['result']['protocolVersion']), 'HTTP ' . $r['status']);
$r = http('POST', $mcp, rpc('tools/list'), ['Content-Type: application/json', 'Authorization: Bearer ' . $access]);
$tools = $r['json']['result']['tools'] ?? [];
check('tools/list with OAuth bearer → tools', count($tools) > 0, count($tools) . ' tools');
$r = http('POST', $mcp, rpc('tools/call', ['name' => 'get_usage_tips', 'arguments' => new stdClass()]), ['Content-Type: application/json', 'Authorization: Bearer ' . $access]);
check('tools/call with OAuth bearer works', ($r['json']['result']['content'][0]['type'] ?? '') === 'text' && empty($r['json']['result']['isError']));
$r = http('POST', $mcp, rpc('ping'), ['Content-Type: application/json', 'Authorization: Bearer ' . str_repeat('0', 64)]);
check('unknown bearer → 401', $r['status'] === 401);

// 8. Refresh rotation
$refresh = (string)($tok['refresh_token'] ?? '');
$r = http('POST', $meta['token_endpoint'], http_build_query(['grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $clientId]), $tokenHdr);
$tok2 = $r['json'];
check('refresh → new access + refresh tokens', $r['status'] === 200 && !empty($tok2['access_token']) && !empty($tok2['refresh_token']) && $tok2['refresh_token'] !== $refresh, substr($r['body'], 0, 120));
$r = http('POST', $meta['token_endpoint'], http_build_query(['grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $clientId]), $tokenHdr);
check('old refresh token rejected after rotation', ($r['json']['error'] ?? '') === 'invalid_grant');
$r = http('POST', $meta['token_endpoint'], http_build_query(['grant_type' => 'refresh_token', 'refresh_token' => $tok2['refresh_token'], 'client_id' => 'c_someoneelse']), $tokenHdr);
check('refresh with another client_id → invalid_client', $r['status'] === 401 && ($r['json']['error'] ?? '') === 'invalid_client');
$r = http('POST', $meta['token_endpoint'], http_build_query(['grant_type' => 'password', 'client_id' => $clientId]), $tokenHdr);
check('unsupported grant_type rejected', ($r['json']['error'] ?? '') === 'unsupported_grant_type');
$r = http('POST', $mcp, rpc('ping'), ['Content-Type: application/json', 'Authorization: Bearer ' . $tok2['access_token']]);
check('new access token works on MCP', $r['status'] === 200);

// 8b. Role enforcement: a tool the role may not use is refused (owner: only a tool-level error)
$r = http('POST', $mcp, rpc('tools/call', ['name' => 'restore_global_backup', 'arguments' => ['backup_id' => 'none']]), ['Content-Type: application/json', 'Authorization: Bearer ' . $tok2['access_token']]);
$msg = (string)($r['json']['result']['content'][0]['text'] ?? '');
check('restricted tool → isError result (not a crash)', ($r['json']['result']['isError'] ?? false) === true, mb_substr($msg, 0, 80));
echo "  info " . (str_contains($msg, 'role does not allow') ? 'role enforcement refused the call (non-owner user)' : 'owner: tool ran and reported its own error') . "\n";
// 8c. REST mode (?tool=) must enforce the same role rules
$r = http('POST', $mcp . '?tool=restore_global_backup', json_encode(['backup_id' => 'none']), ['Content-Type: application/json', 'Authorization: Bearer ' . $tok2['access_token']]);
if (str_contains($msg, 'role does not allow')) {
    check('REST ?tool= refused for a restricted role (403)', $r['status'] === 403, 'HTTP ' . $r['status']);
} else {
    check('REST ?tool= reachable with an OAuth token (owner)', in_array($r['status'], [200, 400], true), 'HTTP ' . $r['status']);
}
$r = http('POST', $mcp . '?tool=read_post', json_encode(['slug' => '../../config/users']), ['Content-Type: application/json', 'Authorization: Bearer ' . $tok2['access_token']]);
check('slug traversal rejected', $r['status'] !== 200 || str_contains((string)$r['body'], 'Invalid slug'), substr((string)$r['body'], 0, 80));

// 9. Revoke via admin page (Connected apps) — needs settings.manage (owner/admin)
$r = http('GET', $base . '/cms/admin/mcp-config.php', null, [], $jar);
if ($r['status'] !== 200) {
    echo "  info admin MCP page not available to this user (HTTP {$r['status']}); skipping revoke checks (run as an owner to cover them)\n";
    @unlink($jar);
    echo $failures === 0 ? "\nAll OAuth checks passed.\n" : "\n{$failures} check(s) FAILED.\n";
    exit($failures === 0 ? 0 : 1);
}
preg_match('/name="csrf_token" value="([^"]+)"/', $r['body'], $m); $csrf = $m[1] ?? '';
preg_match_all('/name="grant_id" value="([^"]+)"/', $r['body'], $gm);
$grantIds = $gm[1] ?? [];
check('admin Connected apps lists the grant', str_contains($r['body'], 'oauth-flow test') && $grantIds !== [], count($grantIds) . ' grant(s)');
foreach ($grantIds as $gid) {
    http('POST', $base . '/cms/admin/mcp-config.php', http_build_query(['csrf_token' => $csrf, 'action' => 'revoke_grant', 'grant_id' => $gid]), ['Content-Type: application/x-www-form-urlencoded'], $jar);
}
$r = http('POST', $mcp, rpc('ping'), ['Content-Type: application/json', 'Authorization: Bearer ' . $tok2['access_token']]);
check('after revoke → access token rejected (401)', $r['status'] === 401, 'HTTP ' . $r['status']);
$r = http('POST', $meta['token_endpoint'], http_build_query(['grant_type' => 'refresh_token', 'refresh_token' => $tok2['refresh_token'], 'client_id' => $clientId]), $tokenHdr);
check('after revoke → refresh rejected', ($r['json']['error'] ?? '') === 'invalid_grant');

@unlink($jar);
echo $failures === 0 ? "\nAll OAuth checks passed.\n" : "\n{$failures} check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
