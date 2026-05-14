<?php
/**
 * Preview Page System
 *
 * Renders a page for preview purposes with auto-refresh via SSE
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/PageManager.php';
require_once __DIR__ . '/../core/PageSettings.php';

$reservedFolders = $config['reserved_folders'] ?? ['cms'];
$pageSettings = new PageSettings($config['cms_dir'] . '/settings');
$pageManager = new PageManager($config['root_dir'], $reservedFolders, $config['drafts_dir'] ?? null, null, null, $pageSettings);

// Get page ID and draft parameter
$pageId = $_GET['page_id'] ?? '';
$showDraft = isset($_GET['draft']) && $_GET['draft'] === '1';

// Validate page_id: reject traversal, null bytes, and disallowed chars
$rawPageId = (string)$pageId;
if (strpos($rawPageId, '..') !== false
    || strpos($rawPageId, "\0") !== false
    || ($rawPageId !== '' && !preg_match('#^[a-z0-9_\-/]+$#', $rawPageId))) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid page_id';
    exit;
}

// Polling auto-refresh script (injected before </body>)
$refreshScript = '';
if ($showDraft) {
    $escapedPageId = htmlspecialchars($pageId, ENT_QUOTES, 'UTF-8');
    $refreshScript = <<<HTML
<script>
(function() {
    var lastMtime = 0;
    var pollInterval = 3000;

    function checkDraft() {
        fetch('/cms/admin/preview-check.php?page_id={$escapedPageId}')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (lastMtime === 0) {
                    lastMtime = data.mtime;
                } else if (data.mtime > lastMtime) {
                    location.reload();
                }
            })
            .catch(function() {});
    }

    setInterval(checkDraft, pollInterval);
    checkDraft();
})();
</script>
HTML;
}

// If showing draft, create temporary file from draft content
if ($showDraft && $pageManager->hasDraft($pageId)) {
    // Containment check: ensure the resolved draft path is inside drafts_dir
    $draftsDir = $config['drafts_dir'] ?? '';
    $expectedReal = $draftsDir ? realpath($draftsDir) : false;
    $draftCandidate = rtrim((string)$draftsDir, '/') . '/pages/' . ($pageId ?: 'index') . '.php';
    $real = realpath($draftCandidate);
    if ($expectedReal === false || $real === false || strpos($real, $expectedReal . DIRECTORY_SEPARATOR) !== 0) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }

    $draftContent = $pageManager->getDraft($pageId);

    if ($draftContent === null) {
        http_response_code(404);
        echo '<!doctype html><html><head><title>Draft Not Found</title></head><body><h1>404 - Draft Not Found</h1></body></html>';
        exit;
    }

    // Inject refresh script before </body>
    if ($refreshScript && stripos($draftContent, '</body>') !== false) {
        $draftContent = str_ireplace('</body>', $refreshScript . '</body>', $draftContent);
    } else if ($refreshScript) {
        $draftContent .= $refreshScript;
    }

    // Create a temporary file with the draft content
    $tempFile = tempnam(sys_get_temp_dir(), 'cms_preview_');
    file_put_contents($tempFile, $draftContent);

    // Include and render the draft
    include $tempFile;

    // Clean up
    @unlink($tempFile);
} else {
    // Show live page
    $pagePath = $pageManager->getPagePath($pageId);

    if (!$pagePath) {
        http_response_code(404);
        echo '<!doctype html><html><head><title>Page Not Found</title></head><body><h1>404 - Page Not Found</h1></body></html>';
        exit;
    }

    // Include and render the page
    include $pagePath;
}
