<?php
/**
 * GlobalBackupManager - Manages grouped backups for global block updates.
 *
 * When a global block (without custom=1) is updated, all affected pages
 * are backed up together in a single group. This allows restoring all
 * pages at once if needed.
 *
 * Storage: /cms/backups/_global/{timestamp}/
 *   - manifest.json - metadata about the update
 *   - {page-id}/index.php - backup of each affected page
 */

class GlobalBackupManager
{
    private string $globalBackupsDir;
    private int $maxBackups;

    /**
     * @param string $backupsDir Base backups directory
     * @param int $maxBackups Maximum number of global backups to keep
     */
    public function __construct(string $backupsDir, int $maxBackups = 10)
    {
        $this->globalBackupsDir = rtrim($backupsDir, '/') . '/_global';
        $this->maxBackups = $maxBackups;
    }

    /**
     * Create a global backup before updating multiple pages.
     *
     * @param array $pagesToBackup Associative array ['page_id' => 'file_path', ...]
     * @param string $blockName Name of the block being updated
     * @param string $description Optional description of the change
     * @return string Timestamp of the created backup
     * @throws Exception if backup creation fails
     */
    public function createGlobalBackup(
        array $pagesToBackup,
        string $blockName,
        string $description = ''
    ): string {
        $timestamp = date('YmdHis');
        $backupDir = $this->globalBackupsDir . '/' . $timestamp;

        // Create backup directory
        if (!mkdir($backupDir, 0755, true)) {
            throw new Exception("Failed to create global backup directory: {$backupDir}");
        }

        // Create manifest
        $manifest = [
            'timestamp' => $timestamp,
            'date' => date('Y-m-d H:i:s'),
            'block_name' => $blockName,
            'description' => $description,
            'pages' => []
        ];

        // Backup each page
        foreach ($pagesToBackup as $pageId => $filePath) {
            if (!file_exists($filePath)) {
                continue;
            }

            // Use 'home' for empty page ID (homepage)
            $pageDirName = $pageId === '' ? 'home' : str_replace('/', '_', $pageId);
            $pageBackupDir = $backupDir . '/' . $pageDirName;

            if (!mkdir($pageBackupDir, 0755, true)) {
                continue;
            }

            if (copy($filePath, $pageBackupDir . '/index.php')) {
                $manifest['pages'][] = $pageId;
            }
        }

        // Save manifest
        file_put_contents(
            $backupDir . '/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT)
        );

        // Prune old global backups
        $this->pruneOldBackups();

        return $timestamp;
    }

    /**
     * List all global backups.
     *
     * @return array Array of backup manifests with path included
     */
    public function listGlobalBackups(): array
    {
        if (!is_dir($this->globalBackupsDir)) {
            return [];
        }

        $backups = [];
        $dirs = glob($this->globalBackupsDir . '/*', GLOB_ONLYDIR);

        foreach ($dirs as $dir) {
            $manifestPath = $dir . '/manifest.json';
            if (file_exists($manifestPath)) {
                $manifest = json_decode(file_get_contents($manifestPath), true);
                if ($manifest) {
                    $manifest['path'] = $dir;
                    $backups[] = $manifest;
                }
            }
        }

        // Sort by timestamp descending (newest first)
        usort($backups, function ($a, $b) {
            return strcmp($b['timestamp'], $a['timestamp']);
        });

        return $backups;
    }

    /**
     * Get a specific global backup by timestamp.
     *
     * @param string $timestamp Backup timestamp
     * @return array|null Backup manifest or null if not found
     */
    public function getGlobalBackup(string $timestamp): ?array
    {
        $backupDir = $this->globalBackupsDir . '/' . $timestamp;
        $manifestPath = $backupDir . '/manifest.json';

        if (!file_exists($manifestPath)) {
            return null;
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        if ($manifest) {
            $manifest['path'] = $backupDir;
        }

        return $manifest;
    }

    /**
     * Restore a global backup, reverting all affected pages at once.
     *
     * @param string $timestamp Backup timestamp
     * @param PageManager $pageManager PageManager instance for getting page paths
     * @return array Results with 'restored' and 'failed' arrays
     * @throws Exception if backup not found
     */
    public function restoreGlobalBackup(string $timestamp, PageManager $pageManager): array
    {
        $backupDir = $this->globalBackupsDir . '/' . $timestamp;
        $manifestPath = $backupDir . '/manifest.json';

        if (!file_exists($manifestPath)) {
            throw new Exception("Global backup not found: {$timestamp}");
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!$manifest) {
            throw new Exception("Invalid backup manifest: {$timestamp}");
        }

        $results = ['restored' => [], 'failed' => []];

        foreach ($manifest['pages'] as $pageId) {
            // Get the backup file path
            $pageDirName = $pageId === '' ? 'home' : str_replace('/', '_', $pageId);
            $backupFile = $backupDir . '/' . $pageDirName . '/index.php';

            // Get the target path
            $targetPath = $pageManager->getPagePath($pageId);

            if (!file_exists($backupFile)) {
                $results['failed'][] = ['page_id' => $pageId, 'reason' => 'Backup file not found'];
                continue;
            }

            if (!$targetPath) {
                $results['failed'][] = ['page_id' => $pageId, 'reason' => 'Page no longer exists'];
                continue;
            }

            // Restore the file
            if (copy($backupFile, $targetPath)) {
                $results['restored'][] = $pageId;
            } else {
                $results['failed'][] = ['page_id' => $pageId, 'reason' => 'Copy failed'];
            }
        }

        return $results;
    }

    /**
     * Remove old global backups, keeping only the most recent N.
     */
    private function pruneOldBackups(): void
    {
        $backups = $this->listGlobalBackups();

        if (count($backups) <= $this->maxBackups) {
            return;
        }

        // Remove oldest backups
        $toRemove = array_slice($backups, $this->maxBackups);

        foreach ($toRemove as $backup) {
            if (isset($backup['path'])) {
                $this->deleteDirectory($backup['path']);
            }
        }
    }

    /**
     * Recursively delete a directory.
     *
     * @param string $dir Directory path
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
