<?php
/**
 * MCP HTTP Endpoint - AI-only API for Claude, Gemini, ChatGPT, etc.
 *
 * Supports two formats:
 * 1. REST format (ChatGPT Desktop): POST /cms/mcp/index.php?tool=<tool_name> with JSON body
 * 2. JSON-RPC 2.0 / MCP Streamable HTTP (stateless): POST with
 *    {"jsonrpc":"2.0","method":"tools/call",...}. This is what Claude Code,
 *    Gemini CLI, Cursor and the official MCP SDK clients speak.
 *
 * MCP transport notes (stateless Streamable HTTP):
 *  - Every response is application/json (no SSE stream is offered; GET → 405).
 *  - Notifications (no "id") are acknowledged with HTTP 202 and an empty body.
 *  - JSON-RPC batches (array bodies) are processed and answered as an array.
 *  - protocolVersion is negotiated against SUPPORTED_PROTOCOL_VERSIONS.
 *  - No Mcp-Session-Id is issued; the token authenticates every request.
 *
 * Authentication: X-CMS-MCP-TOKEN header (or Authorization: Bearer <token>)
 * Response: JSON (format depends on request type)
 */

// Load configuration and core classes
$config = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/tools-definition.php';
require_once __DIR__ . '/../core/BlockParser.php';
require_once __DIR__ . '/../core/PageManager.php';
require_once __DIR__ . '/../core/PageSettings.php';
require_once __DIR__ . '/../core/BackupManager.php';
require_once __DIR__ . '/../core/GlobalBackupManager.php';
require_once __DIR__ . '/../core/BlogManager.php';
require_once __DIR__ . '/../core/AuthorManager.php';
require_once __DIR__ . '/../core/UploadManager.php';

const MCP_SUPPORTED_PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];
const MCP_SERVER_VERSION = '1.1.0';

// CORS + content type on every response (browser-based MCP clients send a
// preflight because of the custom token header).
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-CMS-MCP-TOKEN, Mcp-Session-Id, MCP-Protocol-Version');
header('Access-Control-Expose-Headers: Mcp-Session-Id, MCP-Protocol-Version');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json');

/**
 * Strip absolute filesystem paths from exception messages before they leave
 * the server. Underlying errors (mkdir, file_put_contents, etc.) often
 * embed the full path, which leaks server layout to the JSON-RPC caller.
 * error_log() calls are NOT wrapped — server-side logs keep full paths.
 *
 * $cfg defaults to null and falls back to the top-level $config global, so
 * page-handler functions (which don't receive $config in their signature)
 * can still call this without plumbing config through every handler.
 */
function sanitizeMcpError(string $msg, ?array $cfg = null): string {
    if ($cfg === null) {
        global $config;
        $cfg = is_array($config ?? null) ? $config : [];
    }
    $msg = str_replace($cfg['root_dir'] ?? '', '<root>', $msg);
    $msg = str_replace($cfg['cms_dir']  ?? '', '<cms>',  $msg);
    return $msg;
}

// CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// IP Whitelisting check
if (!empty($config['mcp_ip_whitelist'])) {
    $allowedIps = array_map('trim', explode(',', $config['mcp_ip_whitelist']));
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

    if (!in_array($clientIp, $allowedIps)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied - IP not whitelisted']);
        exit;
    }
}

// Verify authentication BEFORE rate-limit accounting so unauth requests
// cannot exhaust per-IP counters or touch the rate-limit JSON file.
// McpAuth accepts the static install token (X-CMS-MCP-TOKEN or Bearer)
// and OAuth 2.1 access tokens issued by mcp/oauth/.
require_once __DIR__ . '/../core/McpAuth.php';
require_once __DIR__ . '/../core/McpActivityLog.php';
$principal = McpAuth::authenticate($config);
if ($principal === null) {
    McpAuth::challenge($config);
}

