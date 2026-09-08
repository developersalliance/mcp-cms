<?php

require_once __DIR__ . '/Hooks.php';

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
    private string $cmsDir;
    private $categoryManager = null;
    /** Tag / attribute names the sanitizer removed during the last save. */
    private array $lastStripped = [];

    public function __construct(string $rootDir, string $cmsDir, $sitemapGenerator = null, $backupManager = null)
    {
        $this->rootDir = rtrim($rootDir, '/');
        $this->cmsDir = rtrim($cmsDir, '/');
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
        $post = $this->normalizePostForWrite($collectionId, $post);

        $this->savePostJson($path, $post);
        return $this->normalizePostForRead($post, $collectionId);
    }

    public function getPost(string $collectionId, string $slug): ?array
    {
        $path = $this->postPath($collectionId, $slug);
        if (!file_exists($path)) {
            return null;
        }
        $post = json_decode(file_get_contents($path), true);
        return is_array($post) ? $this->normalizePostForRead($post, $collectionId) : null;
    }

    public function savePost(string $collectionId, string $slug, array $post, bool $snapshot = true): void
    {
        $path = $this->postPath($collectionId, $slug);
        if (!file_exists($path)) {
            throw new Exception("Post not found: {$slug}");
        }
        if ($snapshot) {
            $this->snapshotPost($collectionId, $slug, $path);
        }
        $post['modified_at'] = date('Y-m-d');
        $post = $this->normalizePostForWrite($collectionId, $post);
        $this->savePostJson($path, $post);
        Hooks::do('post.saved', $collectionId, $slug, $post);
    }

    // --- Category model -------------------------------------------------
    //
    // One shape everywhere: categories = [{id, slug, name_snapshot}, ...].
    // Legacy posts (developers-alliance.com) carried a bare `category`
    // string and older MCP clients wrote bare name strings; both are
    // upgraded on read (in memory) and on write (persisted). A derived
    // `category` string (first category's name) is always present so old
    // themes reading $post['category'] keep working.

    private function categoryManager(): CategoryManager
    {
        if ($this->categoryManager === null) {
            require_once __DIR__ . '/CategoryManager.php';
            $this->categoryManager = new CategoryManager($this->cmsDir);
        }
        return $this->categoryManager;
    }

    /**
     * Resolve one category reference (object, id, slug or name) against the
     * collection's category list. Returns [id, slug, name_snapshot] or null.
     */
    private function matchCategory(array $list, $ref): ?array
    {
        $cm = $this->categoryManager();
        if (is_array($ref)) {
            $id = (string)($ref['id'] ?? '');
            $slug = (string)($ref['slug'] ?? '');
            $name = (string)($ref['name_snapshot'] ?? $ref['name'] ?? '');
            foreach ($list as $c) {
                if ($id !== '' && $c['id'] === $id) return ['id' => $c['id'], 'slug' => $c['slug'], 'name_snapshot' => $cm->displayName($c)];
            }
            foreach ($list as $c) {
                if ($slug !== '' && $c['slug'] === $slug) return ['id' => $c['id'], 'slug' => $c['slug'], 'name_snapshot' => $cm->displayName($c)];
            }
            $ref = $name !== '' ? $name : $slug;
            if ($ref === '') return null;
        }
        $needle = strtolower(trim((string)$ref));
        if ($needle === '') return null;
        foreach ($list as $c) {
            if (strtolower($c['id']) === $needle || strtolower($c['slug']) === $needle || strtolower($cm->displayName($c)) === $needle) {
                return ['id' => $c['id'], 'slug' => $c['slug'], 'name_snapshot' => $cm->displayName($c)];
            }
        }
        // slugified name match ("AI Search" vs "ai-search")
        require_once __DIR__ . '/Slug.php';
        $asSlug = Slug::make((string)$ref, 60, 'category');
        foreach ($list as $c) {
            if ($c['slug'] === $asSlug) return ['id' => $c['id'], 'slug' => $c['slug'], 'name_snapshot' => $cm->displayName($c)];
        }
        return null;
    }

    /**
     * Turn whatever a caller passed as categories (and/or a legacy
     * `category` string) into the canonical object list. Unknown names are
     * created in the collection when $createMissing is true; otherwise they
     * are kept as slug-only references (id null) so nothing is lost.
     */
    public function resolveCategories(string $collectionId, $refs, bool $createMissing = true, ?string $legacyCategory = null): array
    {
        $refs = is_array($refs) ? $refs : ($refs === null || $refs === '' ? [] : [$refs]);
        if (($legacyCategory ?? '') !== '' && $refs === []) {
            $refs = [$legacyCategory];
        }
        if ($refs === []) return [];
        $cm = $this->categoryManager();
        $list = $cm->list($collectionId);
        $out = [];
        $seen = [];
        foreach ($refs as $ref) {
            if ($ref === null || $ref === '' || $ref === []) continue;
            // Already canonical ({id, slug, name_snapshot}) → keep verbatim.
            // Re-resolving would read the category file, which is stale
            // while CategoryManager::update() is sweeping a rename.
            if (is_array($ref) && array_key_exists('id', $ref) && isset($ref['slug'], $ref['name_snapshot']) && $ref['slug'] !== '') {
                $match = ['id' => $ref['id'] !== null ? (string)$ref['id'] : null, 'slug' => (string)$ref['slug'], 'name_snapshot' => (string)$ref['name_snapshot']];
                $key = $match['id'] ?? ('slug:' . $match['slug']);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $out[] = $match;
                continue;
            }
            $match = $this->matchCategory($list, $ref);
            if ($match === null) {
                $name = is_array($ref) ? (string)($ref['name_snapshot'] ?? $ref['name'] ?? $ref['slug'] ?? '') : trim((string)$ref);
                if ($name === '') continue;
                $explicitSlugOnly = is_array($ref) && array_key_exists('id', $ref) && $ref['id'] === null && !empty($ref['slug']);
                if ($createMissing && !$explicitSlugOnly) {
                    $created = $cm->create($collectionId, ['name' => $name]);
                    $rec = $created['result'] ?? $created;
                    $list = $cm->list($collectionId);
                    $match = ['id' => $rec['id'], 'slug' => $rec['slug'], 'name_snapshot' => $name];
                } else {
                    require_once __DIR__ . '/Slug.php';
                    $match = ['id' => null, 'slug' => Slug::make($name, 60, 'category'), 'name_snapshot' => $name];
                }
            }
            $key = $match['id'] ?? ('slug:' . $match['slug']);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $match;
        }
        return $out;
    }

    /** Canonical categories + derived `category` string, persisted. */
    private function normalizePostForWrite(string $collectionId, array $post): array
    {
        $legacy = isset($post['category']) && is_string($post['category']) ? $post['category'] : null;
        $post['categories'] = $this->resolveCategories($collectionId, $post['categories'] ?? [], true, $legacy);
        $post['category'] = $post['categories'][0]['name_snapshot'] ?? ($legacy ?? '');
        // Manual related-post picks: keep the key only when the caller sent
        // it (legacy posts stay untouched), sanitized against the collection.
        if (array_key_exists('related', $post)) {
            $post['related'] = $this->sanitizeRelated($collectionId, $post['related'], (string)($post['slug'] ?? ''));
        }
        return $post;
    }

    /**
     * Sanitize a `related` value: strings only, deduplicated, never the
     * post's own slug, and only slugs that exist in the collection.
     */
    private function sanitizeRelated(string $collectionId, $related, string $selfSlug): array
    {
        if (!is_array($related)) return [];
        $out = [];
        foreach ($related as $ref) {
            if (!is_string($ref)) continue;
            $ref = trim($ref);
            if ($ref === '' || $ref === $selfSlug || in_array($ref, $out, true)) continue;
            try {
                if (!file_exists($this->postPath($collectionId, $ref))) continue;
            } catch (Exception $e) {
                continue; // unsafe slug — drop it
            }
            $out[] = $ref;
        }
        return $out;
    }

    /**
     * In-memory upgrade of a stored post to the canonical shape. Never
     * writes; unknown bare strings become slug-only references.
     */
    public function normalizePostForRead(array $post, string $collectionId): array
    {
        $cats = $post['categories'] ?? [];
        $needs = !is_array($cats);
        if (!$needs) {
            foreach ($cats as $c) {
                if (!is_array($c) || !isset($c['id'], $c['slug'], $c['name_snapshot'])) { $needs = true; break; }
            }
        }
        $legacy = isset($post['category']) && is_string($post['category']) ? $post['category'] : null;
        if ($needs || ($cats === [] && ($legacy ?? '') !== '')) {
            try {
                $post['categories'] = $this->resolveCategories($collectionId, is_array($cats) ? $cats : [], false, $legacy);
            } catch (Exception $e) {
                $post['categories'] = [];
            }
        }
        $post['category'] = $post['categories'][0]['name_snapshot'] ?? ($legacy ?? '');
        return $post;
    }

    // --- Revisions ------------------------------------------------------

    private function revisionsDir(string $collectionId, string $slug): string
    {
        self::assertSafeId($collectionId, 'collection id');
        self::assertSafeId($slug, 'slug');
        if ($this->backupManager && method_exists($this->backupManager, 'getBackupsDir')) {
            $base = rtrim($this->backupManager->getBackupsDir(), '/');
        } else {
            // Callers that build BlogManager without a BackupManager (some admin
            // pages, BlogRenderer) must still use the configured backups_dir so
            // every path writes one revision history.
            static $cfgBackups = null;
            if ($cfgBackups === null) {
                $cfgFile = $this->cmsDir . '/config/config.php';
                $cfg = is_file($cfgFile) ? (include $cfgFile) : [];
                $cfgBackups = is_array($cfg) && !empty($cfg['backups_dir']) ? rtrim((string)$cfg['backups_dir'], '/') : '';
            }
            $base = $cfgBackups !== '' ? $cfgBackups : $this->cmsDir . '/backups';
        }
        return $base . '/posts/' . $collectionId . '/' . $slug;
    }

    private function maxRevisions(): int
    {
        if ($this->backupManager && method_exists($this->backupManager, 'getMaxBackups')) {
            return max(1, (int)$this->backupManager->getMaxBackups());
        }
        return 10;
    }

    /** Copy the current JSON to backups/posts/… before it is overwritten. */
    private function snapshotPost(string $collectionId, string $slug, string $path): void
    {
        if (!is_file($path)) return;
        $dir = $this->revisionsDir($collectionId, $slug);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return;
        $files = glob($dir . '/*.json') ?: [];
        usort($files, fn($a, $b) => strcmp(basename($b, '.json'), basename($a, '.json'))); // newest first; '.1' suffix sorts after the bare second
        // Skip when the current file is byte-identical to the newest snapshot
        if ($files !== [] && @file_get_contents($files[0]) === @file_get_contents($path)) return;
        $ts = date('YmdHis');
        $target = $dir . '/' . $ts . '.json';
        // Same-second saves get a suffix that still sorts AFTER the bare timestamp
        $n = 1;
        while (file_exists($target)) { $target = $dir . '/' . $ts . '.' . $n++ . '.json'; }
        @copy($path, $target);
        // prune
        $files = glob($dir . '/*.json') ?: [];
        usort($files, fn($a, $b) => strcmp(basename($b, '.json'), basename($a, '.json'))); // newest first; '.1' suffix sorts after the bare second
        foreach (array_slice($files, $this->maxRevisions()) as $old) { @unlink($old); }
    }

    /** @return array<int, array> newest first */
    public function listPostRevisions(string $collectionId, string $slug): array
    {
        $dir = $this->revisionsDir($collectionId, $slug);
        $files = is_dir($dir) ? (glob($dir . '/*.json') ?: []) : [];
        usort($files, fn($a, $b) => strcmp(basename($b, '.json'), basename($a, '.json'))); // newest first; '.1' suffix sorts after the bare second
        $out = [];
        foreach ($files as $f) {
            $d = json_decode((string)file_get_contents($f), true) ?: [];
            $out[] = [
                'timestamp' => basename($f, '.json'),
                'saved_at' => date('Y-m-d H:i:s', filemtime($f)),
                'title' => (string)($d['title'] ?? ''),
                'status' => (string)($d['status'] ?? ''),
                'modified_at' => (string)($d['modified_at'] ?? ''),
                'size' => filesize($f),
            ];
        }
        return $out;
    }

    public function getPostRevision(string $collectionId, string $slug, string $timestamp): ?array
    {
        if (!preg_match('/^[0-9]{14}([.-]\d+)?$/', $timestamp)) return null;
        $f = $this->revisionsDir($collectionId, $slug) . '/' . $timestamp . '.json';
        if (!is_file($f)) return null;
        $d = json_decode((string)file_get_contents($f), true);
        return is_array($d) ? $d : null;
    }

    /**
     * Restore a revision's content + metadata. Publication state is kept as
     * it is now (restoring a draft snapshot does not unpublish); the current
     * version is snapshotted first so the restore itself is reversible.
     */
    public function restorePostRevision(string $collectionId, string $slug, string $timestamp): array
    {
        $rev = $this->getPostRevision($collectionId, $slug, $timestamp);
        if (!$rev) throw new Exception("Revision not found: {$timestamp}");
        $current = $this->getPost($collectionId, $slug);
        if (!$current) throw new Exception("Post not found: {$slug}");
        $restored = $rev;
        $restored['slug'] = $slug;
        $restored['status'] = $current['status'] ?? 'draft';
        $restored['scheduled_at'] = $current['scheduled_at'] ?? null;
        $this->savePost($collectionId, $slug, $restored);
        if (($current['status'] ?? '') === 'published') {
            $this->regenerateSitemap();
        }
        return $this->getPost($collectionId, $slug);
    }

    /** Names of tags/attributes removed by the sanitizer in the last save. */
    public function getLastStripped(): array
    {
        return $this->lastStripped;
    }

    /** Human-readable allowlist summary for tool descriptions / responses. */
    public static function allowlistSummary(): string
    {
        $attrs = self::getAllowedAttrs();
        $perTag = [];
        foreach ($attrs as $tag => $list) {
            if ($tag === '*') continue;
            $perTag[] = $tag . '[' . implode(',', $list) . ']';
        }
        return 'Allowed tags: ' . implode(', ', self::getAllowedTags())
            . '. Global attributes: ' . implode(', ', $attrs['*'] ?? [])
            . '. Per-tag attributes: ' . implode(' ', $perTag)
            . '. iframes only from ' . implode(', ', self::getIframeOriginAllow())
            . '. No style attributes, no <script>/<style>, no data: or javascript: URLs.';
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
            if (str_starts_with(basename($file), '_')) continue; // _categories.json etc.
            $post = json_decode(file_get_contents($file), true);
            if (!$post) continue;
            $post = $this->normalizePostForRead($post, $collectionId);

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
                $needle = strtolower(trim((string)$filters['category']));
                $match = false;
                foreach ($post['categories'] ?? [] as $c) {
                    if (is_array($c)) {
                        if (strtolower((string)($c['id'] ?? '')) === $needle) { $match = true; break; }
                        if (strtolower((string)($c['slug'] ?? '')) === $needle) { $match = true; break; }
                        if (strtolower((string)($c['name_snapshot'] ?? '')) === $needle) { $match = true; break; }
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

    /**
     * Case-insensitive search over published posts. Title matches rank
     * above excerpt matches, which rank above body matches. Returns at
     * most $limit posts, best match first (ties: newest first).
     */
    public function searchPosts(string $collectionId, string $q, int $limit = 50): array
    {
        $q = trim($q);
        if ($q === '') return [];
        $lower = fn(string $s): string => function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        $needle = $lower($q);

        $scored = [];
        foreach ($this->listPosts($collectionId, ['status' => 'published']) as $post) {
            $title = $lower((string)($post['title'] ?? ''));
            $excerpt = $lower((string)($post['excerpt'] ?? ''));
            $body = $lower(strip_tags((string)($post['content'] ?? '')));

            $score = 0;
            if (strpos($title, $needle) !== false) $score += 100;
            if (strpos($excerpt, $needle) !== false) $score += 40;
            $bodyHits = substr_count($body, $needle);
            if ($bodyHits > 0) $score += min(30, 10 + $bodyHits * 2);
            if ($score === 0) continue;

            $scored[] = ['score' => $score, 'post' => $post];
        }

        usort($scored, function ($a, $b) {
            if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
            $da = $a['post']['published_at'] ?? $a['post']['created_at'] ?? '';
            $db = $b['post']['published_at'] ?? $b['post']['created_at'] ?? '';
            return strcmp($db, $da);
        });

        $results = array_column(array_slice($scored, 0, max(1, $limit)), 'post');
        $filtered = Hooks::apply('search.results', $results, $q);
        return is_array($filtered) ? $filtered : $results;
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

        $this->savePost($collectionId, $slug, $post, false); // status change only: no revision snapshot
        $this->generateStub($collectionId, $slug, $collection);
        $this->regenerateListStub($collectionId);
        $this->regenerateSitemap();
        $this->notifyNewsletterSubscribers($collectionId, $slug, $collection);
        Hooks::do('post.published', $collectionId, $slug, $this->getPost($collectionId, $slug));
    }

    /**
     * Email confirmed newsletter subscribers about a post's FIRST publish.
     * Republishing an edited post never re-sends (newsletter_notified_at gate).
     * Mail problems must never break publishing: everything is best-effort.
     */
    private function notifyNewsletterSubscribers(string $collectionId, string $slug, array $collection): void
    {
        try {
            $post = $this->getPost($collectionId, $slug);
            if (!$post || !empty($post['newsletter_notified_at'])) {
                return;
            }
            $managerFile = __DIR__ . '/SubscriberManager.php';
            $configFile = $this->cmsDir . '/config/config.php';
            if (!is_file($managerFile) || !is_file($configFile)) {
                return;
            }
            require_once $managerFile;
            $config = include $configFile;
            if (!is_array($config)) {
                return;
            }
            $subscribers = new SubscriberManager($this->cmsDir . '/settings', $config);
            $confirmed = array_filter($subscribers->listSubscribers(), fn($s) => ($s['status'] ?? '') === 'confirmed');
            if ($confirmed === []) {
                return;
            }
            $basePath = trim($collection['base_path'] ?? 'blog', '/');
            $sent = $subscribers->notifyNewPost([
                'title' => $post['title'] ?? $slug,
                'excerpt' => $post['excerpt'] ?? '',
                'url' => '/' . $basePath . '/' . $slug . '/',
            ], $config);
            $post = $this->getPost($collectionId, $slug);
            if ($post) {
                $post['newsletter_notified_at'] = date('c');
                $post['newsletter_sent'] = $sent;
                $this->savePost($collectionId, $slug, $post, false);
            }
        } catch (Throwable $e) {
            error_log('Newsletter notify failed for ' . $collectionId . '/' . $slug . ': ' . $e->getMessage());
        }
    }

    public function unpublishPost(string $collectionId, string $slug): void
    {
        $post = $this->getPost($collectionId, $slug);
        if (!$post) {
            throw new Exception("Post not found: {$slug}");
        }

        $post['status'] = 'draft';
        $post['modified_at'] = date('Y-m-d');

        $this->savePost($collectionId, $slug, $post, false); // status change only: no revision snapshot
        $this->removeStub($collectionId, $slug);
        $this->regenerateListStub($collectionId);
        $this->regenerateSitemap();
        Hooks::do('post.unpublished', $collectionId, $slug);
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

        $this->savePost($collectionId, $slug, $post, false); // status change only: no revision snapshot
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

        $this->regenerateCategoryStubs($collectionId);
    }

    /**
     * (Re)generate {base_path}/category/{category-slug}/index.php archive
     * stubs for every category used by at least one published post, and
     * remove stubs for categories no longer in use. Runs as part of every
     * list stub regeneration (publish/unpublish/delete); safe to call
     * directly after category changes.
     */
    public function regenerateCategoryStubs(string $collectionId): void
    {
        $collection = $this->getCollection($collectionId);
        if (!$collection) return;

        $basePath = $collection['base_path'];
        $catBaseDir = $this->rootDir . '/' . $basePath . '/category';

        // Category slugs used by published posts (slugs are already url-safe
        // — see resolveCategories — but re-check before using as a path).
        $used = [];
        foreach ($this->listPosts($collectionId, ['status' => 'published']) as $post) {
            foreach ($post['categories'] ?? [] as $c) {
                $catSlug = is_array($c) ? (string)($c['slug'] ?? '') : '';
                if ($catSlug !== '' && preg_match('/^[a-z0-9][a-z0-9_-]{0,120}$/', $catSlug)) {
                    $used[$catSlug] = true;
                }
            }
        }

        // From /{basePath}/category/{slug}/ back to root
        $depth = count(explode('/', trim($basePath, '/'))) + 2;
        $relPath = str_repeat('../', $depth);

        foreach (array_keys($used) as $catSlug) {
            $dir = $catBaseDir . '/' . $catSlug;
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $stub = "<?php\nrequire_once __DIR__ . '/{$relPath}cms/core/BlogRenderer.php';\nBlogRenderer::renderList('" . addslashes($collectionId) . "', ['category' => '" . addslashes($catSlug) . "']);\n";
            file_put_contents($dir . '/index.php', $stub);
        }

        // Remove stubs for categories no longer in use. Only directories
        // whose index.php is one of our list stubs are touched, so custom
        // content a site parked under /category/ survives.
        if (is_dir($catBaseDir)) {
            foreach (scandir($catBaseDir) as $item) {
                if ($item === '.' || $item === '..' || isset($used[$item])) continue;
                $dir = $catBaseDir . '/' . $item;
                if (!is_dir($dir)) continue;
                $stubFile = $dir . '/index.php';
                if (is_file($stubFile) && strpos((string)file_get_contents($stubFile), 'BlogRenderer::renderList(') !== false) {
                    unlink($stubFile);
                    @rmdir($dir);
                }
            }
            @rmdir($catBaseDir); // drops the dir only when no categories remain
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
        self::assertSafeId($collectionId, 'collection id');
        self::assertSafeId($slug, 'slug');
        return $this->contentDir . '/' . $collectionId . '/' . $slug . '.json';
    }

    /**
     * Slugs and collection ids are used as path segments. Anything outside
     * [a-z0-9_-] (no dots, no slashes) is rejected so "../../config/users"
     * can never resolve to a file outside content/.
     */
    public static function assertSafeId(string $value, string $what = 'slug'): void
    {
        if ($value === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,120}$/', $value)) {
            throw new Exception("Invalid {$what}: must be lowercase letters, digits, hyphens or underscores");
        }
    }

    private function stubPath(array $collection, string $slug): string
    {
        return $this->rootDir . '/' . $collection['base_path'] . '/' . $slug . '/index.php';
    }

    private function relativePathToCore(string $basePath, string $slug): string
    {
        // From /{basePath}/{slug}/ back to root
        $depth = count(explode('/', trim($basePath, '/'))) + 1;
        // '../../' (one '..' per directory level), NOT str_repeat('..') which
        // produced '....' and made every CMS-published post stub fatal.
        return implode('/', array_fill(0, $depth, '..'));
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
            'seo' => ['locales' => ['default' => []]],
            'content' => "<h2>{$title}</h2>\n<p>Write your content here...</p>",
        ];
    }

    private function savePostJson(string $path, array $post): void
    {
        $this->lastStripped = [];
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

        $stripped = &$this->lastStripped;
        $note = function (string $what) use (&$stripped) {
            if (!in_array($what, $stripped, true)) $stripped[] = $what;
        };
        $walk = function (DOMNode $node) use (&$walk, $allowedTags, $allowedAttrs, $iframeOriginAllow, $note) {
            foreach (iterator_to_array($node->childNodes) as $child) {
                if ($child instanceof DOMElement) {
                    $tag = strtolower($child->nodeName);
                    if (!in_array($tag, $allowedTags, true)) {
                        $note('<' . $tag . '>');
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
                            $note($tag . '[' . $name . ']');
                            $child->removeAttribute($attr->nodeName);
                            continue;
                        }
                        // Block javascript:/data: in URL attrs
                        if (in_array($name, ['href', 'src'], true)) {
                            $v = trim($value);
                            if (stripos($v, 'javascript:') === 0 || stripos($v, 'data:') === 0 || stripos($v, 'vbscript:') === 0) {
                                $note($tag . '[' . $name . '=' . strtolower(substr($v, 0, (int)strpos($v, ':'))) . ':]');
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
                                    $note('<iframe src=' . $host . '>');
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
