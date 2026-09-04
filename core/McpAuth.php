<?php
/**
 * McpAuth — resolves the caller of an MCP request to a principal.
 *
 * Two credential types:
 *   - the install-wide static token (config['mcp_token']) sent as
 *     X-CMS-MCP-TOKEN or Authorization: Bearer — acts as the site owner;
 *   - an OAuth 2.1 access token issued by mcp/oauth/ (see OAuthServer) —
 *     acts as the CMS user who approved the connection, with that user's
 *     role capabilities.
 *
 * Returns a principal array:
 *   ['type' => 'static'|'oauth', 'user' => string, 'role' => string,
 *    'capabilities' => '*'|string[], 'client' => ?string, 'token_id' => ?string]
 */

class McpAuth
{
    /** Read the raw credential from the request (header or bearer). */
    public static function readCredential(): array
    {
        $token = $_SERVER['HTTP_X_CMS_MCP_TOKEN'] ?? '';
        $source = 'header';
        if ($token === '') {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
            if ($authHeader === '' && function_exists('apache_request_headers')) {
                // Apache (mod_php / php-fpm without CGIPassAuth) drops Authorization
                // from $_SERVER; the raw request headers still carry it.
                foreach (apache_request_headers() as $hName => $hVal) {
                    if (strcasecmp($hName, 'Authorization') === 0) { $authHeader = $hVal; break; }
                }
            }
            if (preg_match('/^Bearer\s+(.+)$/i', trim((string)$authHeader), $m)) {
                $token = trim($m[1]);
                $source = 'bearer';
            }
        }
        return ['token' => (string)$token, 'source' => $source];
    }

    /**
     * Authenticate the current request. Returns a principal or null.
     */
    public static function authenticate(array $config): ?array
    {
        $cred = self::readCredential();
        $token = $cred['token'];
        if ($token === '') {
            return null;
        }

        // 1. Static install token → owner
        $expected = (string)($config['mcp_token'] ?? '');
        if ($expected !== '' && hash_equals($expected, $token)) {
            return [
                'type' => 'static',
                'user' => 'mcp-token',
                'role' => 'owner',
                'capabilities' => '*',
                'client' => null,
                'token_id' => null,
            ];
        }

        // 2. OAuth access token (only when the OAuth server is installed)
        $oauthFile = __DIR__ . '/OAuthServer.php';
        if (is_file($oauthFile)) {
            require_once $oauthFile;
            if (class_exists('OAuthServer')) {
                $server = new OAuthServer($config);
                $principal = $server->resolveAccessToken($token);
                if ($principal) {
                    return $principal;
                }
            }
        }
        return null;
    }

    /**
     * Send the 401 challenge. Includes the RFC 9728 resource_metadata pointer
     * so OAuth-capable clients (ChatGPT, Claude.ai, Gemini Spark) can discover
     * the authorization server without a root-level /.well-known/ route.
     */
    public static function challenge(array $config, string $error = 'invalid_token'): void
    {
        http_response_code(401);
        $base = self::endpointBaseUrl($config);
        $meta = $base . '/oauth/protected-resource.php';
        header('WWW-Authenticate: Bearer realm="cms-mcp", error="' . $error . '", resource_metadata="' . $meta . '"');
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized (invalid MCP token or OAuth access token)']);
        exit;
    }

    /** Absolute URL of the mcp/ directory, e.g. https://site/cms/mcp */
    public static function endpointBaseUrl(array $config): string
    {
        $base = rtrim((string)($config['base_url'] ?? ''), '/');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        return $base . '/cms/mcp';
    }

    /**
     * Can this principal call this tool? Static token: always. OAuth users:
     * the tool's capability (from getMCPToolCapabilities()) must be in the
     * user's role capabilities.
     */
    public static function canUseTool(array $principal, string $tool): bool
    {
        if (($principal['capabilities'] ?? null) === '*') return true;
        $map = function_exists('getMCPToolCapabilities') ? getMCPToolCapabilities() : [];
        $needed = $map[$tool] ?? null;
        if ($needed === null) return true;           // read-only / unmapped tools
        $caps = is_array($principal['capabilities'] ?? null) ? $principal['capabilities'] : [];
        return in_array($needed, $caps, true);
    }
}
