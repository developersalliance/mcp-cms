<?php
/**
 * SitemapGenerator - Generates sitemap.xml for the CMS
 *
 * Automatically creates a valid sitemap.xml with all published pages and blog posts.
 */

class SitemapGenerator
{
    private string $rootDir;
    private string $baseUrl;
    private array $reservedFolders;
    private ?string $blogDraftsDir;
    /** Optional — when set, scanPages() delegates to its listPages()
     *  instead of running its own filesystem walk. PageManager wires
     *  this back automatically when it receives a SitemapGenerator. */
    private $pageManager = null;

    public function __construct(string $rootDir, string $baseUrl, array $reservedFolders = ['cms'], ?string $blogDraftsDir = null)
    {
        $this->rootDir = rtrim($rootDir, '/');
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->reservedFolders = $reservedFolders;
        $this->blogDraftsDir = $blogDraftsDir;
    }

    public function setPageManager($pm): void
    {
        $this->pageManager = $pm;
    }

    /**
     * Generate and save sitemap.xml
     *
     * @return bool True on success, false on failure
     * @throws Exception if sitemap cannot be written
     */
    public function generate(): bool
    {
        $urls = [];

        // Add all published pages
        $pages = $this->scanPages();
        foreach ($pages as $page) {
            $urls[] = [
                'loc' => $this->baseUrl . '/' . $page['path'],
                'lastmod' => $page['lastmod'],
                'priority' => $page['path'] === '' ? '1.0' : '0.8',
            ];
        }

        // Add all published blog posts
        $posts = $this->scanBlogPosts();
        foreach ($posts as $post) {
            $urls[] = [
                'loc' => $this->baseUrl . '/' . $post['path'],
                'lastmod' => $post['lastmod'],
                'priority' => '0.7',
            ];
        }

        // Generate XML
        $xml = $this->generateXml($urls);

        // Write to sitemap.xml
        $sitemapPath = $this->rootDir . '/sitemap.xml';
        if (file_put_contents($sitemapPath, $xml) === false) {
            throw new Exception('Failed to write sitemap.xml');
        }

        return true;
    }

    /**
     * Scan for all published pages
     *
     * @return array Array of pages with path and lastmod
     */
    private function scanPages(): array
    {
        // Delegate to PageManager when wired up — single filesystem walk
        // per page mutation instead of two (one for pages list, one here).
        if ($this->pageManager !== null && method_exists($this->pageManager, 'listPages')) {
            $out = [];
            foreach ($this->pageManager->listPages() as $p) {
                $pagePath = $p['path'] ?? '';
                $id = $p['id'] ?? '';
                if (!$pagePath || !file_exists($pagePath)) continue;
                $sitemapPath = ($id === 'index' || $id === '') ? '' : $id;
                $out[] = [
                    'path' => $sitemapPath,
                    'lastmod' => date('c', filemtime($pagePath)),
                ];
            }
            return $out;
        }

        // Fallback for callers that haven't wired the PageManager back —
        // local recursive walk, same as before.
        $pages = [];
        $rootIndexPath = $this->rootDir . '/index.php';
        if (file_exists($rootIndexPath)) {
            $pages[] = [
                'path' => '',
                'lastmod' => date('c', filemtime($rootIndexPath)),
            ];
        }
        $this->scanDirectory($this->rootDir, '', $pages);
        return $pages;
    }

    /**
     * Recursively scan directory for pages
     *
     * @param string $dir Current directory path
     * @param string $relativePath Relative path from root
     * @param array &$pages Reference to pages array
     */
    private function scanDirectory(string $dir, string $relativePath, array &$pages): void
    {
        $items = scandir($dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            // Skip reserved folders
            if ($relativePath === '' && in_array($item, $this->reservedFolders)) {
                continue;
            }

            $itemPath = $dir . '/' . $item;
            $newRelativePath = $relativePath === '' ? $item : $relativePath . '/' . $item;

            if (is_dir($itemPath)) {
                // Check if this directory has an index.php
                $indexPath = $itemPath . '/index.php';
                if (file_exists($indexPath)) {
                    $pages[] = [
                        'path' => $newRelativePath,
                        'lastmod' => date('c', filemtime($indexPath)),
                    ];
                }

                // Recurse into subdirectories
                $this->scanDirectory($itemPath, $newRelativePath, $pages);
            }
        }
    }

    /**
     * Scan for all published blog posts
     *
     * @return array Array of posts with path and lastmod
     */
    private function scanBlogPosts(): array
    {
        $posts = [];

        // Load collections
        $collectionsFile = dirname($this->blogDraftsDir ?: $this->rootDir . '/cms/drafts') . '/config/collections.json';
        if (!file_exists($collectionsFile)) {
            return $posts;
        }

        $collections = json_decode(file_get_contents($collectionsFile), true) ?? [];

        foreach ($collections as $collection) {
            $collectionPath = $this->rootDir . '/' . $collection['base_path'];

            if (!is_dir($collectionPath)) {
                continue;
            }

            $items = scandir($collectionPath);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $itemPath = $collectionPath . '/' . $item;
                $indexPath = $itemPath . '/index.php';

                if (is_dir($itemPath) && file_exists($indexPath)) {
                    $posts[] = [
                        'path' => $collection['base_path'] . '/' . $item,
                        'lastmod' => date('c', filemtime($indexPath)),
                    ];
                }
            }

            // Add collection index page if exists
            $collectionIndex = $collectionPath . '/index.php';
            if (file_exists($collectionIndex)) {
                $posts[] = [
                    'path' => $collection['base_path'],
                    'lastmod' => date('c', filemtime($collectionIndex)),
                ];
            }
        }

        return $posts;
    }

    /**
     * Generate XML sitemap from URLs
     *
     * @param array $urls Array of URL data
     * @return string XML content
     */
    private function generateXml(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . htmlspecialchars($url['loc']) . '</loc>' . "\n";
            $xml .= '    <lastmod>' . $url['lastmod'] . '</lastmod>' . "\n";
            $xml .= '    <priority>' . $url['priority'] . '</priority>' . "\n";
            $xml .= '  </url>' . "\n";
        }

        $xml .= '</urlset>';

        return $xml;
    }
}
