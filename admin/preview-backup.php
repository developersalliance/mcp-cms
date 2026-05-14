<?php
/**
 * Preview Backup Version
 *
 * Renders a backup version of a page for preview purposes
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/BackupManager.php';

$backupManager = new BackupManager($config['backups_dir'], $config['max_backups_per_page']);

// Get page ID and timestamp
$pageId = $_GET['page_id'] ?? '';
$timestamp = $_GET['timestamp'] ?? '';

// Validate page_id: reject traversal, null bytes, and disallowed chars
if (strpos((string)$pageId, '..') !== false
    || strpos((string)$pageId, "\0") !== false
    || ($pageId !== '' && !preg_match('#^[a-z0-9_\-/]+$#', $pageId))) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid page_id';
    exit;
}

// Validate timestamp: exactly 14 digits (YmdHis)
if (strpos((string)$timestamp, '..') !== false
    || strpos((string)$timestamp, "\0") !== false
    || !preg_match('/^[0-9]{14}$/', (string)$timestamp)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid page_id';
    exit;
}

if (!$timestamp) {
    http_response_code(400);
    echo '<!doctype html><html><head><title>Bad Request</title></head><body><h1>400 - Missing timestamp parameter</h1></body></html>';
    exit;
}

// Get backup content
$backups = $backupManager->listBackups($pageId);
$backupPath = null;

foreach ($backups as $backup) {
    if ($backup['timestamp'] === $timestamp) {
        $backupPath = $backup['path'];
        break;
    }
}

if (!$backupPath || !file_exists($backupPath)) {
    http_response_code(404);
    echo '<!doctype html><html><head><title>Backup Not Found</title></head><body><h1>404 - Backup Not Found</h1></body></html>';
    exit;
}

// Containment check: ensure the resolved backup path is inside backups_dir
$expectedReal = realpath($config['backups_dir']);
$real = realpath($backupPath);
if ($expectedReal === false || $real === false || strpos($real, $expectedReal . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

// Read backup content
$backupContent = file_get_contents($real);

// Format timestamp for display
$dt = DateTime::createFromFormat('YmdHis', $timestamp);
$formattedDate = $dt ? $dt->format('M j, Y \a\t g:i A') : $timestamp;

// Add a banner indicating this is a backup preview
$bannerHtml = <<<HTML
<div style="position: fixed; top: 0; left: 0; right: 0; background: linear-gradient(135deg, #f59e0b, #d97706); color: white; padding: 12px 20px; z-index: 99999; font-family: system-ui, -apple-system, sans-serif; font-size: 14px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 8px rgba(0,0,0,0.15);">
    <div style="display: flex; align-items: center; gap: 10px;">
        <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <strong>Backup Preview</strong>
        <span style="opacity: 0.9;">- Version from {$formattedDate}</span>
    </div>
    <button onclick="window.close()" style="background: rgba(255,255,255,0.2); border: none; color: white; padding: 6px 16px; border-radius: 6px; cursor: pointer; font-size: 13px;">Close Preview</button>
</div>
<div style="height: 52px;"></div>
HTML;

// Inject banner after <body> tag
if (stripos($backupContent, '<body') !== false) {
    $backupContent = preg_replace('/(<body[^>]*>)/i', '$1' . $bannerHtml, $backupContent, 1);
} else {
    $backupContent = $bannerHtml . $backupContent;
}

// Create a temporary file and include it
$tempFile = tempnam(sys_get_temp_dir(), 'cms_backup_preview_');
file_put_contents($tempFile, $backupContent);

// Include and render
include $tempFile;

// Clean up
@unlink($tempFile);
