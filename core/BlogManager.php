<?php

class BlogManager
{
    private string $rootDir;
    private string $contentDir;
    private string $collectionsFile;
    private string $templatesFile;
    private array $collections;
    private array $templates;
    private $sitemapGenerator;
    private $backupManager;

    public function __construct(string $rootDir, string $cmsDir, $sitemapGenerator = null, $backupManager = null)
    {
        $this->rootDir = rtrim($rootDir, '/');
        $this->contentDir = rtrim($cmsDir, '/') . '/content';
        $this->collectionsFile = rtrim($cmsDir, '/') . '/config/collections.json';
        $this->templatesFile = rtrim($cmsDir, '/') . '/config/collection-templates.json';
        $this->sitemapGenerator = $sitemapGenerator;
        $this->backupManager = $backupManager;
        $this->loadCollections();
        $this->loadTemplates();
    }

    private function loadCollections(): void
    {
        if (file_exists($this->collectionsFile)) {
            $this->collections = json_decode(file_get_contents($this->collectionsFile), true) ?? [];
        } else {
            $this->collections = [['id' => 'blog', 'base_path' => 'blog', 'label' => 'Blog']];
        }
    }

    private function loadTemplates(): void
    {
        $this->templates = file_exists($this->templatesFile)
            ? (json_decode(file_get_contents($this->templatesFile), true) ?? [])
            : [];
    }

    // --- Post CRUD ---

