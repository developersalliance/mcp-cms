<?php
/**
 * View Backup Content
 * Shows the content of a backup file with syntax highlighting
 */

require_once __DIR__ . '/includes/auth-guard.php';

$backupsDir = __DIR__ . '/../backups';
$pageId = $_GET['page'] ?? '';
$timestamp = $_GET['ts'] ?? '';

function backup_fail(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

if (!$pageId || !$timestamp) {
    backup_fail(400, 'Missing parameters');
}

// Validate timestamp format
if (!preg_match('/^\d{14}$/', $timestamp)) {
    backup_fail(400, 'Invalid timestamp');
}

// Build backup path
$backupPath = $backupsDir . '/' . $pageId . '/index.php.' . $timestamp;

// Security: ensure path is within backups directory
$realBackupsDir = realpath($backupsDir);
$realBackupPath = realpath($backupPath);

if (!$realBackupPath || strpos($realBackupPath, $realBackupsDir . DIRECTORY_SEPARATOR) !== 0) {
    backup_fail(403, 'Invalid backup path');
}

if (!file_exists($backupPath)) {
    backup_fail(404, 'Backup not found');
}

$content = file_get_contents($backupPath);
$dt = DateTime::createFromFormat('YmdHis', $timestamp);
$dateFormatted = $dt ? $dt->format('F j, Y g:i A') : $timestamp;

$pageTitle = 'View Backup';
$activePage = 'settings';

require __DIR__ . '/includes/header.php';
?>

<!-- Ace Editor for syntax highlighting -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/ace.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/mode-php.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/theme-chrome.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/theme-monokai.min.js"></script>

<div class="mb-6">
    <a href="/cms/admin/backups.php" class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300">
        &larr; Back to Backups
    </a>
</div>

<div class="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">
            Backup: <?php echo htmlspecialchars($pageId); ?>
        </h1>
        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
            <?php echo $dateFormatted; ?>
            &middot;
            <?php echo number_format(strlen($content)); ?> bytes
        </p>
    </div>

    <div id="editor" style="height: 600px; width: 100%;"></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const isDark = document.documentElement.classList.contains('dark');
    const editor = ace.edit('editor');

    editor.setTheme(isDark ? 'ace/theme/monokai' : 'ace/theme/chrome');
    editor.session.setMode('ace/mode/php');
    editor.setReadOnly(true);
    editor.setOptions({
        showPrintMargin: false,
        wrap: true,
        tabSize: 4,
        fontSize: '13px',
        fontFamily: "'JetBrains Mono', 'Fira Code', monospace"
    });

    editor.setValue(<?php echo json_encode($content); ?>, -1);
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
