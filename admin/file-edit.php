<?php
/**
 * File Editor - Edit file contents
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/CSRF.php';
require_once __DIR__ . '/../core/PageManager.php';
require_once __DIR__ . '/../core/PageSettings.php';
require_once __DIR__ . '/../core/BackupManager.php';

$pageTitle = 'Edit File';
$activePage = 'files';

// Forbidden directories
$forbiddenDirs = ['cms', '.git', 'node_modules', 'vendor'];

// Editable file extensions. We deliberately allow code/markup/config files
// (including .php) — admins need to be able to edit them. Auth + CSRF +
// path-traversal and the forbiddenDirs/dotfile checks above stop drive-by
// abuse. Binary file types (jpg, mp4, …) are excluded since the editor
// would corrupt them.
$allowedEditExtensions = [
    // markup / templates
    'html', 'htm', 'php', 'phtml', 'twig', 'jinja', 'liquid', 'erb',
    // scripts / data
    'js', 'mjs', 'ts', 'jsx', 'tsx', 'css', 'scss', 'sass', 'less',
    'json', 'xml', 'yml', 'yaml', 'toml', 'svg', 'csv', 'tsv',
    // text / prose
    'txt', 'md', 'markdown', 'rst', 'log',
    // shell / scripts
    'sh', 'bash', 'zsh', 'fish',
    'py', 'rb', 'pl', 'lua',
    'sql',
    // c-family / other code
    'c', 'cpp', 'h', 'hpp', 'java', 'go', 'rs', 'kt', 'swift',
    // config
    'ini', 'cfg', 'conf', 'env', 'properties', 'plist',
    // generic
    'tpl', 'tmpl', 'gitignore', 'dockerignore', 'editorconfig',
];

// Get root directory from config
$rootDir = rtrim($config['root_dir'], '/');

// Get file path from query parameter and sanitize it
$requestedFile = $_GET['file'] ?? '';
$requestedFile = str_replace(['..', '\\', "\0"], '', trim($requestedFile, '/'));

function fe_fail(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

if (!$requestedFile) {
    header('Location: /cms/admin/files.php');
    exit;
}

// Check if first segment is forbidden
$pathSegments = array_filter(explode('/', $requestedFile));
if (!empty($pathSegments) && in_array($pathSegments[0], $forbiddenDirs)) {
    fe_fail(403, 'Access denied to this directory');
}

// Reject dotfiles like .htaccess / .htpasswd in any segment
foreach ($pathSegments as $segment) {
    if (strpos($segment, '.') === 0) {
        fe_fail(403, 'Access denied');
    }
}

// Build absolute path
$filePath = $rootDir . '/' . $requestedFile;
$realFilePath = realpath($filePath);
$realRootPath = realpath($rootDir);

// Security checks
if (!$realFilePath || !is_file($realFilePath) || !$realRootPath || strpos($realFilePath, $realRootPath . DIRECTORY_SEPARATOR) !== 0) {
    fe_fail(404, 'Invalid file path');
}

// Block any path that resolves inside the cms/ tree
$realCmsPath = realpath($rootDir . '/cms');
if ($realCmsPath && strpos($realFilePath, $realCmsPath . DIRECTORY_SEPARATOR) === 0) {
    fe_fail(403, 'Access denied to this directory');
}

$fileName = basename($requestedFile);
$fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

// Enforce extension allowlist
if (!in_array($fileExt, $allowedEditExtensions, true)) {
    fe_fail(403, 'Editing files of this type is not permitted');
}

// Handle file save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    CSRF::verifyOrDie();

    try {
        // Re-check the resolved target path on save (defense-in-depth)
        if (!$realFilePath || !is_file($realFilePath) || strpos($realFilePath, $realRootPath . DIRECTORY_SEPARATOR) !== 0) {
            throw new Exception('Invalid file path');
        }
        if ($realCmsPath && strpos($realFilePath, $realCmsPath . DIRECTORY_SEPARATOR) === 0) {
            throw new Exception('Access denied');
        }
        if (!in_array($fileExt, $allowedEditExtensions, true)) {
            throw new Exception('Editing files of this type is not permitted');
        }

        $content = $_POST['content'] ?? '';

        // Skip the whole save (and backup) if content is identical to what's
        // already on disk. Avoids piling up backups when the user hits Save
        // multiple times without editing — the dominant cause of backup
        // bloat in the previous behaviour.
        $existing = @file_get_contents($realFilePath);
        if ($existing !== false && $existing === $content) {
            $successMessage = 'No changes — nothing to save.';
            // Fall through to the normal render; nothing else to do here.
            $skipSave = true;
        }
        if (empty($skipSave)) {

        // Decide where to back this file up.
        //
        // If the edited file IS a CMS page's index.php (matches a known
        // page_id), route the backup through BackupManager so the file
        // editor and the block / AI editor all share the same per-page
        // backup history (no more duplicate timelines).
        //
        // Otherwise, fall back to a generic backups_dir/_file_edits/...
        // location keyed by the file's relative path.
        // Reuse listPages() via resolvePageIdByPath — single helper, single
        // memoized scan instead of building it inline every save.
        $pageSettings = new PageSettings($config['cms_dir'] . '/settings');
        $pageManager = new PageManager($config['root_dir'], $config['reserved_folders'] ?? ['cms'], $config['drafts_dir'] ?? null, null, null, $pageSettings);
        $matchedPageId = $pageManager->resolvePageIdByPath($realFilePath);
        $backupCreated = null; // path of the backup just made, for the success message
        if ($matchedPageId !== null) {
            $backupManager = new BackupManager($config['backups_dir'], $config['max_backups_per_page'] ?? 10);
            try {
                $backupManager->createBackup($matchedPageId, $realFilePath);
                $backupCreated = 'page backup history (' . ($matchedPageId === '' ? 'home' : $matchedPageId) . ')';
            } catch (Exception $e) {
                throw new Exception('Backup failed (refused save): ' . $e->getMessage());
            }
        } else {
            $relativePath = ltrim(str_replace($realRootPath, '', dirname($realFilePath)), '/\\');
            $centralBackupDir = rtrim($config['backups_dir'], '/') . '/_file_edits' . ($relativePath ? '/' . $relativePath : '');
            if (!is_dir($centralBackupDir) && !mkdir($centralBackupDir, 0755, true) && !is_dir($centralBackupDir)) {
                throw new Exception('Backup failed: could not create ' . $centralBackupDir . ' (check ownership of backups dir — must be writable by the PHP-FPM user, usually www-data)');
            }
            $backupFile = $centralBackupDir . '/' . $fileName . '.' . date('YmdHis') . '.bak';
            if (!copy($realFilePath, $backupFile)) {
                throw new Exception('Backup failed: could not copy ' . $realFilePath . ' to ' . $backupFile);
            }
            $backupCreated = $backupFile;
        }

        if (file_put_contents($realFilePath, $content) === false) {
            throw new Exception('Failed to save file');
        }

        $successMessage = 'File saved' . ($backupCreated ? ' (backup: ' . htmlspecialchars($backupCreated) . ')' : '');
        } // end if (!$skipSave)
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

// Backup preview — emit the raw contents of a backup as text/plain so the
// user can inspect what they would be restoring. text/plain prevents PHP
// in the backup from executing. Same path-traversal protection as restore.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['preview_backup'])) {
    $bakPath = (string)$_GET['preview_backup'];
    $realBak = $bakPath !== '' ? realpath($bakPath) : false;
    $realBackupsDir = realpath($config['backups_dir']);
    if (!$realBak || !$realBackupsDir || strpos($realBak, $realBackupsDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($realBak)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Backup not found or path outside backups directory.";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . basename($realBak) . '"');
    readfile($realBak);
    exit;
}

// Handle backup restore (after save handler, before render)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_backup') {
    CSRF::verifyOrDie();
    try {
        $bakPath = (string)($_POST['backup_path'] ?? '');
        $realBak = $bakPath !== '' ? realpath($bakPath) : false;
        $realBackupsDir = realpath($config['backups_dir']);
        if (!$realBak || !$realBackupsDir || strpos($realBak, $realBackupsDir . DIRECTORY_SEPARATOR) !== 0) {
            throw new Exception('Backup path is outside the backups directory');
        }
        if (!is_file($realBak)) {
            throw new Exception('Backup file not found');
        }
        // Create a "pre-restore" backup of the current file first
        $relativePath = ltrim(str_replace($realRootPath, '', dirname($realFilePath)), '/\\');
        $centralBackupDir = rtrim($config['backups_dir'], '/') . '/_file_edits' . ($relativePath ? '/' . $relativePath : '');
        if (!is_dir($centralBackupDir) && !mkdir($centralBackupDir, 0755, true) && !is_dir($centralBackupDir)) {
            throw new Exception('Could not create pre-restore backup dir');
        }
        @copy($realFilePath, $centralBackupDir . '/' . $fileName . '.' . date('YmdHis') . '.pre-restore.bak');
        if (!copy($realBak, $realFilePath)) {
            throw new Exception('Failed to restore backup');
        }
        $successMessage = 'Restored from ' . basename($realBak) . ' (the previous version was saved as a pre-restore backup).';
    } catch (Exception $e) {
        $errorMessage = 'Restore failed: ' . $e->getMessage();
    }
}

// Read file content (after any restore so the editor shows restored content)
$fileContent = file_get_contents($realFilePath);
$fileSize = filesize($realFilePath);
$fileSizeFormatted = $fileSize < 1024 ? $fileSize . ' B' : ($fileSize < 1048576 ? round($fileSize / 1024, 1) . ' KB' : round($fileSize / 1048576, 1) . ' MB');

// Collect prior backups of THIS file from both possible locations.
$fileBackups = [];
$pageSettings = new PageSettings($config['cms_dir'] . '/settings');
$pageManager = new PageManager($config['root_dir'], $config['reserved_folders'] ?? ['cms'], $config['drafts_dir'] ?? null, null, null, $pageSettings);
foreach ($pageManager->listPages() as $p) {
    if (realpath($p['path']) === $realFilePath) {
        $bm = new BackupManager($config['backups_dir'], $config['max_backups_per_page'] ?? 10);
        foreach ($bm->listBackups($p['id']) as $b) {
            $fileBackups[] = ['path' => $b['path'], 'date' => $b['date'], 'timestamp' => $b['timestamp'], 'source' => 'page'];
        }
        break;
    }
}
$relativeDir = ltrim(str_replace($realRootPath, '', dirname($realFilePath)), '/\\');
$feDir = rtrim($config['backups_dir'], '/') . '/_file_edits' . ($relativeDir ? '/' . $relativeDir : '');
foreach (glob($feDir . '/' . $fileName . '.*.bak') ?: [] as $bakPath) {
    // file-edit backups are written with date('YmdHis') = 14 digits, no
    // separator (e.g. "presentation/index.html.20260513151517.bak"). Also
    // accept the legacy "YYYYMMDD-HHMMSS" pattern just in case.
    if (preg_match('/\.(\d{14}|\d{8}-\d{6})(?:\.pre-restore)?\.bak$/', basename($bakPath), $m)) {
        $ts = $m[1];
        $digits = str_replace('-', '', $ts);
        $readable = sprintf('%s-%s-%s %s:%s:%s',
            substr($digits, 0, 4), substr($digits, 4, 2), substr($digits, 6, 2),
            substr($digits, 8, 2), substr($digits, 10, 2), substr($digits, 12, 2));
        // Normalise the sortable timestamp so all entries are comparable.
        $fileBackups[] = ['path' => $bakPath, 'date' => $readable, 'timestamp' => $digits, 'source' => 'file-edits'];
    }
}
usort($fileBackups, fn($a, $b) => strcmp($b['timestamp'], $a['timestamp']));

// Get parent directory for breadcrumb
$parentPath = dirname($requestedFile);
$parentPath = ($parentPath === '.' || $parentPath === '/') ? '' : $parentPath;

require __DIR__ . '/includes/header.php';
?>

<!-- CodeMirror CSS and JS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/eclipse.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/xml/xml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/css/css.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/php/php.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/markdown/markdown.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/python/python.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/shell/shell.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/sql/sql.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/yaml/yaml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/clike/clike.min.js"></script>

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900 mb-2">Edit File</h1>
    <p class="text-gray-600">
        <a href="/cms/admin/files.php?path=<?php echo urlencode($parentPath); ?>" class="text-blue-600 hover:text-blue-800">&larr; Back to File Manager</a>
    </p>
</div>

<?php if (isset($successMessage)): ?>
    <div class="bg-green-50 border-l-4 border-green-500 p-4 mb-6">
        <p class="text-green-700"><?php echo htmlspecialchars($successMessage); ?></p>
    </div>
<?php endif; ?>

<?php if (isset($errorMessage)): ?>
    <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
        <p class="text-red-700"><?php echo htmlspecialchars($errorMessage); ?></p>
    </div>
<?php endif; ?>

<!-- File Info -->
<div class="bg-white rounded-lg shadow-md p-4 mb-6">
    <div class="grid grid-cols-3 gap-4 text-sm">
        <div>
            <span class="text-gray-600">File:</span>
            <span class="font-medium text-gray-900 ml-2"><?php echo htmlspecialchars($fileName); ?></span>
        </div>
        <div>
            <span class="text-gray-600">Size:</span>
            <span class="font-medium text-gray-900 ml-2"><?php echo htmlspecialchars($fileSizeFormatted); ?></span>
        </div>
        <div>
            <span class="text-gray-600">Type:</span>
            <span class="font-medium text-gray-900 ml-2"><?php echo htmlspecialchars($fileExt ?: 'no extension'); ?></span>
        </div>
    </div>
</div>

<!-- Backups for this file -->
<?php if (!empty($fileBackups)): ?>
<div class="bg-white rounded-lg shadow-md p-4 mb-6">
    <details<?php echo count($fileBackups) <= 3 ? ' open' : ''; ?>>
        <summary class="text-sm font-medium text-gray-700 cursor-pointer select-none">Backups (<?php echo count($fileBackups); ?>)</summary>
        <ul class="mt-3 divide-y divide-gray-100">
            <?php foreach ($fileBackups as $b): ?>
                <li class="flex items-center justify-between py-2 text-sm">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="text-xs font-mono text-gray-500"><?php echo htmlspecialchars($b['date']); ?></span>
                        <span class="text-xs px-2 py-0.5 rounded-full <?php echo $b['source'] === 'page' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600'; ?>"><?php echo $b['source']; ?></span>
                        <code class="text-xs text-gray-400 truncate"><?php echo htmlspecialchars(basename($b['path'])); ?></code>
                    </div>
                    <div class="flex items-center gap-1">
                        <a href="/cms/admin/file-edit.php?file=<?php echo urlencode($requestedFile); ?>&preview_backup=<?php echo urlencode($b['path']); ?>"
                           target="_blank"
                           class="px-2.5 py-1 text-xs font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-md">Preview</a>
                        <form method="post" class="inline" onsubmit="return confirm('Restore this backup? Your current edits will be saved as a pre-restore backup first.');">
                            <?php echo CSRF::inputField(); ?>
                            <input type="hidden" name="action" value="restore_backup">
                            <input type="hidden" name="backup_path" value="<?php echo htmlspecialchars($b['path']); ?>">
                            <button type="submit" class="px-2.5 py-1 text-xs font-medium text-accent-700 hover:bg-accent-50 rounded-md">Restore</button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </details>
</div>
<?php else: ?>
<div class="bg-white rounded-lg shadow-md p-4 mb-6 text-sm text-gray-500">No prior backups of this file yet — the next save will create one.</div>
<?php endif; ?>

<!-- File Editor -->
<form method="post">
    <?php echo CSRF::inputField(); ?>
    <input type="hidden" name="action" value="save">

    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-2">File Content:</label>
            <textarea
                name="content"
                rows="25"
                class="w-full px-4 py-3 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono text-sm"
                spellcheck="false"
            ><?php echo htmlspecialchars($fileContent); ?></textarea>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition">
                Save Changes
            </button>
            <a href="/cms/admin/files.php?path=<?php echo urlencode($parentPath); ?>" class="px-6 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 transition inline-block">
                Cancel
            </a>
        </div>
    </div>
</form>

<div class="bg-yellow-50 border-l-4 border-yellow-500 p-4">
    <p class="text-yellow-700">
        <strong>Note:</strong> A backup is automatically created before every save. Page files share the page's backup history; other files go to <code class="bg-yellow-100 px-1 rounded">backups/_file_edits/</code>. Restore prior versions from the Backups list above.
    </p>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const textarea = document.querySelector('textarea[name="content"]');
    const form = textarea.closest('form');
    const fileExt = '<?php echo strtolower($fileExt); ?>';

    // Determine CodeMirror mode based on file extension
    let mode = 'text/plain';

    const modeMap = {
        'php': 'application/x-httpd-php',
        'html': 'htmlmixed',
        'htm': 'htmlmixed',
        'xml': 'xml',
        'js': 'javascript',
        'json': { name: 'javascript', json: true },
        'css': 'css',
        'scss': 'text/x-scss',
        'sass': 'text/x-sass',
        'less': 'text/x-less',
        'md': 'markdown',
        'markdown': 'markdown',
        'py': 'python',
        'sh': 'shell',
        'bash': 'shell',
        'sql': 'sql',
        'yml': 'yaml',
        'yaml': 'yaml',
        'java': 'text/x-java',
        'c': 'text/x-csrc',
        'cpp': 'text/x-c++src',
        'h': 'text/x-csrc',
        'go': 'text/x-go',
        'rb': 'text/x-ruby',
        'txt': 'text/plain'
    };

    if (modeMap[fileExt]) {
        mode = modeMap[fileExt];
    }

    // Initialize CodeMirror
    const editor = CodeMirror.fromTextArea(textarea, {
        mode: mode,
        theme: 'eclipse',
        lineNumbers: true,
        lineWrapping: true,
        indentUnit: 4,
        indentWithTabs: false,
        matchBrackets: true,
        viewportMargin: Infinity
    });

    // Save CodeMirror content to textarea before form submit
    form.addEventListener('submit', function() {
        editor.save();
    });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
