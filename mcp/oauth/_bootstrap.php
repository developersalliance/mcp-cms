<?php
/**
 * Shared bootstrap for the OAuth endpoints: config, server instance, CORS,
 * no-store caching and a JSON helper. Included by every file in mcp/oauth/.
 */

$config = require __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/OAuthServer.php';

$oauth = new OAuthServer($config);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization, MCP-Protocol-Version');
header('Access-Control-Max-Age: 86400');
header('Cache-Control: no-store');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function oauthJson($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function oauthError(string $error, string $description = '', int $status = 400): void
{
    $body = ['error' => $error];
    if ($description !== '') $body['error_description'] = $description;
    oauthJson($body, $status);
}

/** Parse a JSON or form-encoded request body into an array. */
function oauthRequestBody(): array
{
    $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($ct, 'application/json')) {
        $raw = file_get_contents('php://input');
        $d = json_decode((string)$raw, true);
        return is_array($d) ? $d : [];
    }
    return $_POST;
}
