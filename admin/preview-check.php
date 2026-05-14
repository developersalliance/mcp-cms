<?php
/**
 * Draft modification time check endpoint
 * Returns JSON with draft mtime for polling
 */

require_once __DIR__ . '/includes/auth-guard.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache');

$pageId = $_GET['page_id'] ?? '';
if ($pageId === '') {
    $pageId = 'index';
}

// Validate page_id: reject traversal, null bytes, and disallowed chars
if (strpos($pageId, '..') !== false
    || strpos($pageId, "\0") !== false
    || !preg_match('#^[a-z0-9_\-/]+$#', $pageId)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid page_id';
    exit;
}

// Draft path matches PageManager format: drafts/pages/{pageId}.php
$draftPath = $config['drafts_dir'] . '/pages/' . $pageId . '.php';

// Containment check: only respond about files inside drafts_dir
$expectedReal = realpath($config['drafts_dir']);
$real = realpath($draftPath);

if ($real !== false) {
    if ($expectedReal === false || strpos($real, $expectedReal . DIRECTORY_SEPARATOR) !== 0) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }
    echo json_encode([
        'exists' => true,
        'mtime' => filemtime($real)
    ]);
} else {
    echo json_encode([
        'exists' => false,
        'mtime' => 0
    ]);
}
