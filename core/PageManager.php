<?php
/**
 * PageManager - Scans and manages page folders in the flat-file CMS.
 *
 * Pages are directories containing index.php files.
 * Page ID = relative path without leading slash (e.g., "", "about", "about/team")
 */

class PageManager
{
    private string $rootDir;
    private array $reservedFolders;
    private array $reservedFoldersLower;
    private string $draftsDir;
    private $backupManager;
    private $sitemapGenerator;
    private $pageSettings;
    /** Per-request cache for listPages(). Busted by createPageFromHtml,
     *  duplicatePage, deletePage, publishDraft. */
    private ?array $pagesCache = null;

    /**
     * @param string $rootDir Absolute path to the web root
     * @param array $reservedFolders List of reserved folder names
     * @param string|null $draftsDir Absolute path to drafts directory (optional)
     * @param object|null $backupManager Optional BackupManager instance for creating backups
     * @param object|null $sitemapGenerator Optional SitemapGenerator instance for updating sitemap
     * @param object|null $pageSettings Optional PageSettings instance for managing page settings
     */
    public function __construct(string $rootDir, array $reservedFolders = ['cms'], ?string $draftsDir = null, $backupManager = null, $sitemapGenerator = null, $pageSettings = null)
    {
        $this->rootDir = rtrim($rootDir, '/');
        $this->reservedFolders = $reservedFolders;
        $this->reservedFoldersLower = array_map('strtolower', $reservedFolders);
        $this->draftsDir = $draftsDir ? rtrim($draftsDir, '/') . '/pages' : '';
        $this->backupManager = $backupManager;
        $this->sitemapGenerator = $sitemapGenerator;
        $this->pageSettings = $pageSettings;
        // Wire back into the sitemap so it can reuse our memoized
        // listPages() instead of duplicating the recursive walk.
        if ($sitemapGenerator && method_exists($sitemapGenerator, 'setPageManager')) {
            $sitemapGenerator->setPageManager($this);
        }
    }

    /**
     * List all pages in the content tree.
     *
     * @return array Array of pages, each with 'id' and 'path'
     */
    public function listPages(): array
    {
        if ($this->pagesCache !== null) return $this->pagesCache;

        $pages = [];

        // Add root page if it exists
        $rootIndexPath = $this->rootDir . '/index.php';
        if (file_exists($rootIndexPath)) {
            $pages[] = [
                'id' => 'index',
                'path' => $rootIndexPath,
            ];
        }

        // Recursively scan for page directories
        $this->scanDirectory($this->rootDir, '', $pages);

        // Sort by ID
        usort($pages, function ($a, $b) {
            return strcmp($a['id'], $b['id']);
        });

        $this->pagesCache = $pages;
        return $pages;
    }

    /** Bust the listPages() memoization. Called by every mutator. */
    private function invalidatePagesCache(): void
    {
        $this->pagesCache = null;
    }

    /**
     * Case-insensitive check against $reservedFolders. macOS / Windows
     * filesystems are case-insensitive, so "CMS" must collide with "cms".
     */
    private function isReservedFolder(string $segment): bool
    {
        return in_array(strtolower($segment), $this->reservedFoldersLower, true);
    }

    /**
     * Validate a page_id at ingress. Rejects null bytes, parent-directory
     * traversal, leading-dot segments (hidden files), and any characters
     * outside the safe charset. Empty string is allowed (homepage alias).
     *
     * @throws Exception on any rejection
     */
    private function validatePageId(string $pageId): void
    {
        if (strpos($pageId, "\0") !== false) {
            throw new Exception('Invalid page_id');
        }
        if (strpos($pageId, '..') !== false) {
            throw new Exception('Invalid page_id');
        }
        $trimmed = trim($pageId, '/');
        if ($trimmed !== '') {
            foreach (explode('/', $trimmed) as $segment) {
                if ($segment === '' || $segment[0] === '.') {
                    throw new Exception('Invalid page_id');
                }
            }
        }
        if (!preg_match('#^[a-z0-9_\-/]*$#', $pageId)) {
            throw new Exception('Invalid page_id');
        }
    }

