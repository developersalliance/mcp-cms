<?php
/**
 * Backups Management
 * View and restore all page and blog post backups
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/CSRF.php';
require_once __DIR__ . '/../core/BackupManager.php';

$backupsDir = __DIR__ . '/../backups';
$rootDir = dirname(dirname(__DIR__));

// Handle restore action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $action = $_POST['action'] ?? '';

    if ($action === 'restore') {
        $pageId = $_POST['page_id'] ?? '';
        $timestamp = $_POST['timestamp'] ?? '';

        if ($pageId && $timestamp) {
            try {
                $backupManager = new BackupManager($backupsDir);

                // Determine target path based on page ID
                if (strpos($pageId, '/') !== false) {
                    // Blog post: collection/slug format
                    list($collection, $slug) = explode('/', $pageId, 2);
                    $targetPath = $rootDir . '/' . $collection . '/' . $slug . '/index.php';

                    // Check if it's a draft
                    $draftPath = __DIR__ . '/../drafts/' . $pageId . '/index.php';
                    if (file_exists($draftPath)) {
                        $targetPath = $draftPath;
                    }
                } elseif ($pageId === 'home') {
                    $targetPath = $rootDir . '/index.php';
                } else {
                    $targetPath = $rootDir . '/' . $pageId . '/index.php';
                }

                $backupManager->restoreBackup($pageId, $timestamp, $targetPath);
                $successMessage = "Backup restored successfully for: {$pageId}";
            } catch (Exception $e) {
                $errorMessage = "Failed to restore backup: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $pageId = $_POST['page_id'] ?? '';
        $timestamp = $_POST['timestamp'] ?? '';

        if ($pageId && $timestamp) {
            $backupPath = $backupsDir . '/' . $pageId . '/index.php.' . $timestamp;
            if (file_exists($backupPath) && unlink($backupPath)) {
                $successMessage = "Backup deleted successfully.";
            } else {
                $errorMessage = "Failed to delete backup.";
            }
        }
    }
}

/**
 * Scan all backups in the backups directory
 */
function getAllBackups(string $backupsDir): array
{
    $allBackups = [];

    if (!is_dir($backupsDir)) {
        return $allBackups;
    }

    // Scan top-level directories (pages)
    $topLevel = scandir($backupsDir);
    foreach ($topLevel as $item) {
        if ($item === '.' || $item === '..' || $item === '.gitkeep') {
            continue;
        }

        $itemPath = $backupsDir . '/' . $item;
        if (!is_dir($itemPath)) {
            continue;
        }

        // Check if this directory contains backup files directly (page backups)
        $hasBackupFiles = false;
        $subItems = scandir($itemPath);

        foreach ($subItems as $subItem) {
            if (preg_match('/^index\.php\.\d{14}$/', $subItem)) {
                $hasBackupFiles = true;
                break;
            }
        }

        if ($hasBackupFiles) {
            // This is a page backup directory
            $backups = getBackupsForPage($itemPath, $item);
            if (!empty($backups)) {
                $allBackups[] = [
                    'type' => 'page',
                    'id' => $item,
                    'label' => $item === 'home' ? 'Home Page' : ucfirst($item),
                    'backups' => $backups
                ];
            }
        } else {
            // This might be a collection directory (blog posts)
            foreach ($subItems as $subItem) {
                if ($subItem === '.' || $subItem === '..') {
                    continue;
                }

                $subPath = $itemPath . '/' . $subItem;
                if (is_dir($subPath)) {
                    $backups = getBackupsForPage($subPath, $item . '/' . $subItem);
                    if (!empty($backups)) {
                        $allBackups[] = [
                            'type' => 'blog',
                            'id' => $item . '/' . $subItem,
                            'label' => ucfirst($item) . ': ' . ucwords(str_replace('-', ' ', $subItem)),
                            'backups' => $backups
                        ];
                    }
                }
            }
        }
    }

    // Sort by most recent backup
    usort($allBackups, function ($a, $b) {
        $aLatest = $a['backups'][0]['timestamp'] ?? '';
        $bLatest = $b['backups'][0]['timestamp'] ?? '';
        return strcmp($bLatest, $aLatest);
    });

    return $allBackups;
}

