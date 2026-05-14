<?php
/**
 * MCP HTTP Endpoint - AI-only API for ChatGPT, Claude, etc.
 *
 * Supports two formats:
 * 1. REST format (ChatGPT Desktop): POST /cms/mcp/index.php?tool=<tool_name> with JSON body
 * 2. JSON-RPC 2.0 format (Claude Code): POST with {"jsonrpc":"2.0","method":"tools/call",...}
 *
 * Authentication: X-CMS-MCP-TOKEN header
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

// Set JSON response header
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

// Handle CORS if needed
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
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
$token = $_SERVER['HTTP_X_CMS_MCP_TOKEN'] ?? '';
$expectedToken = (string)($config['mcp_token'] ?? '');
if ($token === '' || $expectedToken === '' || !hash_equals($expectedToken, (string)$token)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized (invalid MCP token)']);
    exit;
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

// Verify POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Parse JSON request body
$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true);

// Detect request format: JSON-RPC 2.0 or REST
$isJsonRpc = isset($jsonInput['jsonrpc']) && $jsonInput['jsonrpc'] === '2.0';
$jsonRpcId = $jsonInput['id'] ?? null;

// Helper function for JSON-RPC error responses
function jsonRpcError($code, $message, $id = null) {
    echo json_encode([
        'jsonrpc' => '2.0',
        'error' => [
            'code' => $code,
            'message' => $message
        ],
        'id' => $id
    ]);
    exit;
}

// Helper function for JSON-RPC success responses
function jsonRpcSuccess($result, $id = null) {
    echo json_encode([
        'jsonrpc' => '2.0',
        'result' => $result,
        'id' => $id
    ]);
    exit;
}

// Handle JSON-RPC 2.0 format
if ($isJsonRpc) {
    $method = $jsonInput['method'] ?? '';
    $params = $jsonInput['params'] ?? [];

    // Handle initialize method (MCP handshake)
    if ($method === 'initialize') {
        jsonRpcSuccess([
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => new stdClass()
            ],
            'serverInfo' => [
                'name' => 'cms-mcp',
                'version' => '1.0.0'
            ]
        ], $jsonRpcId);
    }

    // Handle initialized notification (no response needed, but acknowledge)
    if ($method === 'notifications/initialized' || $method === 'initialized') {
        jsonRpcSuccess(new stdClass(), $jsonRpcId);
    }

    // Handle tools/list method (discovery)
    if ($method === 'tools/list') {
        $toolsDefinition = getMCPToolsWithSchema();
        $tools = [];
        foreach ($toolsDefinition as $name => $def) {
            $tools[] = [
                'name' => $name,
                'description' => $def['description'],
                'inputSchema' => $def['inputSchema'] ?? ['type' => 'object', 'properties' => new stdClass()]
            ];
        }
        jsonRpcSuccess(['tools' => $tools], $jsonRpcId);
    }

    // Handle tools/call method
    if ($method === 'tools/call') {
        $tool = $params['name'] ?? '';
        $input = $params['arguments'] ?? [];

        if (!$tool) {
            jsonRpcError(-32602, 'Missing tool name in params', $jsonRpcId);
        }

        // Check if tool is allowed
        $allowedTools = $config['mcp_allowed_tools'] ?? array_keys(getMCPTools());
        if (!in_array($tool, $allowedTools)) {
            jsonRpcError(-32601, "Tool '{$tool}' is not allowed. Check MCP permissions in settings.", $jsonRpcId);
        }

        // Tool will be executed below with $tool and $input set
    } else if ($method !== 'tools/call') {
        jsonRpcError(-32601, "Method not found: {$method}", $jsonRpcId);
    }
} else {
    // REST format (ChatGPT Desktop)
    $tool = $_GET['tool'] ?? '';
    if (!$tool) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing tool parameter']);
        exit;
    }

    // Check if tool is allowed based on permissions
    $allowedTools = $config['mcp_allowed_tools'] ?? array_keys(getMCPTools());
    if (!in_array($tool, $allowedTools)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => "Tool '{$tool}' is not allowed. Check MCP permissions in settings."]);
        exit;
    }

    $input = $jsonInput;
    if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON in request body']);
        exit;
    }
}

// Output wrapper - handles both REST and JSON-RPC responses
function outputResult($data, $isJsonRpc, $jsonRpcId) {
    if ($isJsonRpc) {
        // Convert REST response to JSON-RPC format
        if (isset($data['success']) && $data['success'] === false) {
            echo json_encode([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32000,
                    'message' => $data['error'] ?? 'Unknown error'
                ],
                'id' => $jsonRpcId
            ]);
        } else {
            // Wrap successful response in content array for MCP protocol
            echo json_encode([
                'jsonrpc' => '2.0',
                'result' => [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => json_encode($data, JSON_PRETTY_PRINT)
                        ]
                    ]
                ],
                'id' => $jsonRpcId
            ]);
        }
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


// Load dispatch table and route
require_once __DIR__ . '/handlers.php';

$handlers = getMcpHandlers($pageManager, $blockParser, $backupManager, $globalBackupManager, $blogManager, $uploadManager, $authorManager, $config, $isJsonRpc, $jsonRpcId);

try {
    if (!isset($handlers[$tool])) {
        outputResult(['success' => false, 'error' => 'Unknown tool: ' . $tool], $isJsonRpc, $jsonRpcId);
    }

    $result = $handlers[$tool]($input);

    if ($result !== null) {
        outputResult($result, $isJsonRpc, $jsonRpcId);
    }
} catch (Exception $e) {
    outputResult(['success' => false, 'error' => sanitizeMcpError($e->getMessage())], $isJsonRpc, $jsonRpcId);
}