    /**
     * Resolve a real (realpath) filesystem path back to the page_id of the
     * CMS page that owns it (its index.php), or null if it doesn't match
     * any page. Uses listPages() so it benefits from the memo cache.
     */
    public function resolvePageIdByPath(string $realFilePath): ?string
    {
        foreach ($this->listPages() as $p) {
            $rp = @realpath($p['path']);
            if ($rp !== false && $rp === $realFilePath) {
                return $p['id'];
            }
        }
        return null;
    }

    /**
     * Get the file path for a specific page ID.
     *
     * @param string $pageId Page ID (e.g., "", "about", "about/team")
     * @return string|null Absolute path to index.php, or null if not found
     */
    public function getPagePath(string $pageId): ?string
    {
        $pageId = trim($pageId, '/');

        // Validate against path traversal attacks
        if (strpos($pageId, '..') !== false) {
            return null;
        }

        if ($pageId === '' || $pageId === 'index') {
            $path = $this->rootDir . '/index.php';
        } else {
            $path = $this->rootDir . '/' . $pageId . '/index.php';
        }

        // Ensure the resolved path is within the root directory
        if (file_exists($path)) {
            $realPath = realpath($path);
            $realRoot = realpath($this->rootDir);

            // Check if the resolved path is within the root directory
            if ($realPath && $realRoot && strpos($realPath, $realRoot) === 0) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Check if a page exists.
     *
     * @param string $pageId Page ID
     * @return bool True if page exists
     */
    public function pageExists(string $pageId): bool
    {
        return $this->getPagePath($pageId) !== null;
    }

    /**
     * Create a new page by duplicating an existing one.
     *
     * @param string $sourcePageId Source page ID to duplicate
     * @param string $newPageId New page ID
     * @return void
     * @throws Exception if source doesn't exist or target already exists
     */
    public function duplicatePage(string $sourcePageId, string $newPageId): void
    {
        $this->validatePageId($sourcePageId);
        $this->validatePageId($newPageId);

        $sourcePath = $this->getPagePath($sourcePageId);
        if (!$sourcePath) {
            throw new Exception("Source page '{$sourcePageId}' not found");
        }

        if ($this->pageExists($newPageId)) {
            throw new Exception("Target page '{$newPageId}' already exists");
        }

        // Check against reserved folder names (case-insensitive)
        $pageIdParts = explode('/', trim($newPageId, '/'));
        $firstPart = $pageIdParts[0] ?? '';
        if ($this->isReservedFolder($firstPart)) {
            throw new Exception("Cannot use reserved folder name '{$firstPart}' as page ID");
        }

        $newPageId = trim($newPageId, '/');
        $targetDir = $newPageId === '' ? $this->rootDir : $this->rootDir . '/' . $newPageId;

        // Create target directory if needed
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                throw new Exception("Failed to create directory: {$targetDir}");
            }
        }

        $targetPath = $targetDir . '/index.php';

        // Copy the file
        if (!copy($sourcePath, $targetPath)) {
            throw new Exception("Failed to copy page");
        }

        // Copy page settings if they exist
        if ($this->pageSettings) {
            try {
                $this->pageSettings->copySettings($sourcePageId, $newPageId);
            } catch (Exception $e) {
                // Settings copy failed, but don't stop duplication
                error_log("Failed to copy page settings: " . $e->getMessage());
            }
        }

        $this->regenerateSitemap();
    }

    /**
     * Internal helper: best-effort sitemap regeneration. Called from every
     * mutation that changes the public page set (create / duplicate / delete
     * / publish). Never throws — failures are logged so the original
     * operation isn't blocked by a sitemap glitch.
     */
    private function regenerateSitemap(): void
    {
        // Any operation that triggers a sitemap rebuild also changed the
        // page list — bust the in-memory cache.
        $this->invalidatePagesCache();
        if (!$this->sitemapGenerator) return;
        try {
            $this->sitemapGenerator->generate();
        } catch (Exception $e) {
            error_log("Sitemap regeneration failed: " . $e->getMessage());
        }
    }