    public function createPost(string $collectionId, string $slug, array $data = []): array
    {
        $collection = $this->requireCollection($collectionId);
        $slug = $this->sanitizeSlug($slug);
        $path = $this->postPath($collectionId, $slug);

        if (file_exists($path)) {
            throw new Exception("Post already exists: {$slug}");
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $post = array_merge($this->getDefaultPost($slug, $collectionId), $data);
        $post['slug'] = $slug;
        $post['created_at'] = date('Y-m-d');
        $post['modified_at'] = date('Y-m-d');

        $this->savePostJson($path, $post);
        return $post;
    }

    public function getPost(string $collectionId, string $slug): ?array
    {
        $path = $this->postPath($collectionId, $slug);
        if (!file_exists($path)) {
            return null;
        }
        return json_decode(file_get_contents($path), true);
    }

    public function savePost(string $collectionId, string $slug, array $post): void
    {
        $path = $this->postPath($collectionId, $slug);
        if (!file_exists($path)) {
            throw new Exception("Post not found: {$slug}");
        }
        $post['modified_at'] = date('Y-m-d');
        $this->savePostJson($path, $post);
    }

    public function deletePost(string $collectionId, string $slug): void
    {
        $this->requireCollection($collectionId);
        $path = $this->postPath($collectionId, $slug);

        if (!file_exists($path)) {
            throw new Exception("Post not found: {$slug}");
        }

        $post = json_decode(file_get_contents($path), true);

        // Remove published stub if exists
        if (($post['status'] ?? '') === 'published') {
            $this->removeStub($collectionId, $slug);
        }

        unlink($path);
        $this->regenerateListStub($collectionId);
        $this->regenerateSitemap();
    }

    public function listPosts(string $collectionId, array $filters = []): array
    {
        $this->requireCollection($collectionId);
        $dir = $this->contentDir . '/' . $collectionId;

        if (!is_dir($dir)) {
            return [];
        }

        $posts = [];
        foreach (glob($dir . '/*.json') as $file) {
            $post = json_decode(file_get_contents($file), true);
            if (!$post) continue;

            // Apply filters
            if (!empty($filters['status']) && ($post['status'] ?? 'draft') !== $filters['status']) continue;
            if (!empty($filters['author_id']) && ($post['author_id'] ?? '') !== $filters['author_id']) continue;
            if (!empty($filters['tag'])) {
                $tags = array_map('strtolower', $post['tags'] ?? []);
                if (!in_array(strtolower($filters['tag']), $tags)) continue;
            }
            if (!empty($filters['category'])) {
                // Categories are stored as [{id, slug, name_snapshot}]; the
                // filter value may be an id or a slug (URL ?category=tech).
                $needle = strtolower((string)$filters['category']);
                $match = false;
                foreach ($post['categories'] ?? [] as $c) {
                    if (is_array($c)) {
                        if (strtolower((string)($c['id'] ?? '')) === $needle) { $match = true; break; }
                        if (strtolower((string)($c['slug'] ?? '')) === $needle) { $match = true; break; }
                    }
                }
                if (!$match) continue;
            }

            $posts[] = $post;
        }

        // Sort: featured first, then by date desc
        usort($posts, function ($a, $b) {
            $fa = $a['featured'] ?? false;
            $fb = $b['featured'] ?? false;
            if ($fa && !$fb) return -1;
            if (!$fa && $fb) return 1;

            $da = $a['published_at'] ?? $a['created_at'] ?? '';
            $db = $b['published_at'] ?? $b['created_at'] ?? '';
            return strcmp($db, $da);
        });

        return $posts;
    }

    // --- Publishing ---

    public function publishPost(string $collectionId, string $slug): void
    {
        $collection = $this->requireCollection($collectionId);
        $post = $this->getPost($collectionId, $slug);
        if (!$post) {
            throw new Exception("Post not found: {$slug}");
        }

        // Backup existing published stub content if exists
        $stubPath = $this->stubPath($collection, $slug);
        if (file_exists($stubPath) && $this->backupManager) {
            try {
                $this->backupManager->createBackup($collectionId . '/' . $slug, $stubPath);
            } catch (Exception $e) {
                error_log("Backup failed during blog publish: " . $e->getMessage());
            }
        }

        $post['status'] = 'published';
        if (empty($post['published_at'])) {
            $post['published_at'] = date('Y-m-d');
        }
        $post['scheduled_at'] = null;
        $post['modified_at'] = date('Y-m-d');

        $this->savePost($collectionId, $slug, $post);
        $this->generateStub($collectionId, $slug, $collection);
        $this->regenerateListStub($collectionId);
        $this->regenerateSitemap();
    }

    public function unpublishPost(string $collectionId, string $slug): void
    {
        $post = $this->getPost($collectionId, $slug);
        if (!$post) {
            throw new Exception("Post not found: {$slug}");
        }

        $post['status'] = 'draft';
        $post['modified_at'] = date('Y-m-d');

        $this->savePost($collectionId, $slug, $post);
        $this->removeStub($collectionId, $slug);
        $this->regenerateListStub($collectionId);
        $this->regenerateSitemap();
    }

    public function schedulePost(string $collectionId, string $slug, string $scheduledAt): void
    {
        $post = $this->getPost($collectionId, $slug);
        if (!$post) {
            throw new Exception("Post not found: {$slug}");
        }

        $post['status'] = 'scheduled';
        $post['scheduled_at'] = $scheduledAt;
        $post['modified_at'] = date('Y-m-d');

        $this->savePost($collectionId, $slug, $post);
    }

    public function publishScheduledPosts(): array
    {
        $published = [];
        $now = date('Y-m-d H:i:s');

        foreach ($this->collections as $collection) {
            $posts = $this->listPosts($collection['id'], ['status' => 'scheduled']);
            foreach ($posts as $post) {
                $scheduledAt = $post['scheduled_at'] ?? null;
                if ($scheduledAt && $scheduledAt <= $now) {
                    $this->publishPost($collection['id'], $post['slug']);
                    $published[] = $collection['id'] . '/' . $post['slug'];
                }
            }
        }

        return $published;
    }

    // --- Stubs ---

    private function generateStub(string $collectionId, string $slug, array $collection): void
    {
        $stubDir = $this->rootDir . '/' . $collection['base_path'] . '/' . $slug;
        if (!is_dir($stubDir)) {
            mkdir($stubDir, 0755, true);
        }

        $basePath = $collection['base_path'];
        $stub = "<?php\nrequire_once __DIR__ . '/" . $this->relativePathToCore($basePath, $slug) . "/cms/core/BlogRenderer.php';\nBlogRenderer::render('" . addslashes($collectionId) . "', '" . addslashes($slug) . "');\n";

        file_put_contents($stubDir . '/index.php', $stub);
    }

    private function removeStub(string $collectionId, string $slug): void
    {
        $collection = $this->getCollection($collectionId);
        if (!$collection) return;

        $stubDir = $this->rootDir . '/' . $collection['base_path'] . '/' . $slug;
        if (is_dir($stubDir)) {
            $stubFile = $stubDir . '/index.php';
            if (file_exists($stubFile)) {
                unlink($stubFile);
            }
            @rmdir($stubDir);
        }
    }

    public function regenerateListStub(string $collectionId): void
    {
        $collection = $this->getCollection($collectionId);
        if (!$collection) return;

        $listDir = $this->rootDir . '/' . $collection['base_path'];
        if (!is_dir($listDir)) {
            mkdir($listDir, 0755, true);
        }

        $basePath = $collection['base_path'];
        $depth = count(explode('/', $basePath));
        $relPath = str_repeat('../', $depth);

        $stub = "<?php\nrequire_once __DIR__ . '/{$relPath}cms/core/BlogRenderer.php';\nBlogRenderer::renderList('" . addslashes($collectionId) . "');\n";

        file_put_contents($listDir . '/index.php', $stub);

        // Clean up old pagination stubs
        $pageDir = $listDir . '/page';
        if (is_dir($pageDir)) {
            $this->deleteDirectory($pageDir);
        }
    }

    public function regenerateAllStubs(string $collectionId): void
    {
        $collection = $this->requireCollection($collectionId);

        // Regenerate list stub
        $this->regenerateListStub($collectionId);

        // Regenerate all published post stubs
        $posts = $this->listPosts($collectionId, ['status' => 'published']);
        foreach ($posts as $post) {
            $this->generateStub($collectionId, $post['slug'], $collection);
        }
    }

    // --- Collections ---

    public function getCollections(): array
    {
        return $this->collections;
    }

    public function getCollection(string $id): ?array
    {
        foreach ($this->collections as $collection) {
            if ($collection['id'] === $id) {
                return $collection;
            }
        }
        return null;
    }

    public function createCollection(string $id, string $label, string $basePath, string $indexType = 'auto'): void
    {
        $id = $this->sanitizeSlug($id);
        // 'default' is the fallback template name in collection-templates/.
        // Reserving the slug prevents collection-templates/default-detail.php
        // from colliding with a per-collection override.
        if ($id === 'default') {
            throw new Exception("'default' is a reserved collection id (used for fallback templates). Pick another slug.");
        }
        if ($this->getCollection($id)) {
            throw new Exception("Collection already exists: {$id}");
        }

        $this->collections[] = [
            'id' => $id,
            'base_path' => $basePath,
            'label' => $label,
            'index_type' => $indexType,
            'posts_per_page' => 10,
            'sort_by' => 'date',
            'sort_order' => 'desc',
            'show_excerpts' => true,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $this->saveCollections();

        $contentDir = $this->contentDir . '/' . $id;
        if (!is_dir($contentDir)) {
            mkdir($contentDir, 0755, true);
        }

        $baseDir = $this->rootDir . '/' . $basePath;
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0755, true);
        }

        $this->regenerateListStub($id);
    }