// Rate limiting check (only for authenticated requests)
if ($config['mcp_rate_limit_enabled'] ?? false) {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateLimitFile = __DIR__ . '/../logs/rate_limit_' . md5($clientIp) . '.json';
    $now = time();
    $window = $config['mcp_rate_limit_window'] ?? 60;
    $maxRequests = $config['mcp_rate_limit_requests'] ?? 60;

    // Atomic read-modify-write under flock to avoid concurrent clobbering
    $fp = @fopen($rateLimitFile, 'c+');
    if ($fp && flock($fp, LOCK_EX)) {
        rewind($fp);
        $raw = stream_get_contents($fp);
        $requests = [];
        if ($raw !== false && $raw !== '') {
            $data = json_decode($raw, true);
            $requests = is_array($data['requests'] ?? null) ? $data['requests'] : [];
        }

        // Remove old requests outside the time window
        $requests = array_values(array_filter($requests, function($timestamp) use ($now, $window) {
            return ($now - $timestamp) < $window;
        }));

        // Check if limit exceeded
        if (count($requests) >= $maxRequests) {
            $retryAfter = $window - ($now - min($requests));
            flock($fp, LOCK_UN);
            fclose($fp);
            http_response_code(429);
            header('Retry-After: ' . max(1, (int)$retryAfter));
            echo json_encode([
                'success' => false,
                'error' => 'Rate limit exceeded',
                'retry_after' => $retryAfter
            ]);
            exit;
        }

        // Add current request and persist
        $requests[] = $now;
        $newRaw = json_encode(['requests' => $requests]);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $newRaw);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// Verify POST method. MCP clients may probe GET for an SSE stream and DELETE
// to end a session; 405 tells them neither is offered (stateless server).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Parse JSON request body
$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true);
$jsonParseFailed = ($jsonInput === null && json_last_error() !== JSON_ERROR_NONE);

// Detect request format: REST (?tool=) vs JSON-RPC 2.0 (everything else).
$restTool = $_GET['tool'] ?? '';
$isJsonRpc = ($restTool === '');

/**
 * Thrown by outputResult() while a JSON-RPC message is being dispatched so
 * the dispatcher (not the handler) decides how to emit the response. Lets
 * legacy handlers that call outputResult()+exit participate in batches.
 */
class McpResultSignal extends Exception {
    public $payload;
    public function __construct($payload) { parent::__construct('mcp-result'); $this->payload = $payload; }
}
$GLOBALS['mcpDispatching'] = false;

function jsonRpcErrorArray($code, $message, $id = null, $data = null): array {
    $err = ['code' => $code, 'message' => $message];
    if ($data !== null) $err['data'] = $data;
    return ['jsonrpc' => '2.0', 'error' => $err, 'id' => $id];
}

function jsonRpcSuccessArray($result, $id = null): array {
    return ['jsonrpc' => '2.0', 'result' => $result, 'id' => $id];
}

// Legacy helpers (still used by page-handlers.php) — emit a single response.
function jsonRpcError($code, $message, $id = null) {
    echo json_encode(jsonRpcErrorArray($code, $message, $id));
    exit;
}

function jsonRpcSuccess($result, $id = null) {
    echo json_encode(jsonRpcSuccessArray($result, $id));
    exit;
}

/**
 * Convert a handler's REST-shaped array into an MCP tools/call result.
 * Tool-level failures become { isError: true } results (spec-preferred) so
 * the model can read the message and retry; protocol errors stay JSON-RPC
 * errors.
 */
function mcpToolResult(array $data): array {
    $isError = isset($data['success']) && $data['success'] === false;
    $text = $isError
        ? (string)($data['error'] ?? 'Unknown error')
        : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $result = ['content' => [['type' => 'text', 'text' => $text]]];
    if ($isError) {
        $result['isError'] = true;
    }
    return $result;
}

// Output wrapper - handles both REST and JSON-RPC responses
function outputResult($data, $isJsonRpc, $jsonRpcId) {
    if ($isJsonRpc) {
        if (!empty($GLOBALS['mcpDispatching'])) {
            throw new McpResultSignal($data);
        }
        echo json_encode(jsonRpcSuccessArray(mcpToolResult(is_array($data) ? $data : ['result' => $data]), $jsonRpcId));
    } else {
        echo json_encode($data);
    }
    exit;
}