/**
 * Get backups for a specific page/post
 */
function getBackupsForPage(string $dir, string $pageId): array
{
    $backups = [];
    $files = glob($dir . '/index.php.*');

    foreach ($files as $file) {
        $filename = basename($file);
        if (preg_match('/index\.php\.(\d{14})$/', $filename, $matches)) {
            $timestamp = $matches[1];
            $dt = DateTime::createFromFormat('YmdHis', $timestamp);

            $backups[] = [
                'timestamp' => $timestamp,
                'date' => $dt ? $dt->format('M j, Y g:i A') : $timestamp,
                'date_relative' => $dt ? getRelativeTime($dt) : '',
                'size' => filesize($file),
                'path' => $file
            ];
        }
    }

    // Sort by timestamp descending (newest first)
    usort($backups, function ($a, $b) {
        return strcmp($b['timestamp'], $a['timestamp']);
    });

    return $backups;
}

/**
 * Get relative time string
 */
function getRelativeTime(DateTime $date): string
{
    $now = new DateTime();
    $diff = $now->diff($date);

    if ($diff->days === 0) {
        if ($diff->h === 0) {
            if ($diff->i === 0) {
                return 'just now';
            }
            return $diff->i . ' min ago';
        }
        return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    } elseif ($diff->days === 1) {
        return 'yesterday';
    } elseif ($diff->days < 7) {
        return $diff->days . ' days ago';
    } elseif ($diff->days < 30) {
        $weeks = floor($diff->days / 7);
        return $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ago';
    } else {
        return $date->format('M j, Y');
    }
}

/**
 * Format file size
 */
function formatSize(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    } elseif ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' KB';
    } else {
        return round($bytes / (1024 * 1024), 2) . ' MB';
    }
}

// Get all backups
$allBackups = getAllBackups($backupsDir);

// Calculate totals
$totalBackups = 0;
$totalSize = 0;
foreach ($allBackups as $item) {
    $totalBackups += count($item['backups']);
    foreach ($item['backups'] as $backup) {
        $totalSize += $backup['size'];
    }
}

$pageTitle = 'Backups';
$activePage = 'settings';

require __DIR__ . '/includes/header.php';
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Backups</h1>
        <p class="text-gray-600 dark:text-gray-400 mt-1">
            <?php echo $totalBackups; ?> backup<?php echo $totalBackups !== 1 ? 's' : ''; ?>
            across <?php echo count($allBackups); ?> page<?php echo count($allBackups) !== 1 ? 's' : ''; ?>
            (<?php echo formatSize($totalSize); ?> total)
        </p>
    </div>
    <a href="/cms/admin/settings.php" class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300">
        &larr; Back to Settings
    </a>
</div>

<?php if (isset($successMessage)): ?>
    <div class="bg-green-50 dark:bg-green-900/20 border-l-4 border-green-500 p-4 mb-6">
        <p class="text-green-700 dark:text-green-400"><?php echo htmlspecialchars($successMessage); ?></p>
    </div>
<?php endif; ?>

<?php if (isset($errorMessage)): ?>
    <div class="bg-red-50 dark:bg-red-900/20 border-l-4 border-red-500 p-4 mb-6">
        <p class="text-red-700 dark:text-red-400"><?php echo htmlspecialchars($errorMessage); ?></p>
    </div>
<?php endif; ?>

<?php if (empty($allBackups)): ?>
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-8 text-center">
        <svg class="w-16 h-16 mx-auto text-gray-400 dark:text-gray-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
        </svg>
        <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">No Backups Yet</h2>
        <p class="text-gray-600 dark:text-gray-400">
            Backups are created automatically when you save pages or publish blog posts.
        </p>
    </div>