    public function updateCollection(string $id, array $settings): void
    {
        $found = false;
        foreach ($this->collections as &$collection) {
            if ($collection['id'] === $id) {
                foreach (['label', 'base_path', 'index_type', 'posts_per_page', 'sort_by', 'sort_order', 'show_excerpts'] as $key) {
                    if (isset($settings[$key])) {
                        $collection[$key] = $settings[$key];
                    }
                }
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw new Exception("Collection not found: {$id}");
        }
        $this->saveCollections();
    }

    public function deleteCollection(string $id): void
    {
        if (count($this->collections) <= 1) {
            throw new Exception('Cannot delete the last collection');
        }
        $posts = $this->listPosts($id);
        if (!empty($posts)) {
            throw new Exception('Cannot delete collection with existing posts');
        }

        $this->collections = array_values(array_filter($this->collections, fn($c) => $c['id'] !== $id));
        $this->saveCollections();

        $contentDir = $this->contentDir . '/' . $id;
        if (is_dir($contentDir)) {
            $this->deleteDirectory($contentDir);
        }
    }

    public function getPostCount(string $collectionId): int
    {
        $posts = $this->listPosts($collectionId, ['status' => 'published']);
        return count($posts);
    }

    // --- Helpers ---

    public function postPath(string $collectionId, string $slug): string
    {
        return $this->contentDir . '/' . $collectionId . '/' . $slug . '.json';
    }

    private function stubPath(array $collection, string $slug): string
    {
        return $this->rootDir . '/' . $collection['base_path'] . '/' . $slug . '/index.php';
    }

    private function relativePathToCore(string $basePath, string $slug): string
    {
        // From /{basePath}/{slug}/ back to root
        $depth = count(explode('/', $basePath)) + 1;
        return str_repeat('..', $depth);
    }

    private function requireCollection(string $id): array
    {
        $collection = $this->getCollection($id);
        if (!$collection) {
            throw new Exception("Collection not found: {$id}");
        }
        return $collection;
    }

    private function sanitizeSlug(string $slug): string
    {
        require_once __DIR__ . '/Slug.php';
        return Slug::make($slug, 60, 'post');
    }

    private function getDefaultPost(string $slug, string $collectionId): array
    {
        $title = ucwords(str_replace('-', ' ', $slug));
        $author = $this->templates['defaults']['author'] ?? 'Dev Team';
        $authorId = strtolower(str_replace(' ', '-', $author));

        return [
            'title' => $title,
            'slug' => $slug,
            'status' => 'draft',
            'author_id' => $authorId,
            'created_at' => date('Y-m-d'),
            'published_at' => null,
            'modified_at' => date('Y-m-d'),
            'scheduled_at' => null,
            'categories' => [],
            'tags' => [],
            'excerpt' => '',
            'featured_image' => '',
            'featured_image_alt' => '',
            'featured' => false,
            'seo' => ['title' => '', 'description' => ''],
            'content' => "<h2>{$title}</h2>\n<p>Write your content here...</p>",
        ];
    }

    private function savePostJson(string $path, array $post): void
    {
        if (isset($post['content']) && is_string($post['content']) && $post['content'] !== '') {
            $post['content'] = $this->sanitizeBodyHtml($post['content']);
        }
        $json = json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($path, $json) === false) {
            throw new Exception('Failed to save post');
        }
    }

