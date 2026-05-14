<?php
/**
 * BackupManager - Manages backups of page files.
 *
 * Backups are stored in /cms/backups/{page-id}/index.php.YYYYMMDDHHMMSS
 * Only the most recent N backups are kept per page.
 */

class BackupManager
{
    private string $backupsDir;
    private int $maxBackupsPerPage;

    /**
     * @param string $backupsDir Absolute path to backups directory
     * @param int $maxBackupsPerPage Maximum number of backups to keep per page
     */
    public function __construct(string $backupsDir, int $maxBackupsPerPage = 10)
    {
        $this->backupsDir = rtrim($backupsDir, '/');
        $this->maxBackupsPerPage = $maxBackupsPerPage;
    }

    /**
     * Create a backup of a page file.
     *
     * @param string $pageId Page ID (e.g., "", "about")
     * @param string $filePath Absolute path to the page file
     * @return void
     * @throws Exception if backup fails
     */
    public function createBackup(string $pageId, string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new Exception("Source file not found: {$filePath}");
        }

        // Determine backup directory
        $pageBackupDir = $this->getPageBackupDir($pageId);

        // Create backup directory if it doesn't exist
        if (!is_dir($pageBackupDir)) {
            if (!mkdir($pageBackupDir, 0755, true)) {
                throw new Exception("Failed to create backup directory: {$pageBackupDir}");
            }
        }

        // Create backup filename with timestamp
        $timestamp = date('YmdHis');
        $backupPath = $pageBackupDir . '/index.php.' . $timestamp;

        // Copy file to backup
        if (!copy($filePath, $backupPath)) {
            throw new Exception("Failed to create backup");
        }

        // Prune old backups
        $this->pruneOldBackups($pageId);
    }

    /**
     * List all backups for a page.
     *
     * @param string $pageId Page ID
     * @return array Array of backup info, each with 'timestamp' and 'path'
     */
    public function listBackups(string $pageId): array
    {
        $pageBackupDir = $this->getPageBackupDir($pageId);

        if (!is_dir($pageBackupDir)) {
            return [];
        }

        $backups = [];
        $files = glob($pageBackupDir . '/index.php.*');

        foreach ($files as $file) {
            $filename = basename($file);
            if (preg_match('/index\.php\.(\d{14})$/', $filename, $matches)) {
                $timestamp = $matches[1];
                $backups[] = [
                    'timestamp' => $timestamp,
                    'path' => $file,
                    'date' => $this->formatTimestamp($timestamp),
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
     * Restore a backup to the original page file.
     *
     * @param string $pageId Page ID
     * @param string $timestamp Backup timestamp (YYYYMMDDHHMMSS)
     * @param string $targetPath Target file path to restore to
     * @return void
     * @throws Exception if backup not found or restore fails
     */
    public function restoreBackup(string $pageId, string $timestamp, string $targetPath): void
    {
        $pageBackupDir = $this->getPageBackupDir($pageId);
        $backupPath = $pageBackupDir . '/index.php.' . $timestamp;

        if (!file_exists($backupPath)) {
            throw new Exception("Backup not found: {$timestamp}");
        }

        // Create a backup of the current file before restoring
        if (file_exists($targetPath)) {
            $this->createBackup($pageId, $targetPath);
        }

        // Restore the backup
        if (!copy($backupPath, $targetPath)) {
            throw new Exception("Failed to restore backup");
        }
    }

    /**
     * Get the backup directory for a specific page.
     *
     * @param string $pageId Page ID
     * @return string Absolute path to the page's backup directory
     */
    private function getPageBackupDir(string $pageId): string
    {
        // Canonicalize homepage aliases ("" and "index") to the same bucket
        // so block edits, AI edits and file-edits don't fan out into
        // separate directories.
        if ($pageId === '' || $pageId === 'index') {
            return $this->backupsDir . '/home';
        }

        return $this->backupsDir . '/' . $pageId;
    }

    /**
     * Remove old backups, keeping only the most recent N.
     *
     * @param string $pageId Page ID
     * @return void
     */
    private function pruneOldBackups(string $pageId): void
    {
        $backups = $this->listBackups($pageId);

        if (count($backups) <= $this->maxBackupsPerPage) {
            return;
        }

        // Remove oldest backups
        $toRemove = array_slice($backups, $this->maxBackupsPerPage);

        foreach ($toRemove as $backup) {
            @unlink($backup['path']);
        }
    }

    /**
     * Format a timestamp string for display.
     *
     * @param string $timestamp Timestamp in YmdHis format
     * @return string Formatted date string
     */
    private function formatTimestamp(string $timestamp): string
    {
        $dt = DateTime::createFromFormat('YmdHis', $timestamp);
        return $dt ? $dt->format('Y-m-d H:i:s') : $timestamp;
    }
}