// Initialize managers
require_once __DIR__ . '/../core/SitemapGenerator.php';
$reservedFolders = $config['reserved_folders'] ?? ['cms'];
$pageSettings = new PageSettings($config['cms_dir'] . '/settings');
$sitemapGenerator = new SitemapGenerator($config['root_dir'], $config['base_url'] ?? 'http://localhost', $reservedFolders, $config['drafts_dir'] ?? null);
$pageManager = new PageManager($config['root_dir'], $reservedFolders, $config['drafts_dir'] ?? null, null, $sitemapGenerator, $pageSettings);
$blockParser = new BlockParser();
$backupManager = new BackupManager($config['backups_dir'], $config['max_backups_per_page']);
$globalBackupManager = new GlobalBackupManager($config['backups_dir']);
$blogBackupManager = new BackupManager($config['backups_dir'], $config['max_backups_per_page']);
$blogManager = new BlogManager($config['root_dir'], $config['cms_dir'], $sitemapGenerator, $blogBackupManager);
$authorManager = new AuthorManager($config['cms_dir'] . '/config');
$uploadManager = new UploadManager(
    $config['root_dir'],
    $config['uploads_dir'] ?? 'assets/content/',
    $config['image_thumbnail_width'] ?? 300,
    $config['image_thumbnail_height'] ?? 300,
    $config['image_full_width'] ?? 1920,
    $config['image_full_height'] ?? 1080
);

require_once __DIR__ . '/page-handlers.php';
require_once __DIR__ . '/handlers.php';

// Tool enablement: the admin grid saves an allow-list AND a deny-list. Tools
// that were added to the engine after the grid was last saved appear in
// neither, and are enabled — otherwise every engine update would silently
// hide its new tools on every install.
$allKnownTools = array_keys(getMCPTools());
$configAllowed = $config['mcp_allowed_tools'] ?? null;
$configDisabled = is_array($config['mcp_disabled_tools'] ?? null) ? $config['mcp_disabled_tools'] : [];
if (is_array($configAllowed)) {
    $gridKnown = array_merge($configAllowed, $configDisabled);
    $allowedTools = array_values(array_filter($allKnownTools, function ($t) use ($configAllowed, $gridKnown, $configDisabled) {
        if (in_array($t, $configDisabled, true)) return false;
        return in_array($t, $configAllowed, true) || !in_array($t, $gridKnown, true);
    }));
} else {
    $allowedTools = array_values(array_diff($allKnownTools, $configDisabled));
}

/**
 * Record write-type tool calls (and every failure) in the MCP activity log.
 * Read-only tools are skipped to keep the log about changes.
 */
function mcpLogToolCall(array $context, string $tool, array $input, array $result, float $seconds): void {
    $readOnly = function_exists('getMCPToolAnnotations')
        ? (bool)((getMCPToolAnnotations()[$tool]['readOnlyHint'] ?? false))
        : (bool)preg_match('/^(list_|read_|get_|search_)/', $tool);
    $ok = !(isset($result['success']) && $result['success'] === false);
    if ($readOnly && $ok) return;
    McpActivityLog::record($context['config'], [
        'principal' => ($context['principal']['type'] ?? '?') . ':' . ($context['principal']['user'] ?? '?'),
        'client' => $context['principal']['client'] ?? null,
        'tool' => $tool,
        'target' => McpActivityLog::summarizeArgs($input),
        'ok' => $ok,
        'error' => $ok ? null : mb_substr((string)($result['error'] ?? ''), 0, 200),
        'ms' => (int)round($seconds * 1000),
    ]);
}

// ---------------------------------------------------------------------------
// REST format (ChatGPT Desktop): POST ?tool=<name> with a plain JSON body
// ---------------------------------------------------------------------------
if (!$isJsonRpc) {
    if (!in_array($restTool, $allowedTools, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => "Tool '{$restTool}' is not allowed. Check MCP permissions in settings."]);
        exit;
    }
    if ($jsonParseFailed) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON in request body']);
        exit;
    }
    if (!McpAuth::canUseTool($principal, $restTool)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => "Your account's role does not allow '{$restTool}'."]);
        exit;
    }
    $input = is_array($jsonInput) ? $jsonInput : [];
    $handlers = getMcpHandlers($pageManager, $blockParser, $backupManager, $globalBackupManager, $blogManager, $uploadManager, $authorManager, $config, false, null);
    $restContext = ['config' => $config, 'principal' => $principal];
    $t0 = microtime(true);
    try {
        if (!isset($handlers[$restTool])) {
            outputResult(['success' => false, 'error' => 'Unknown tool: ' . $restTool], false, null);
        }
        $result = $handlers[$restTool]($input);
        if ($result === null) $result = ['success' => true];
        mcpLogToolCall($restContext, $restTool, $input, is_array($result) ? $result : ['result' => $result], microtime(true) - $t0);
        outputResult($result, false, null);
    } catch (Throwable $e) {
        $err = ['success' => false, 'error' => sanitizeMcpError($e->getMessage())];
        mcpLogToolCall($restContext, $restTool, $input, $err, microtime(true) - $t0);
        outputResult($err, false, null);
    }
    exit;
}