    /**
     * Single source of truth for what HTML the post-body sanitizer accepts.
     * The TinyMCE config on admin/blog-edit.php derives valid_elements +
     * valid_styles from these arrays so what the editor shows == what the
     * server keeps after save.
     */
    public static function getAllowedTags(): array
    {
        return [
            'a','p','br','span','div','section','article','figure','figcaption',
            'h1','h2','h3','h4','h5','h6',
            'ul','ol','li','blockquote','pre','code','em','strong','b','i','u','s','small','sup','sub',
            'img','video','audio','source','iframe',
            'table','thead','tbody','tfoot','tr','th','td','hr',
        ];
    }

    public static function getAllowedAttrs(): array
    {
        return [
            '*'      => ['class', 'id', 'title', 'lang', 'dir'],
            'a'      => ['href', 'target', 'rel'],
            'img'    => ['src', 'alt', 'width', 'height', 'loading'],
            'video'  => ['src', 'controls', 'width', 'height', 'poster'],
            'audio'  => ['src', 'controls'],
            'source' => ['src', 'type'],
            'iframe' => ['src', 'width', 'height', 'allow', 'allowfullscreen', 'loading'],
            'th'     => ['colspan', 'rowspan', 'scope'],
            'td'     => ['colspan', 'rowspan'],
        ];
    }

