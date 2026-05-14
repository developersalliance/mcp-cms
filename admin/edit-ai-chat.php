<?php
/**
 * AI Editor — Chat Backend
 *
 * Proxies a conversation to Anthropic's Messages API with the CMS's MCP
 * tools exposed as tool_use definitions. Runs a tool execution loop:
 * when the assistant emits tool_use blocks, we run the matching handler
 * locally and feed back tool_result, until the assistant stops asking.
 *
 * Request JSON:
 *   { csrf_token, page_id, messages: [{role, content}, ...] }
 *
 * Response JSON:
 *   { assistant: "text", tool_calls: [{name, input, result_summary}],
 *     messages: [...full conversation incl. tool blocks],
 *     modified: bool, error?: string }
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/CSRF.php';
require_once __DIR__ . '/../core/BlockParser.php';
require_once __DIR__ . '/../core/PageManager.php';
require_once __DIR__ . '/../core/PageSettings.php';
require_once __DIR__ . '/../core/SitemapGenerator.php';
require_once __DIR__ . '/../core/BackupManager.php';
require_once __DIR__ . '/../core/GlobalBackupManager.php';
require_once __DIR__ . '/../core/BlogManager.php';
require_once __DIR__ . '/../core/AuthorManager.php';
require_once __DIR__ . '/../core/UploadManager.php';
require_once __DIR__ . '/../mcp/tools-definition.php';
require_once __DIR__ . '/../mcp/page-handlers.php';
require_once __DIR__ . '/../mcp/handlers.php';

$config = require __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

function jerr($code, $msg) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jerr(405, 'POST only');
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    jerr(400, 'Invalid JSON');
}

// CSRF
$token = $input['csrf_token'] ?? '';
if (!CSRF::validateToken($token)) {
    jerr(403, 'Invalid CSRF token');
}

$pageId = (string)($input['page_id'] ?? '');
$incoming = $input['messages'] ?? [];
if (!is_array($incoming) || count($incoming) === 0) {
    jerr(400, 'messages must be a non-empty array');
}
$attachedMedia = is_array($input['attached_media'] ?? null) ? $input['attached_media'] : [];

// Validate attached media URLs: must be same-origin (absolute or path) and
// resolve to an existing file under root_dir/assets. Any URL that fails
// either check is dropped with a warning included in the response.
$validatedMedia = [];
$mediaWarnings = [];
$rootDir = rtrim($config['root_dir'], '/');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$siteOrigin = $scheme . '://' . $host;
foreach ($attachedMedia as $m) {
    $url = is_array($m) ? (string)($m['url'] ?? '') : '';
    if ($url === '') continue;
    // Accept either /assets/... path or https://this-host/assets/...
    $pathOnly = $url;
    if (strpos($url, '://') !== false) {
        if (strpos($url, $siteOrigin) !== 0) {
            $mediaWarnings[] = 'Dropped non-same-origin attachment: ' . $url;
            continue;
        }
        $pathOnly = substr($url, strlen($siteOrigin));
    }
    if ($pathOnly === '' || $pathOnly[0] !== '/' || strpos($pathOnly, '..') !== false) {
        $mediaWarnings[] = 'Dropped malformed attachment: ' . $url;
        continue;
    }
    $diskPath = $rootDir . $pathOnly;
    $real = realpath($diskPath);
    $rootReal = realpath($rootDir);
    if (!$real || !$rootReal || strpos($real, $rootReal . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {
        $mediaWarnings[] = 'Dropped missing attachment: ' . $url;
        continue;
    }
    $validatedMedia[] = [
        'name' => is_array($m) ? (string)($m['name'] ?? basename($pathOnly)) : basename($pathOnly),
        'path' => $pathOnly,                          // relative for system prompt
        'absUrl' => $siteOrigin . $pathOnly,          // absolute for Anthropic image source
        'mime' => mime_content_type($real) ?: 'image/jpeg',
    ];
}

// AI config
$provider = $config['ai_provider'] ?? '';
$apiKey = $config['ai_api_key'] ?? '';
$model = $config['ai_model'] ?? 'claude-sonnet-4-6';
if ($provider !== 'anthropic') {
    jerr(400, 'Only ai_provider=anthropic is supported here. Configure it in /cms/admin/ai-settings.php');
}
if (!$apiKey) {
    jerr(400, 'ai_api_key is not configured');
}

// Wire managers (mirrors mcp/index.php)
$reservedFolders = $config['reserved_folders'] ?? ['cms'];
$pageSettings = new PageSettings($config['cms_dir'] . '/settings');
$sitemapGenerator = new SitemapGenerator($config['root_dir'], $config['base_url'] ?? 'http://localhost', $reservedFolders, $config['drafts_dir'] ?? null);
$pageManager = new PageManager($config['root_dir'], $reservedFolders, $config['drafts_dir'] ?? null, null, $sitemapGenerator, $pageSettings);
$blockParser = new BlockParser();
$backupManager = new BackupManager($config['backups_dir'], $config['max_backups_per_page']);
$globalBackupManager = new GlobalBackupManager($config['backups_dir']);
$blogBackupManager = new BackupManager($config['backups_dir'], $config['max_backups_per_page']);
$blogManager = new BlogManager($config['root_dir'], $config['cms_dir'], null, $blogBackupManager);
$authorManager = new AuthorManager($config['cms_dir'] . '/config');
$uploadManager = new UploadManager(
    $config['root_dir'],
    $config['uploads_dir'] ?? 'assets/content/',
    $config['image_thumbnail_width'] ?? 300,
    $config['image_thumbnail_height'] ?? 300,
    $config['image_full_width'] ?? 1920,
    $config['image_full_height'] ?? 1080
);

// normalizePageId() is provided by mcp/page-handlers.php

$handlers = getMcpHandlers($pageManager, $blockParser, $backupManager, $globalBackupManager, $blogManager, $uploadManager, $authorManager, $config, false, null);

// Tool schemas → Anthropic format
$toolsSchema = getMCPToolsWithSchema();
$allowedTools = $config['mcp_allowed_tools'] ?? array_keys($toolsSchema);

// Defense in depth: the schema list shown to the model is filtered to
// $allowedTools above, but the model can still emit tool_use for any
// name. Filter the executor map too so a disabled tool can never run
// through the AI editor.
$allowedSet = array_flip($allowedTools);
$handlers = array_intersect_key($handlers, $allowedSet);

$anthropicTools = [];
foreach ($allowedTools as $name) {
    if (!isset($toolsSchema[$name])) continue;
    $anthropicTools[] = [
        'name' => $name,
        'description' => $toolsSchema[$name]['description'] ?? $name,
        'input_schema' => $toolsSchema[$name]['inputSchema'] ?? ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
    ];
}

// Resolve current page context for the system prompt
$pagePath = $pageManager->getPagePath(normalizePageId($pageId));
$blockSummary = [];
if ($pagePath) {
    try {
        $blocks = $blockParser->parseBlocks($pagePath);
        foreach ($blocks as $b) {
            $blockSummary[] = sprintf(
                '%s%s%s',
                $b['name'],
                !empty($b['role']) ? '(' . $b['role'] . ')' : '',
                !empty($b['custom']) ? '[custom]' : '[global]'
            );
        }
    } catch (Throwable $e) {
        // proceed without block list if parse fails
    }
}

$hasDraft = $pagePath ? $pageManager->hasDraft(normalizePageId($pageId)) : false;

$systemPrompt = <<<SYS
You are an AI editor inside the CMS admin panel.

Active page: "{$pageId}" (use this exact value as page_id for tool calls; for the homepage use empty string "" — never "/" or "index").
Blocks currently on this page: {{BLOCKS}}
Draft exists: {{HASDRAFT}}

Behavior:
- When the user asks to change content, use search_blocks or list_blocks first to locate, then prefer find_and_replace_block_content for small edits, update_block for larger rewrites, insert_block to add new sections.
- For images, use list_images_in_block to inspect, then update_image_in_block (with index or match_src) to swap. Use upload_image / upload_image_from_url first if the user provides a new file or URL.
- Attached media (sent as image content blocks in the user's turn) are candidate uploads the user has attached in the drawer — they are NOT yet on the page. Their URLs are listed below. To place one, call update_image_in_block or insert_block with the listed URL as the new src; do not assume it is already in any block.
- All edits create a DRAFT — they don't go live. Do NOT call publish_page unless the user explicitly says "publish" / "make it live". The admin UI already shows the user a Publish button and a draft preview link in the header, so do not include any draft/preview URLs in your reply (especially never write "http://localhost…").
- Final response must be ONE short sentence stating what changed. No URLs. No "let me know when you want to publish" — the UI handles that. No re-statement of your plan.
- Block types: blocks marked "custom" are per-page overrides; blocks marked "global" sync to every page that uses them — warn the user when a change will sync globally and ask confirmation only if the change is large.
- If the user's request is ambiguous (multiple matching blocks, unclear scope), ask one clarifying question before editing.

You can use any MCP tool. Tools are wired to this exact CMS install — edits hit real files.
SYS;

$systemPrompt = str_replace('{{BLOCKS}}', $blockSummary ? implode(', ', $blockSummary) : '(no blocks parsed)', $systemPrompt);
$systemPrompt = str_replace('{{HASDRAFT}}', $hasDraft ? 'yes' : 'no', $systemPrompt);

if (!empty($validatedMedia)) {
    $attachedList = "\n\nAttached media (candidate uploads, NOT yet on the page):\n";
    foreach ($validatedMedia as $vm) {
        $attachedList .= '- ' . $vm['name'] . ' → ' . $vm['path'] . "\n";
    }
    $systemPrompt .= $attachedList;
}

// Build conversation array for Anthropic. When attached media are present,
// inject image content blocks into the LATEST user turn so the model can
// actually see the images.
$apiMessages = [];
$lastUserIdx = -1;
foreach ($incoming as $i => $m) {
    if (!isset($m['role'], $m['content'])) continue;
    if ($m['role'] === 'user') $lastUserIdx = $i;
}
foreach ($incoming as $i => $m) {
    if (!isset($m['role'], $m['content'])) continue;
    if ($i === $lastUserIdx && $m['role'] === 'user' && !empty($validatedMedia) && is_string($m['content'])) {
        $content = [];
        foreach ($validatedMedia as $vm) {
            $content[] = [
                'type' => 'image',
                'source' => ['type' => 'url', 'url' => $vm['absUrl']],
            ];
        }
        $content[] = ['type' => 'text', 'text' => $m['content']];
        $apiMessages[] = ['role' => 'user', 'content' => $content];
    } else {
        $apiMessages[] = ['role' => $m['role'], 'content' => $m['content']];
    }
}

$toolCalls = [];
$modified = false;
$mutatingPrefixes = ['update_', 'create_', 'delete_', 'insert_', 'publish_', 'discard_', 'restore_', 'upload_', 'find_and_replace_', 'manage_', 'schedule_'];
$maxIterations = 8;

function callAnthropic(string $apiKey, string $model, string $system, array $messages, array $tools): array {
    $body = [
        'model' => $model,
        'max_tokens' => 4096,
        'system' => $system,
        'messages' => $messages,
        'tools' => $tools,
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        CURLOPT_TIMEOUT => 90,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        return ['_error' => 'curl: ' . $err];
    }
    $j = json_decode($resp, true);
    if ($code >= 400) {
        $msg = $j['error']['message'] ?? $resp;
        return ['_error' => 'Anthropic ' . $code . ': ' . $msg];
    }
    if (!is_array($j) || !isset($j['content'])) {
        return ['_error' => 'Unexpected response shape'];
    }
    return $j;
}

function summarizeResult($r): string {
    if (is_string($r)) return mb_strlen($r) > 200 ? mb_substr($r, 0, 200) . '…' : $r;
    if (!is_array($r)) return (string)$r;
    if (isset($r['success']) && $r['success'] === false) {
        return 'error: ' . ($r['error'] ?? 'unknown');
    }
    if (isset($r['message'])) return $r['message'];
    if (isset($r['url'])) return 'url: ' . $r['url'];
    if (isset($r['count']) && isset($r['images'])) return $r['count'] . ' images';
    if (isset($r['pages']) && is_array($r['pages'])) return count($r['pages']) . ' pages';
    if (isset($r['blocks']) && is_array($r['blocks'])) return count($r['blocks']) . ' blocks';
    if (isset($r['content'])) return strlen((string)$r['content']) . ' chars';
    $s = json_encode($r);
    return mb_strlen($s) > 200 ? mb_substr($s, 0, 200) . '…' : $s;
}

for ($i = 0; $i < $maxIterations; $i++) {
    $resp = callAnthropic($apiKey, $model, $systemPrompt, $apiMessages, $anthropicTools);
    if (isset($resp['_error'])) {
        echo json_encode([
            'error' => $resp['_error'],
            'tool_calls' => $toolCalls,
            'messages' => $apiMessages,
            'modified' => $modified,
            'media_warnings' => $mediaWarnings,
        ]);
        exit;
    }
    $assistantContent = $resp['content'];
    $apiMessages[] = ['role' => 'assistant', 'content' => $assistantContent];
    $stopReason = $resp['stop_reason'] ?? '';

    if ($stopReason !== 'tool_use') {
        $text = '';
        foreach ($assistantContent as $b) {
            if (($b['type'] ?? '') === 'text') $text .= $b['text'];
        }
        echo json_encode([
            'assistant' => $text !== '' ? $text : '(no response)',
            'tool_calls' => $toolCalls,
            'messages' => $apiMessages,
            'modified' => $modified,
            'media_warnings' => $mediaWarnings,
        ]);
        exit;
    }

    // Execute each tool_use block
    $toolResults = [];
    foreach ($assistantContent as $b) {
        if (($b['type'] ?? '') !== 'tool_use') continue;
        $tname = $b['name'];
        $tinput = $b['input'] ?? [];
        $tid = $b['id'];
        $handler = $handlers[$tname] ?? null;
        if (!$handler) {
            $result = ['success' => false, 'error' => 'Unknown tool: ' . $tname];
        } else {
            try {
                $result = $handler($tinput);
            } catch (Throwable $e) {
                $result = ['success' => false, 'error' => $e->getMessage()];
            }
        }
        foreach ($mutatingPrefixes as $p) {
            if (strpos($tname, $p) === 0) { $modified = true; break; }
        }
        $toolCalls[] = [
            'name' => $tname,
            'input' => $tinput,
            'result_summary' => summarizeResult($result),
        ];
        $toolResults[] = [
            'type' => 'tool_result',
            'tool_use_id' => $tid,
            'content' => is_string($result) ? $result : json_encode($result),
        ];
    }
    $apiMessages[] = ['role' => 'user', 'content' => $toolResults];
}

echo json_encode([
    'error' => 'Max tool-use iterations reached (' . $maxIterations . '). Try a simpler request.',
    'tool_calls' => $toolCalls,
    'messages' => $apiMessages,
    'modified' => $modified,
    'media_warnings' => $mediaWarnings,
]);