    /**
     * Create a new page from HTML content.
     *
     * @param string $pageId New page ID
     * @param string $htmlContent HTML content for the page
     * @return void
     * @throws Exception if page already exists or creation fails
     */
    public function createPageFromHtml(string $pageId, string $htmlContent): void
    {
        $this->validatePageId($pageId);

        if ($this->pageExists($pageId)) {
            throw new Exception("Page '{$pageId}' already exists");
        }

        // Check against reserved folder names (case-insensitive)
        $pageIdParts = explode('/', trim($pageId, '/'));
        $firstPart = $pageIdParts[0] ?? '';
        if ($this->isReservedFolder($firstPart)) {
            throw new Exception("Cannot use reserved folder name '{$firstPart}' as page ID");
        }

        $pageId = trim($pageId, '/');
        $targetDir = $pageId === '' ? $this->rootDir : $this->rootDir . '/' . $pageId;

        // Create target directory if needed
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                throw new Exception("Failed to create directory: {$targetDir}");
            }
        }

        $targetPath = $targetDir . '/index.php';

        // Ensure HTML starts with <?php tag if it doesn't already
        if (strpos(trim($htmlContent), '<?php') !== 0) {
            $htmlContent = "<?php\n// Page created via CMS\n\n?>" . $htmlContent;
        }

        // Write the HTML content to the file
        if (file_put_contents($targetPath, $htmlContent) === false) {
            throw new Exception("Failed to create page file");
        }

        // Auto-extract CSS from HTML and save to page settings
        if ($this->pageSettings) {
            try {
                $extractedCSS = $this->extractCSSFromHTML($htmlContent);
                if (!empty($extractedCSS)) {
                    $this->pageSettings->saveSettings($pageId, [
                        'custom_css' => $extractedCSS
                    ]);
                }
            } catch (Exception $e) {
                // CSS extraction failed, but don't stop page creation
                error_log("Failed to extract CSS during page creation: " . $e->getMessage());
            }
        }

        $this->regenerateSitemap();
    }

    /**
     * Extract CSS links and style tags from HTML content
     *
     * @param string $html HTML content
     * @return string Extracted CSS (links and style tags)
     */
    private function extractCSSFromHTML(string $html): string
    {
        $extracted = [];

        // Extract <link rel="stylesheet"> tags
        preg_match_all('/<link[^>]*rel=["\']stylesheet["\'][^>]*>/i', $html, $linkMatches);
        if (!empty($linkMatches[0])) {
            $extracted = array_merge($extracted, $linkMatches[0]);
        }

        // Extract <style> tags with content
        preg_match_all('/<style[^>]*>.*?<\/style>/is', $html, $styleMatches);
        if (!empty($styleMatches[0])) {
            $extracted = array_merge($extracted, $styleMatches[0]);
        }

        return implode("\n\n", $extracted);
    }

    /**
     * Delete a page.
     *
     * @param string $pageId Page ID to delete
     * @return void
     * @throws Exception if page doesn't exist or cannot be deleted
     */
    public function deletePage(string $pageId): void
    {
        $this->validatePageId($pageId);

        $pagePath = $this->getPagePath($pageId);
        if (!$pagePath) {
            throw new Exception("Page '{$pageId}' not found");
        }

        // Delete the index.php file
        if (!unlink($pagePath)) {
            throw new Exception("Failed to delete page file");
        }

        // Delete page settings if they exist
        if ($this->pageSettings) {
            try {
                $this->pageSettings->deleteSettings($pageId);
            } catch (Exception $e) {
                // Settings deletion failed, but don't stop page deletion
                error_log("Failed to delete page settings: " . $e->getMessage());
            }
        }

        // Try to remove the directory if empty (but don't fail if not empty)
        if ($pageId !== '') {
            $pageDir = dirname($pagePath);
            @rmdir($pageDir);
        }

        $this->regenerateSitemap();
    }

    /**
     * Recursively scan a directory for page folders.
     *
     * @param string $dir Absolute directory path
     * @param string $relPath Relative path from root (for building page IDs)
     * @param array &$pages Reference to pages array to populate
     * @return void
     */
    private function scanDirectory(string $dir, string $relPath, array &$pages): void
    {
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            // Skip reserved folders (case-insensitive)
            if ($this->isReservedFolder($item)) {
                continue;
            }

            $itemPath = $dir . '/' . $item;

            if (is_dir($itemPath)) {
                // Check if this directory has an index.php
                $indexPath = $itemPath . '/index.php';
                if (file_exists($indexPath)) {
                    $pageId = $relPath === '' ? $item : $relPath . '/' . $item;
                    $pages[] = [
                        'id' => $pageId,
                        'path' => $indexPath,
                    ];
                }

                // Recursively scan subdirectories
                $newRelPath = $relPath === '' ? $item : $relPath . '/' . $item;
                $this->scanDirectory($itemPath, $newRelPath, $pages);
            }
        }
    }

    /**
     * Get the draft file path for a page ID.
     *
     * @param string $pageId Page ID
     * @return string|null Draft file path, or null if drafts not configured
     */
    public function getDraftPath(string $pageId): ?string
    {
        if (!$this->draftsDir) {
            return null;
        }

        $pageId = trim($pageId, '/');
        // Homepage has multiple aliases ("", "index", "/") — getPagePath()
        // resolves them to the same live file already, so the draft must
        // also share one canonical filename. Otherwise callers using
        // different aliases create parallel out-of-sync drafts (MCP / AI
        // edit normalizes to "" while listPages reports id="index" and the
        // pages list ends up looking for the wrong file).
        $isHomepage = ($pageId === '' || $pageId === 'index');
        $safeName = $isHomepage ? '__homepage__' : str_replace('/', '__', $pageId);
        return $this->draftsDir . '/' . $safeName . '.php';
    }

    /**
     * Check if a page has a draft.
     *
     * @param string $pageId Page ID
     * @return bool True if draft exists
     */
    public function hasDraft(string $pageId): bool
    {
        $draftPath = $this->getDraftPath($pageId);
        return $draftPath && file_exists($draftPath);
    }

    /**
     * One-shot "what should I show for this page": draft content if a draft
     * exists, otherwise the published file's content. Replaces the
     * hasDraft() + getDraft() + file_get_contents() dance that 12 call
     * sites repeat. Single stat + single read.
     *
     * @return array{content: string, is_draft: bool}|null
     */
    public function loadCurrentPageContent(string $pageId, ?string $pagePath = null): ?array
    {
        $draftPath = $this->getDraftPath($pageId);
        if ($draftPath && is_file($draftPath)) {
            $body = @file_get_contents($draftPath);
            if ($body === false) return null;
            return ['content' => $body, 'is_draft' => true];
        }
        if ($pagePath === null) $pagePath = $this->getPagePath($pageId);
        if (!$pagePath || !is_file($pagePath)) return null;
        $body = @file_get_contents($pagePath);
        if ($body === false) return null;
        return ['content' => $body, 'is_draft' => false];
    }

    /**
     * Save page content as a draft.
     *
     * @param string $pageId Page ID
     * @param string $content Page content
     * @return void
     * @throws Exception if drafts not configured or save fails
     */
    public function saveDraft(string $pageId, string $content): void
    {
        $draftPath = $this->getDraftPath($pageId);
        if (!$draftPath) {
            throw new Exception('Drafts directory not configured');
        }

        // Ensure drafts directory exists
        if (!is_dir($this->draftsDir)) {
            if (!mkdir($this->draftsDir, 0755, true)) {
                throw new Exception('Failed to create drafts directory');
            }
        }

        // Save draft
        if (file_put_contents($draftPath, $content) === false) {
            throw new Exception('Failed to save draft');
        }
    }

    /**
     * Get draft content for a page.
     *
     * @param string $pageId Page ID
     * @return string|null Draft content, or null if no draft exists
     */
    public function getDraft(string $pageId): ?string
    {
        if (!$this->hasDraft($pageId)) {
            return null;
        }

        $draftPath = $this->getDraftPath($pageId);
        $content = file_get_contents($draftPath);
        return $content !== false ? $content : null;
    }

    /**
     * Publish a draft to the live page.
     *
     * @param string $pageId Page ID
     * @return void
     * @throws Exception if no draft exists or publish fails
     */
    public function publishDraft(string $pageId): void
    {
        if (!$this->hasDraft($pageId)) {
            throw new Exception("No draft exists for page '{$pageId}'");
        }

        $draftPath = $this->getDraftPath($pageId);
        $draftContent = file_get_contents($draftPath);

        if ($draftContent === false) {
            throw new Exception('Failed to read draft content');
        }

        // Get or create live page path
        $pageId = trim($pageId, '/');
        if ($pageId === '' || $pageId === 'index') {
            $livePath = $this->rootDir . '/index.php';
        } else {
            $liveDir = $this->rootDir . '/' . $pageId;

            // Create directory if it doesn't exist
            if (!is_dir($liveDir)) {
                if (!mkdir($liveDir, 0755, true)) {
                    throw new Exception('Failed to create page directory');
                }
            }

            $livePath = $liveDir . '/index.php';
        }

        // Create backup of current live page before overwriting
        if (file_exists($livePath) && $this->backupManager) {
            try {
                $this->backupManager->createBackup($pageId, $livePath);
            } catch (Exception $e) {
                // Backup failed, but don't stop publishing
                error_log("Backup failed during publish: " . $e->getMessage());
            }
        }

        // Write to live page
        if (file_put_contents($livePath, $draftContent) === false) {
            throw new Exception('Failed to publish draft to live page');
        }

        // Delete the draft
        @unlink($draftPath);

        $this->regenerateSitemap();
    }

    /**
     * Discard a draft.
     *
     * @param string $pageId Page ID
     * @return void
     * @throws Exception if no draft exists or delete fails
     */
    public function discardDraft(string $pageId): void
    {
        if (!$this->hasDraft($pageId)) {
            throw new Exception("No draft exists for page '{$pageId}'");
        }

        $draftPath = $this->getDraftPath($pageId);
        if (!unlink($draftPath)) {
            throw new Exception('Failed to discard draft');
        }
    }

    /**
     * Get settings for a page.
     *
     * @param string $pageId Page ID
     * @return array Page settings
     */
    public function getPageSettings(string $pageId): array
    {
        if (!$this->pageSettings) {
            return [
                'custom_styles' => '',
                'custom_stylesheets' => [],
                'created_at' => null,
                'updated_at' => null
            ];
        }

        return $this->pageSettings->getSettings($pageId);
    }

    /**
     * Save settings for a page.
     *
     * @param string $pageId Page ID
     * @param array $settings Settings to save
     * @return void
     * @throws Exception if settings manager not configured or save fails
     */
    public function savePageSettings(string $pageId, array $settings): void
    {
        if (!$this->pageSettings) {
            throw new Exception('Page settings manager not configured');
        }

        $this->pageSettings->saveSettings($pageId, $settings);
    }

    /**
     * Check if a page has settings.
     *
     * @param string $pageId Page ID
     * @return bool True if page has settings
     */
    public function hasPageSettings(string $pageId): bool
    {
        if (!$this->pageSettings) {
            return false;
        }

        return $this->pageSettings->hasSettings($pageId);
    }
}