    public static function getIframeOriginAllow(): array
    {
        return ['youtube.com','youtu.be','vimeo.com','player.vimeo.com'];
    }

    /**
     * Build a TinyMCE valid_elements string from the allowlist. The format
     * is "tag[attr1|attr2],tag2[...]" with "*" prepended for global attrs.
     */
    public static function buildTinyMceValidElements(): string
    {
        $tags = self::getAllowedTags();
        $attrs = self::getAllowedAttrs();
        $globals = $attrs['*'] ?? [];
        $entries = [];
        foreach ($tags as $tag) {
            $perTag = array_merge($globals, $attrs[$tag] ?? []);
            $entries[] = $tag . '[' . implode('|', $perTag) . ']';
        }
        return implode(',', $entries);
    }

    /**
     * Sanitize post body HTML at write time. Allowlist approach: drop
     * everything not in getAllowedTags()/getAllowedAttrs(). Removes
     * script/style/event handlers / javascript: URLs.
     */
    private function sanitizeBodyHtml(string $html): string
    {
        $allowedTags = self::getAllowedTags();
        $allowedAttrs = self::getAllowedAttrs();
        $iframeOriginAllow = self::getIframeOriginAllow();

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        // Wrap so DOMDocument has a root to chew on
        $dom->loadHTML('<?xml encoding="UTF-8"?><div id="__root__">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $walk = function (DOMNode $node) use (&$walk, $allowedTags, $allowedAttrs, $iframeOriginAllow) {
            foreach (iterator_to_array($node->childNodes) as $child) {
                if ($child instanceof DOMElement) {
                    $tag = strtolower($child->nodeName);
                    if (!in_array($tag, $allowedTags, true)) {
                        // Strip the element but keep its children inline
                        while ($child->firstChild) {
                            $child->parentNode->insertBefore($child->firstChild, $child);
                        }
                        $child->parentNode->removeChild($child);
                        continue;
                    }
                    $perTag = array_merge($allowedAttrs['*'] ?? [], $allowedAttrs[$tag] ?? []);
                    foreach (iterator_to_array($child->attributes) as $attr) {
                        $name = strtolower($attr->nodeName);
                        $value = $attr->nodeValue;
                        if (!in_array($name, $perTag, true)) {
                            $child->removeAttribute($attr->nodeName);
                            continue;
                        }
                        // Block javascript:/data: in URL attrs
                        if (in_array($name, ['href', 'src'], true)) {
                            $v = trim($value);
                            if (stripos($v, 'javascript:') === 0 || stripos($v, 'data:') === 0 || stripos($v, 'vbscript:') === 0) {
                                $child->removeAttribute($attr->nodeName);
                                continue;
                            }
                            // iframe origin allowlist
                            if ($tag === 'iframe' && $name === 'src') {
                                $host = parse_url($v, PHP_URL_HOST) ?: '';
                                $ok = false;
                                foreach ($iframeOriginAllow as $allow) {
                                    if ($host === $allow || str_ends_with($host, '.' . $allow)) { $ok = true; break; }
                                }
                                if (!$ok) {
                                    $child->parentNode->removeChild($child);
                                    continue 2;
                                }
                            }
                        }
                    }
                    $walk($child);
                }
            }
        };
        $root = $dom->getElementById('__root__');
        if ($root) {
            $walk($root);
            $out = '';
            foreach ($root->childNodes as $c) {
                $out .= $dom->saveHTML($c);
            }
            return $out;
        }
        return $html;
    }

    private function saveCollections(): void
    {
        $json = json_encode($this->collections, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->collectionsFile, $json);
    }

    private function regenerateSitemap(): void
    {
        if ($this->sitemapGenerator) {
            try {
                $this->sitemapGenerator->generate();
            } catch (Exception $e) {
                error_log("Sitemap generation failed: " . $e->getMessage());
            }
        }
    }

    private function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) return false;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        return rmdir($dir);
    }
}