// ---------------------------------------------------------------------------
// JSON-RPC 2.0 / MCP
// ---------------------------------------------------------------------------
if ($jsonParseFailed) {
    http_response_code(400);
    echo json_encode(jsonRpcErrorArray(-32700, 'Parse error: invalid JSON', null));
    exit;
}

$isBatch = is_array($jsonInput) && array_is_list($jsonInput);
$messages = $isBatch ? $jsonInput : [$jsonInput];

if ($isBatch && count($messages) === 0) {
    http_response_code(400);
    echo json_encode(jsonRpcErrorArray(-32600, 'Invalid Request: empty batch', null));
    exit;
}

/**
 * Dispatch one JSON-RPC message. Returns a response array, or null for a
 * notification (no "id") which must not be answered.
 */
function mcpDispatch($msg, array $context): ?array {
    if (!is_array($msg) || ($msg['jsonrpc'] ?? null) !== '2.0' || !isset($msg['method']) || !is_string($msg['method'])) {
        return jsonRpcErrorArray(-32600, 'Invalid Request: expected a JSON-RPC 2.0 object with a "method"', is_array($msg) ? ($msg['id'] ?? null) : null);
    }
    $method = $msg['method'];
    $params = isset($msg['params']) && is_array($msg['params']) ? $msg['params'] : [];
    $isNotification = !array_key_exists('id', $msg);
    $id = $msg['id'] ?? null;

    // Notifications are acknowledged without a body (handled by the caller).
    if ($isNotification || str_starts_with($method, 'notifications/')) {
        return null;
    }

    switch ($method) {
        case 'initialize': {
            $requested = (string)($params['protocolVersion'] ?? '');
            $negotiated = in_array($requested, MCP_SUPPORTED_PROTOCOL_VERSIONS, true)
                ? $requested
                : MCP_SUPPORTED_PROTOCOL_VERSIONS[0];
            $siteName = (string)($context['config']['site_name'] ?? 'Site');
            return jsonRpcSuccessArray([
                'protocolVersion' => $negotiated,
                'capabilities' => array_merge(
                    ['tools' => ['listChanged' => false]],
                    function_exists('mcpPromptsList') ? ['prompts' => ['listChanged' => false]] : [],
                    function_exists('mcpResourcesList') ? ['resources' => ['subscribe' => false, 'listChanged' => false]] : []
                ),
                'serverInfo' => [
                    'name' => 'cms-mcp',
                    'title' => $siteName . ' CMS',
                    'version' => MCP_SERVER_VERSION,
                ],
                'instructions' => function_exists('getMCPServerInstructions')
                    ? getMCPServerInstructions($context['config'])
                    : 'Flat-file CMS for "' . $siteName . '". Start with list_pages or search_blocks; read_block before update_block; call get_usage_tips for recipes.',
            ], $id);
        }
        case 'ping':
            return jsonRpcSuccessArray(new stdClass(), $id);
        case 'resources/list':
            return function_exists('mcpResourcesList')
                ? jsonRpcSuccessArray(mcpResourcesList($context), $id)
                : jsonRpcSuccessArray(['resources' => []], $id);
        case 'resources/templates/list':
            return function_exists('mcpResourceTemplatesList')
                ? jsonRpcSuccessArray(mcpResourceTemplatesList($context), $id)
                : jsonRpcSuccessArray(['resourceTemplates' => []], $id);
        case 'resources/read':
            if (!function_exists('mcpResourcesRead')) {
                return jsonRpcErrorArray(-32601, 'Resources are not available on this server', $id);
            }
            try {
                return jsonRpcSuccessArray(mcpResourcesRead($context, (string)($params['uri'] ?? '')), $id);
            } catch (Throwable $e) {
                return jsonRpcErrorArray(-32602, sanitizeMcpError($e->getMessage()), $id);
            }
        case 'prompts/list':
            return function_exists('mcpPromptsList')
                ? jsonRpcSuccessArray(mcpPromptsList($context), $id)
                : jsonRpcSuccessArray(['prompts' => []], $id);
        case 'prompts/get':
            if (!function_exists('mcpPromptsGet')) {
                return jsonRpcErrorArray(-32601, 'Prompts are not available on this server', $id);
            }
            try {
                return jsonRpcSuccessArray(mcpPromptsGet($context, (string)($params['name'] ?? ''), is_array($params['arguments'] ?? null) ? $params['arguments'] : []), $id);
            } catch (Throwable $e) {
                return jsonRpcErrorArray(-32602, sanitizeMcpError($e->getMessage()), $id);
            }
        case 'logging/setLevel':
            return jsonRpcSuccessArray(new stdClass(), $id);
        case 'tools/list': {
            $tools = [];
            $annotations = function_exists('getMCPToolAnnotations') ? getMCPToolAnnotations() : [];
            foreach (getMCPToolsWithSchema() as $name => $def) {
                if (!in_array($name, $context['allowedTools'], true)) continue;
                if (!McpAuth::canUseTool($context['principal'], $name)) continue;
                $entry = [
                    'name' => $name,
                    'description' => $def['description'],
                    'inputSchema' => mcpNormalizeInputSchema($def['inputSchema'] ?? null),
                ];
                $ann = $def['annotations'] ?? ($annotations[$name] ?? null);
                if (is_array($ann) && $ann !== []) {
                    if (isset($ann['title'])) { $entry['title'] = $ann['title']; unset($ann['title']); }
                    if ($ann !== []) $entry['annotations'] = $ann;
                }
                $tools[] = $entry;
            }
            return jsonRpcSuccessArray(['tools' => $tools], $id);
        }
        case 'tools/call': {
            $tool = $params['name'] ?? '';
            $input = $params['arguments'] ?? [];
            if (!is_string($tool) || $tool === '') {
                return jsonRpcErrorArray(-32602, 'Invalid params: missing tool name', $id);
            }
            if (!is_array($input)) {
                return jsonRpcErrorArray(-32602, 'Invalid params: "arguments" must be an object', $id);
            }
            if (!in_array($tool, $context['allowedTools'], true)) {
                return jsonRpcErrorArray(-32602, "Unknown or disabled tool: {$tool}. Check MCP permissions in settings.", $id);
            }
            $handlers = $context['handlers'];
            if (!isset($handlers[$tool])) {
                return jsonRpcErrorArray(-32602, 'Unknown tool: ' . $tool, $id);
            }
            if (!McpAuth::canUseTool($context['principal'], $tool)) {
                $refused = ['success' => false, 'error' => "Your account's role does not allow '{$tool}'."];
                mcpLogToolCall($context, $tool, $input, $refused, 0.0);
                return jsonRpcSuccessArray(mcpToolResult($refused), $id);
            }
            $GLOBALS['mcpDispatching'] = true;
            $t0 = microtime(true);
            try {
                $result = $handlers[$tool]($input);
                if ($result === null) {
                    $result = ['success' => true];
                }
            } catch (McpResultSignal $sig) {
                $result = $sig->payload;
            } catch (Throwable $e) {
                $result = ['success' => false, 'error' => sanitizeMcpError($e->getMessage())];
            } finally {
                $GLOBALS['mcpDispatching'] = false;
            }
            $result = is_array($result) ? $result : ['result' => $result];
            mcpLogToolCall($context, $tool, $input, $result, microtime(true) - $t0);
            return jsonRpcSuccessArray(mcpToolResult($result), $id);
        }
        default:
            return jsonRpcErrorArray(-32601, "Method not found: {$method}", $id);
    }
}


if (is_file(__DIR__ . '/prompts-resources.php')) {
    require_once __DIR__ . '/prompts-resources.php';
}

$handlers = getMcpHandlers($pageManager, $blockParser, $backupManager, $globalBackupManager, $blogManager, $uploadManager, $authorManager, $config, true, null);
$context = [
    'config' => $config,
    'allowedTools' => $allowedTools,
    'handlers' => $handlers,
    'principal' => $principal,
    'managers' => [
        'pageManager' => $pageManager, 'blogManager' => $blogManager, 'authorManager' => $authorManager,
        'uploadManager' => $uploadManager, 'backupManager' => $backupManager,
    ],
];

$responses = [];
foreach ($messages as $msg) {
    $resp = mcpDispatch($msg, $context);
    if ($resp !== null) {
        $responses[] = $resp;
    }
}

if (count($responses) === 0) {
    // Only notifications: 202 Accepted, no body.
    http_response_code(202);
    exit;
}

echo json_encode($isBatch ? $responses : $responses[0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