<?php else: ?>
    <div class="space-y-4" x-data="{ expanded: {} }">
        <?php foreach ($allBackups as $index => $item): ?>
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-hidden">
                <!-- Header -->
                <button
                    @click="expanded[<?php echo $index; ?>] = !expanded[<?php echo $index; ?>]"
                    class="w-full px-6 py-4 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-gray-700/50 transition"
                >
                    <div class="flex items-center gap-4">
                        <!-- Icon -->
                        <?php if ($item['type'] === 'blog'): ?>
                            <span class="flex-shrink-0 w-10 h-10 bg-purple-100 dark:bg-purple-900/30 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/>
                                </svg>
                            </span>
                        <?php else: ?>
                            <span class="flex-shrink-0 w-10 h-10 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                            </span>
                        <?php endif; ?>

                        <div class="text-left">
                            <h3 class="font-semibold text-gray-900 dark:text-white">
                                <?php echo htmlspecialchars($item['label']); ?>
                            </h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                <?php echo count($item['backups']); ?> backup<?php echo count($item['backups']) !== 1 ? 's' : ''; ?>
                                &middot;
                                Latest: <?php echo $item['backups'][0]['date_relative']; ?>
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            <?php
                            $itemSize = array_sum(array_column($item['backups'], 'size'));
                            echo formatSize($itemSize);
                            ?>
                        </span>
                        <svg
                            class="w-5 h-5 text-gray-400 transition-transform"
                            :class="expanded[<?php echo $index; ?>] ? 'rotate-180' : ''"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </div>
                </button>

                <!-- Backup List -->
                <div x-show="expanded[<?php echo $index; ?>]" x-collapse>
                    <div class="border-t border-gray-200 dark:border-gray-700">
                        <table class="w-full">
                            <thead class="bg-gray-50 dark:bg-gray-700/50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Size</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($item['backups'] as $backupIndex => $backup): ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                                        <td class="px-6 py-3">
                                            <div class="text-sm text-gray-900 dark:text-white">
                                                <?php echo $backup['date']; ?>
                                            </div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                                <?php echo $backup['date_relative']; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-3 text-sm text-gray-600 dark:text-gray-400">
                                            <?php echo formatSize($backup['size']); ?>
                                        </td>
                                        <td class="px-6 py-3 text-right">
                                            <div class="flex items-center justify-end gap-2">
                                                <!-- View -->
                                                <a
                                                    href="/cms/admin/backup-view.php?page=<?php echo urlencode($item['id']); ?>&ts=<?php echo $backup['timestamp']; ?>"
                                                    target="_blank"
                                                    class="px-3 py-1.5 text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-700 rounded transition"
                                                    title="View backup"
                                                >
                                                    View
                                                </a>

                                                <!-- Restore -->
                                                <form method="POST" class="inline" onsubmit="return confirm('Restore this backup? A backup of the current version will be created first.');">
                                                    <?php echo CSRF::inputField(); ?>
                                                    <input type="hidden" name="action" value="restore">
                                                    <input type="hidden" name="page_id" value="<?php echo htmlspecialchars($item['id']); ?>">
                                                    <input type="hidden" name="timestamp" value="<?php echo $backup['timestamp']; ?>">
                                                    <button
                                                        type="submit"
                                                        class="px-3 py-1.5 text-sm text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded transition"
                                                    >
                                                        Restore
                                                    </button>
                                                </form>

                                                <!-- Delete -->
                                                <form method="POST" class="inline" onsubmit="return confirm('Delete this backup? This cannot be undone.');">
                                                    <?php echo CSRF::inputField(); ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="page_id" value="<?php echo htmlspecialchars($item['id']); ?>">
                                                    <input type="hidden" name="timestamp" value="<?php echo $backup['timestamp']; ?>">
                                                    <button
                                                        type="submit"
                                                        class="px-3 py-1.5 text-sm text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 hover:bg-red-50 dark:hover:bg-red-900/20 rounded transition"
                                                    >
                                                        Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
