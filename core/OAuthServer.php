<?php
/**
 * OAuthServer — minimal OAuth 2.1 authorization server for MCP clients.
 *
 * Lets ChatGPT, Claude.ai, Claude Desktop, Gemini Spark/Enterprise, Cursor
 * and the MCP SDKs connect to /cms/mcp/index.php with just a URL: the client
 * discovers this server (RFC 9728 + RFC 8414), registers itself (RFC 7591 or
 * a Client ID Metadata Document), sends the user to the consent page (a CMS
 * admin login), and exchanges the code for a bearer token (PKCE S256 only).
 *
 * Everything is flat files under {cms_dir}/oauth/:
 *   clients/<id>.json   registered clients and cached CIMD documents
 *   codes/<sha256>.json  single-use authorization codes (10 min)
 *   tokens/<sha256>.json access + refresh tokens
 *
 * Only the sha256 of a credential is ever stored, never the credential.
 */

require_once __DIR__ . '/McpAuth.php';
require_once __DIR__ . '/Permissions.php';

class OAuthServer
{
    public const SCOPE = 'cms';
    public const CODE_TTL = 600;
    public const CIMD_CACHE_TTL = 86400;

    private array $config;
    private string $dir;

    public function __construct(array $config)
    {
        $this->config = $config;
        $cmsDir = rtrim((string)($config['cms_dir'] ?? dirname(__DIR__)), '/');
        $this->dir = $cmsDir . '/oauth';
    }

    // ------------------------------------------------------------ URLs

    /** https://site/cms/mcp */
    public function mcpBase(): string
    {
        return McpAuth::endpointBaseUrl($this->config);
    }

    /** https://site/cms/mcp/oauth (the issuer) */
    public function issuer(): string
    {
        return $this->mcpBase() . '/oauth';
    }

    /** The protected resource identifier: the MCP endpoint URL. */
    public function resource(): string
    {
        return $this->mcpBase() . '/index.php';
    }

    // ------------------------------------------------------------ metadata

    /** RFC 8414 authorization server metadata. */
    public function metadata(): array
    {
        $iss = $this->issuer();
        return [
            'issuer' => $iss,
            'authorization_endpoint' => $iss . '/authorize.php',
            'token_endpoint' => $iss . '/token.php',
            'registration_endpoint' => $iss . '/register.php',
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post'],
            'scopes_supported' => [self::SCOPE],
            'client_id_metadata_document_supported' => true,
            'service_documentation' => $this->mcpBase() . '/../admin/mcp-config.php',
            // OpenID Provider Metadata fields. The MCP SDKs validate the
            // {issuer}/.well-known/openid-configuration form against the OIDC
            // schema, which requires these even though no id_tokens are issued.
            'jwks_uri' => $iss . '/jwks.php',
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
        ];
    }

    /** RFC 9728 protected resource metadata. */
    public function protectedResourceMetadata(): array
    {
        return [
            'resource' => $this->resource(),
            'authorization_servers' => [$this->issuer()],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => [self::SCOPE],
            'resource_name' => (string)($this->config['site_name'] ?? 'CMS') . ' MCP',
        ];
    }

    // ------------------------------------------------------------ clients

    /**
     * RFC 7591 dynamic client registration.
     * @throws InvalidArgumentException with an RFC 7591 error code as message
     */
    public function registerClient(array $req): array
    {
        $redirects = $req['redirect_uris'] ?? null;
        if (!is_array($redirects) || $redirects === []) {
            throw new InvalidArgumentException('invalid_redirect_uri');
        }
        $clean = [];
        foreach ($redirects as $uri) {
            if (!is_string($uri) || !self::redirectUriShapeOk($uri)) {
                throw new InvalidArgumentException('invalid_redirect_uri');
            }
            $clean[] = $uri;
        }
        $authMethod = (string)($req['token_endpoint_auth_method'] ?? 'none');
        if (!in_array($authMethod, ['none', 'client_secret_post'], true)) {
            throw new InvalidArgumentException('invalid_client_metadata');
        }
        $grantTypes = array_values(array_intersect(
            is_array($req['grant_types'] ?? null) ? $req['grant_types'] : ['authorization_code', 'refresh_token'],
            ['authorization_code', 'refresh_token']
        ));
        if (!in_array('authorization_code', $grantTypes, true)) {
            $grantTypes[] = 'authorization_code';
        }
        $name = trim((string)($req['client_name'] ?? ''));
        $name = $name === '' ? 'MCP client' : mb_substr(strip_tags($name), 0, 120);

        $client = [
            'client_id' => 'c_' . bin2hex(random_bytes(16)),
            'client_id_issued_at' => time(),
            'client_name' => $name,
            'redirect_uris' => $clean,
            'token_endpoint_auth_method' => $authMethod,
            'grant_types' => $grantTypes,
            'response_types' => ['code'],
            'scope' => self::SCOPE,
            'kind' => 'dcr',
        ];
        // Optional metadata only when present: clients validate the echoed
        // registration response and reject null where a string is expected.
        $clientUri = self::httpsOrNull($req['client_uri'] ?? null);
        if ($clientUri !== null) $client['client_uri'] = $clientUri;
        if ($authMethod === 'client_secret_post') {
            $secret = bin2hex(random_bytes(32));
            $client['client_secret'] = $secret;
            $client['client_secret_expires_at'] = 0;
            $stored = $client;
            $stored['client_secret_hash'] = hash('sha256', $secret);
            unset($stored['client_secret']);
        } else {
            $stored = $client;
        }
        $this->writeJson($this->clientPath($client['client_id']), $stored);
        return $client;
    }

    /**
     * Look up a client: a registered id, or a Client ID Metadata Document
     * (client_id is an https URL that serves its own metadata).
     */
    public function getClient(string $clientId): ?array
    {
        if ($clientId === '') return null;
        if (preg_match('~^https://~i', $clientId)) {
            return $this->fetchClientMetadataDocument($clientId);
        }
        if (!preg_match('/^[A-Za-z0-9_\-]{3,80}$/', $clientId)) return null;
        $c = $this->readJson($this->clientPath($clientId));
        return is_array($c) ? $c : null;
    }

    private function fetchClientMetadataDocument(string $url): ?array
    {
        $cachePath = $this->dir . '/clients/cimd-' . hash('sha256', $url) . '.json';
        $cached = $this->readJson($cachePath);
        if (is_array($cached) && ($cached['fetched_at'] ?? 0) > time() - self::CIMD_CACHE_TTL) {
            return $cached;
        }
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || !empty($parts['fragment'])) {
            return null;
        }
        $body = $this->httpGet($url, 65536, 5);
        if ($body === null) {
            return is_array($cached) ? $cached : null; // stale cache beats nothing
        }
        $doc = json_decode($body, true);
        if (!is_array($doc) || ($doc['client_id'] ?? null) !== $url || !is_array($doc['redirect_uris'] ?? null)) {
            return null;
        }
        $redirects = [];
        foreach ($doc['redirect_uris'] as $u) {
            if (is_string($u) && self::redirectUriShapeOk($u)) $redirects[] = $u;
        }
        if ($redirects === []) return null;
        $client = [
            'client_id' => $url,
            'client_name' => mb_substr(strip_tags((string)($doc['client_name'] ?? parse_url($url, PHP_URL_HOST))), 0, 120),
            'client_uri' => self::httpsOrNull($doc['client_uri'] ?? null),
            'redirect_uris' => $redirects,
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'kind' => 'cimd',
            'fetched_at' => time(),
        ];
        $this->writeJson($cachePath, $client);
        return $client;
    }

    /** Exact match, except loopback redirects where the port is ignored (RFC 8252 §7.3). */
    public static function redirectUriAllowed(array $client, string $uri): bool
    {
        foreach ($client['redirect_uris'] ?? [] as $registered) {
            if ($registered === $uri) return true;
            if (self::isLoopback($registered) && self::isLoopback($uri)) {
                $a = parse_url($registered); $b = parse_url($uri);
                if ($a && $b
                    && strtolower($a['scheme'] ?? '') === strtolower($b['scheme'] ?? '')
                    && strtolower($a['host'] ?? '') === strtolower($b['host'] ?? '')
                    && ($a['path'] ?? '/') === ($b['path'] ?? '/')) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function isLoopback(string $uri): bool
    {
        $p = parse_url($uri);
        if (!$p || strtolower($p['scheme'] ?? '') !== 'http') return false;
        return in_array(strtolower($p['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    }

    /** https://… anywhere, or http:// only on loopback hosts. */
    public static function redirectUriShapeOk(string $uri): bool
    {
        $p = parse_url($uri);
        if (!$p || empty($p['host']) || !empty($p['fragment'])) return false;
        $scheme = strtolower($p['scheme'] ?? '');
        if ($scheme === 'https') return true;
        return $scheme === 'http' && self::isLoopback($uri);
    }

    // ------------------------------------------------------------ codes

    /**
     * Issue a single-use authorization code.
     * @param array $grant client_id, client_name, redirect_uri, code_challenge, user, role, scope, resource
     */
    public function issueCode(array $grant): string
    {
        $this->sweep('codes');
        $code = bin2hex(random_bytes(32));
        $this->writeJson($this->codePath($code), $grant + [
            'issued_at' => time(),
            'expires_at' => time() + self::CODE_TTL,
        ]);
        return $code;
    }

    /** Consume (delete) a code; returns its grant or null when unknown/expired. */
    public function consumeCode(string $code): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $code)) return null;
        $path = $this->codePath($code);
        $data = $this->readJson($path);
        if (is_file($path)) @unlink($path);
        if (!is_array($data) || ($data['expires_at'] ?? 0) < time()) return null;
        return $data;
    }

    public static function pkceMatches(string $verifier, string $challenge): bool
    {
        if (!preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier)) return false;
        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return hash_equals($challenge, $computed);
    }

    // ------------------------------------------------------------ tokens

    public function accessTtl(): int
    {
        return max(60, (int)($this->config['mcp_oauth_access_ttl'] ?? 3600));
    }

    public function refreshTtl(): int
    {
        return max(3600, (int)($this->config['mcp_oauth_refresh_ttl'] ?? 30 * 86400));
    }

    /**
     * Mint an access + refresh token pair for a grant.
     * @param array $grant user, role, client_id, client_name, scope, grant_id?
     */
    public function issueTokens(array $grant): array
    {
        $this->sweep('tokens');
        $grantId = $grant['grant_id'] ?? ('g_' . bin2hex(random_bytes(8)));
        $now = time();
        $base = [
            'grant_id' => $grantId,
            'user' => (string)$grant['user'],
            'role' => (string)$grant['role'],
            'client_id' => (string)$grant['client_id'],
            'client_name' => (string)($grant['client_name'] ?? ''),
            'scope' => (string)($grant['scope'] ?? self::SCOPE),
            'issued_at' => $now,
        ];
        $access = bin2hex(random_bytes(32));
        $refresh = bin2hex(random_bytes(32));
        $this->writeJson($this->tokenPath($access), $base + [
            'kind' => 'access', 'id' => substr(hash('sha256', $access), 0, 12), 'expires_at' => $now + $this->accessTtl(),
        ]);
        $this->writeJson($this->tokenPath($refresh), $base + [
            'kind' => 'refresh', 'id' => substr(hash('sha256', $refresh), 0, 12), 'expires_at' => $now + $this->refreshTtl(),
        ]);
        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTtl(),
            'refresh_token' => $refresh,
            'scope' => $base['scope'],
        ];
    }

    /** Rotate a refresh token. Returns a new token response or null when invalid. */
    public function refresh(string $refreshToken, string $clientId): ?array
    {
        $rec = $this->readToken($refreshToken);
        if (!$rec || ($rec['kind'] ?? '') !== 'refresh') return null;
        if (($rec['client_id'] ?? '') !== $clientId) return null;
        @unlink($this->tokenPath($refreshToken));
        // The user may have been deleted or demoted since the grant was made.
        $role = $this->currentRole($rec['user']);
        if ($role === null) return null;
        $rec['role'] = $role;
        return $this->issueTokens($rec);
    }

    /** Principal for McpAuth, or null. */
    public function resolveAccessToken(string $token): ?array
    {
        $rec = $this->readToken($token);
        if (!$rec || ($rec['kind'] ?? '') !== 'access') return null;
        $role = $this->currentRole($rec['user']);
        if ($role === null) return null;
        $perms = new Permissions($this->rolesFile());
        return [
            'type' => 'oauth',
            'user' => $rec['user'],
            'role' => $role,
            'capabilities' => $role === 'owner' ? '*' : $perms->roleCapabilities($role),
            'client' => $rec['client_name'] ?: $rec['client_id'],
            'token_id' => $rec['id'] ?? null,
            'grant_id' => $rec['grant_id'] ?? null,
        ];
    }

    /** Active grants for the admin "Connected apps" list, newest first. */
    public function listGrants(): array
    {
        $this->sweep('tokens');
        $grants = [];
        foreach (glob($this->dir . '/tokens/*.json') ?: [] as $f) {
            $t = $this->readJson($f);
            if (!is_array($t)) continue;
            $g = $t['grant_id'] ?? 'unknown';
            if (!isset($grants[$g])) {
                $grants[$g] = [
                    'grant_id' => $g,
                    'client_name' => $t['client_name'] ?: $t['client_id'],
                    'client_id' => $t['client_id'],
                    'user' => $t['user'],
                    'role' => $t['role'],
                    'issued_at' => $t['issued_at'],
                    'access_expires_at' => null,
                    'refresh_expires_at' => null,
                ];
            }
            $grants[$g]['issued_at'] = min($grants[$g]['issued_at'], $t['issued_at']);
            $key = ($t['kind'] === 'refresh') ? 'refresh_expires_at' : 'access_expires_at';
            $grants[$g][$key] = max((int)$grants[$g][$key], (int)$t['expires_at']);
        }
        usort($grants, fn($a, $b) => $b['issued_at'] <=> $a['issued_at']);
        return array_values($grants);
    }

    /** Delete every token of a grant. Returns how many files were removed. */
    public function revokeGrant(string $grantId): int
    {
        $n = 0;
        foreach (glob($this->dir . '/tokens/*.json') ?: [] as $f) {
            $t = $this->readJson($f);
            if (is_array($t) && ($t['grant_id'] ?? null) === $grantId) {
                if (@unlink($f)) $n++;
            }
        }
        return $n;
    }

    /** Delete every token issued to a user (call when a user is removed). */
    public function revokeUser(string $user): int
    {
        $n = 0;
        foreach (glob($this->dir . '/tokens/*.json') ?: [] as $f) {
            $t = $this->readJson($f);
            if (is_array($t) && ($t['user'] ?? null) === $user) {
                if (@unlink($f)) $n++;
            }
        }
        return $n;
    }

    // ------------------------------------------------------------ users

    /** Current role of a CMS user from users.json, or null if the user is gone. */
    public function currentRole(string $username): ?string
    {
        $usersFile = rtrim((string)($this->config['cms_dir'] ?? dirname(__DIR__)), '/') . '/config/users.json';
        $data = $this->readJson($usersFile);
        $users = is_array($data['users'] ?? null) ? $data['users'] : (is_array($data) ? $data : []);
        foreach ($users as $u) {
            if (is_array($u) && ($u['username'] ?? null) === $username) {
                return (string)($u['role'] ?? 'viewer');
            }
        }
        return null;
    }

    private function rolesFile(): string
    {
        return rtrim((string)($this->config['cms_dir'] ?? dirname(__DIR__)), '/') . '/config/roles.json';
    }

    // ------------------------------------------------------------ storage

    private function clientPath(string $id): string
    {
        return $this->dir . '/clients/' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $id) . '.json';
    }

    private function codePath(string $code): string
    {
        return $this->dir . '/codes/' . hash('sha256', $code) . '.json';
    }

    private function tokenPath(string $token): string
    {
        return $this->dir . '/tokens/' . hash('sha256', $token) . '.json';
    }

    private function readToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $rec = $this->readJson($this->tokenPath($token));
        if (!is_array($rec)) return null;
        if (($rec['expires_at'] ?? 0) < time()) {
            @unlink($this->tokenPath($token));
            return null;
        }
        return $rec;
    }

    /** Remove expired records in a subdir; cheap and only every ~50th call. */
    private function sweep(string $sub): void
    {
        if (random_int(1, 50) !== 1) return;
        foreach (glob($this->dir . '/' . $sub . '/*.json') ?: [] as $f) {
            $d = $this->readJson($f);
            if (!is_array($d) || ($d['expires_at'] ?? PHP_INT_MAX) < time()) {
                @unlink($f);
            }
        }
    }

    private function readJson(string $path): mixed
    {
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        return $raw === false ? null : json_decode($raw, true);
    }

    private function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('OAuth storage directory is not writable');
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            throw new RuntimeException('OAuth storage is not writable');
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('OAuth storage is not writable');
        }
    }

    private function httpGet(string $url, int $maxBytes, int $timeout): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_USERAGENT => 'cms-mcp-oauth/1.0',
                CURLOPT_BUFFERSIZE => 16384,
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => function ($res, $dlTotal, $dl) use ($maxBytes) { return $dl > $maxBytes ? 1 : 0; },
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($body === false || $status !== 200 || strlen($body) > $maxBytes) return null;
            return $body;
        }
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'header' => "Accept: application/json\r\nUser-Agent: cms-mcp-oauth/1.0\r\n", 'follow_location' => 0]]);
        $body = @file_get_contents($url, false, $ctx, 0, $maxBytes + 1);
        if ($body === false || strlen($body) > $maxBytes) return null;
        return $body;
    }

    private static function httpsOrNull($v): ?string
    {
        return (is_string($v) && preg_match('~^https://~i', $v)) ? mb_substr($v, 0, 300) : null;
    }
}
