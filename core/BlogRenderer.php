<?php

require_once __DIR__ . '/BlogManager.php';
require_once __DIR__ . '/AuthorManager.php';
require_once __DIR__ . '/Pagination.php';
require_once __DIR__ . '/PageMeta.php';

class BlogRenderer
{
    private static ?array $config = null;
    private static ?BlogManager $blogManager = null;
    private static ?AuthorManager $authorManager = null;

    private static function boot(): void
    {
        if (self::$config !== null) return;

        self::$config = require __DIR__ . '/../config/config.php';
        $cmsDir = self::$config['cms_dir'];
        $rootDir = self::$config['root_dir'];

        self::$authorManager = new AuthorManager($cmsDir . '/config');
        self::$blogManager = new BlogManager($rootDir, $cmsDir);
    }

    public static function render(string $collectionId, string $slug): void
    {
        self::renderDetail($collectionId, $slug, false);
    }

    /** Preview path — bypasses the "must be published" check. */
    public static function renderPreview(string $collectionId, string $slug): void
    {
        self::renderDetail($collectionId, $slug, true);
    }

    private static function renderDetail(string $collectionId, string $slug, bool $includeUnpublished): void
    {
        self::boot();

        $post = self::$blogManager->getPost($collectionId, $slug);
        if (!$post) {
            http_response_code(404);
            echo '<h1>404 - Post not found</h1>';
            return;
        }
        if (!$includeUnpublished && ($post['status'] ?? 'draft') !== 'published') {
            http_response_code(404);
            echo '<h1>404 - Post not found</h1>';
            return;
        }

        $author = self::$authorManager->getAuthor($post['author_id'] ?? '');
        if ($author) {
            $post['_author'] = $author; // detail templates read $post['_author'] like the list path
        }
        $collection = self::$blogManager->getCollection($collectionId);

        // Calculate reading time (uses the shared helper at line ~160)
        $readingTime = self::calculateReadingTime($post['content'] ?? '');

        // Check for scheduled post publishing (only on public render path —
        // preview should not cause a publish as a side effect)
        if (!$includeUnpublished) {
            self::$blogManager->publishScheduledPosts();
        }

        // Responsive body images: add srcset/lazy where size variants exist
        // on disk (generated at upload time; degrades silently without them).
        if (!empty($post['content'])) {
            $post['content'] = self::addImageSrcset((string)$post['content']);
        }

        // Manually selected related posts (empty array when none)
        $relatedPosts = self::resolveRelated($collectionId, $post, $collection);

        // Load template (per-collection if customised, else default)
        $templatePath = self::getTemplatePath($collectionId, 'detail');
        if ($templatePath === null) {
            echo $post['content'] ?? '';
            return;
        }

        // Template variables
        $siteName = self::$config['site_name'] ?? 'Blog';
        $baseUrl = self::$config['base_url'] ?? '';

        /* Buffer the template output so we can patch the <head> with the
         * per-post meta overrides (PageMeta::apply works on the rendered
         * HTML string). The bound block's PHP echoes are resolved by the
         * include — the buffer we receive is plain HTML. */
        ob_start();
        include $templatePath;
        $html = (string)ob_get_clean();
        echo self::applyPostMeta($html, $post, $collection, $author);
    }

    /**
     * Render a collection's list page. $presetFilters lets a stub force a
     * filter (category archive stubs pass ['category' => $slug]); presets
     * beat the query string. The ?tag= / ?category= / ?author= / ?q= query
     * parameters keep working unchanged.
     */
    public static function renderList(string $collectionId, array $presetFilters = []): void
    {
        self::boot();

        // Publish any scheduled posts first
        self::$blogManager->publishScheduledPosts();

        $collection = self::$blogManager->getCollection($collectionId);
        if (!$collection) {
            http_response_code(404);
            echo '<h1>404 - Collection not found</h1>';
            return;
        }

        // Parse filters from query string; stub presets win over the query
        $filters = ['status' => 'published'];
        if (!empty($_GET['tag'])) $filters['tag'] = $_GET['tag'];
        if (!empty($_GET['category'])) $filters['category'] = $_GET['category'];
        if (!empty($_GET['author'])) $filters['author_id'] = $_GET['author'];
        if (!empty($presetFilters['tag'])) $filters['tag'] = (string)$presetFilters['tag'];
        if (!empty($presetFilters['category'])) $filters['category'] = (string)$presetFilters['category'];
        $presetCategory = !empty($presetFilters['category']) ? (string)$presetFilters['category'] : null;

        // Public search (?q=): matched posts replace the filtered listing
        $searchQuery = null;
        if (isset($_GET['q'])) {
            $q = trim((string)$_GET['q']);
            if ($q !== '') {
                $searchQuery = function_exists('mb_substr') ? mb_substr($q, 0, 100, 'UTF-8') : substr($q, 0, 100);
            }
        }

        $posts = $searchQuery !== null
            ? self::$blogManager->searchPosts($collectionId, $searchQuery)
            : self::$blogManager->listPosts($collectionId, $filters);

        // Pagination (search results are capped at ~50 and shown on one page
        // so /page/N/ links never drop the query)
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = $searchQuery !== null ? max(1, count($posts)) : ($collection['posts_per_page'] ?? 10);
        $pagination = new Pagination(count($posts), $perPage, $page);
        $pagedPosts = array_slice($posts, $pagination->getOffset(), $pagination->getLimit());

        // Resolve authors for each post
        $authors = [];
        foreach ($pagedPosts as &$p) {
            $aid = $p['author_id'] ?? '';
            if ($aid && !isset($authors[$aid])) {
                $authors[$aid] = self::$authorManager->getAuthor($aid);
            }
            $p['_author'] = $authors[$aid] ?? null;
        }
        unset($p);

        // Category archive: resolve the display name for titles/templates
        $archiveCategory = null;
        if ($presetCategory !== null) {
            $archiveCategory = ['slug' => $presetCategory, 'name' => self::categoryDisplayName($collectionId, $presetCategory)];
        }

        // Load template (per-collection if customised, else default)
        $templatePath = self::getTemplatePath($collectionId, 'list');
        if ($templatePath === null) {
            echo '<h1>' . htmlspecialchars($collection['label']) . '</h1>';
            foreach ($pagedPosts as $p) {
                echo '<p><a href="/' . htmlspecialchars($collection['base_path'] . '/' . $p['slug']) . '/">' . htmlspecialchars($p['title']) . '</a></p>';
            }
            return;
        }

        // Template variables
        $siteName = self::$config['site_name'] ?? 'Blog';
        $baseUrl = self::$config['base_url'] ?? '';
        $activeFilter = $presetFilters['tag'] ?? $presetFilters['category'] ?? $_GET['tag'] ?? $_GET['category'] ?? null;

        /* Buffer so the <head> can be patched afterwards: search result
         * pages get a "Search: …" title + noindex, category archives get a
         * "Category: …" title + their canonical URL (normal index). Plain
         * list renders pass through untouched. */
        ob_start();
        include $templatePath;
        $html = (string)ob_get_clean();

        $metaUpdates = [];
        if ($searchQuery !== null) {
            $metaUpdates['title'] = 'Search: ' . $searchQuery . ($siteName ? ' — ' . $siteName : '');
            $metaUpdates['robots'] = 'noindex, follow';
        } elseif ($archiveCategory !== null) {
            $metaUpdates['title'] = 'Category: ' . $archiveCategory['name'] . ($siteName ? ' — ' . $siteName : '');
            $metaUpdates['canonical'] = rtrim($baseUrl, '/') . '/' . trim($collection['base_path'], '/') . '/category/' . $archiveCategory['slug'] . '/';
        }
        if ($metaUpdates !== []) {
            $pm = new PageMeta();
            $html = $pm->apply($html, $metaUpdates);
        }
        echo $html;
    }

    /** Display name for a category slug; falls back to a humanized slug. */
    private static function categoryDisplayName(string $collectionId, string $slug): string
    {
        try {
            require_once __DIR__ . '/CategoryManager.php';
            $cm = new CategoryManager(self::$config['cms_dir']);
            $cat = $cm->getBySlug($collectionId, $slug);
            if ($cat) return $cm->displayName($cat);
        } catch (Exception $e) {
            // fall through to the humanized slug
        }
        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    /**
     * Resolve the template file path for a collection.
     * Resolution order (first existing file wins):
     *   1. {root_dir}/theme/collection-templates/{collectionId}-{kind}.php  (theme override)
     *   2. {root_dir}/theme/collection-templates/default-{kind}.php         (theme default)
     *   3. collection-templates/{collectionId}-{kind}.php                   (engine per-collection)
     *   4. collection-templates/default-{kind}.php                          (engine/CMS-core default)
     *
     * Theme dir lives in the site's web root (website repo); the engine only
     * ships generic defaults. Returns null when none exist — caller is
     * responsible for fallback rendering (raw content / simple list).
     */
    private static function getTemplatePath(string $collectionId, string $kind): ?string
    {
        $engineDir = __DIR__ . '/../collection-templates';
        $candidates = [];
        // Theme overrides in the site's web root win when present:
        //   {root_dir}/theme/collection-templates/{collection|default}-{kind}.php
        // This CMS has a single theme; if the file exists there, it is used.
        $rootDir = self::$config['root_dir'] ?? null;
        if ($rootDir) {
            $themeDir = rtrim($rootDir, '/') . '/theme/collection-templates';
            $candidates[] = $themeDir . '/' . $collectionId . '-' . $kind . '.php';
            $candidates[] = $themeDir . '/default-' . $kind . '.php';
        }
        // CMS core defaults (fallback):
        $candidates[] = $engineDir . '/' . $collectionId . '-' . $kind . '.php';
        $candidates[] = $engineDir . '/default-' . $kind . '.php';
        foreach ($candidates as $path) {
            if (file_exists($path)) return $path;
        }
        error_log('BlogRenderer: no template found for collection=' . $collectionId . ' kind=' . $kind . '. Tried: ' . implode(', ', $candidates));
        return null;
    }

    /**
     * Resolve a post's manual `related` slug list into template-ready rows.
     * Unpublished or missing posts are skipped silently.
     *
     * @return array<int, array{title:string,url:string,excerpt:string,category:string,published_at:?string}>
     */
    private static function resolveRelated(string $collectionId, array $post, ?array $collection): array
    {
        $slugs = $post['related'] ?? [];
        if (!is_array($slugs) || $slugs === []) return [];

        $basePath = trim($collection['base_path'] ?? $collectionId, '/');
        $out = [];
        foreach ($slugs as $slug) {
            if (!is_string($slug) || $slug === '' || $slug === ($post['slug'] ?? '')) continue;
            try {
                $rel = self::$blogManager->getPost($collectionId, $slug);
            } catch (Exception $e) {
                continue; // unsafe slug — skip
            }
            if (!$rel || ($rel['status'] ?? 'draft') !== 'published') continue;
            $out[] = [
                'title'        => (string)($rel['title'] ?? ''),
                'url'          => '/' . $basePath . '/' . $slug . '/',
                'excerpt'      => (string)($rel['excerpt'] ?? ''),
                'category'     => (string)($rel['category'] ?? ''),
                'published_at' => $rel['published_at'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Add srcset/sizes (+ loading="lazy") to body <img> tags whose src is a
     * local upload with -md (800w) / -lg (1400w) size variants on disk.
     * Variants are generated at upload time elsewhere; when none exist the
     * tag passes through byte-identical. Conservative on purpose: only
     * quoted, query-less, site-local src values are touched, and only the
     * matched <img> tag itself is rewritten (never the surrounding HTML).
     */
    private static function addImageSrcset(string $html): string
    {
        $rootDir = rtrim(self::$config['root_dir'] ?? '', '/');
        if ($rootDir === '' || stripos($html, '<img') === false) return $html;
        $baseUrl = rtrim(self::$config['base_url'] ?? '', '/');

        // Quoted attribute values may contain ">", so the tag pattern eats
        // quoted runs whole instead of stopping at the first ">".
        $result = preg_replace_callback(
            '#<img\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i',
            function (array $m) use ($rootDir, $baseUrl) {
                $tag = $m[0];
                if (preg_match('/\bsrcset\s*=/i', $tag)) return $tag;
                if (!preg_match('/\bsrc\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $srcM)) return $tag;
                $src = $srcM[2] !== '' ? $srcM[2] : ($srcM[3] ?? '');

                // Local, absolute-path, query-less src only
                $path = $src;
                if ($baseUrl !== '' && str_starts_with($path, $baseUrl . '/')) {
                    $path = substr($path, strlen($baseUrl));
                }
                if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) return $tag;
                if (strpos($path, '..') !== false || strpos($path, '?') !== false || strpos($path, '#') !== false) return $tag;

                $file = $rootDir . $path;
                if (!is_file($file)) return $tag;
                $info = pathinfo($path);
                $ext = strtolower($info['extension'] ?? '');
                if ($ext === '' || !preg_match('/^[a-z0-9]+$/', $ext)) return $tag;
                $stem = ($info['dirname'] === '/' ? '' : $info['dirname']) . '/' . $info['filename'];

                // Size variants next to the original (widths by convention)
                $entries = [];
                foreach (['md' => 800, 'lg' => 1400] as $suffix => $width) {
                    $variantPath = $stem . '-' . $suffix . '.' . $ext;
                    if (is_file($rootDir . $variantPath)) {
                        $entries[$width] = $variantPath;
                    }
                }
                if ($entries === []) return $tag;

                // Original joins the set when its real width is known
                $size = @getimagesize($file);
                if (is_array($size) && !empty($size[0]) && !isset($entries[(int)$size[0]])) {
                    $entries[(int)$size[0]] = $path;
                }
                ksort($entries);

                $srcsetParts = [];
                foreach ($entries as $width => $entryPath) {
                    $srcsetParts[] = $entryPath . ' ' . $width . 'w';
                }
                $extra = ' srcset="' . htmlspecialchars(implode(', ', $srcsetParts), ENT_QUOTES, 'UTF-8') . '"'
                       . ' sizes="(max-width: 800px) 100vw, 800px"';
                if (!preg_match('/\bloading\s*=/i', $tag)) {
                    $extra .= ' loading="lazy"';
                }

                // Insert before the tag's closer, keeping /> style intact
                if (str_ends_with($tag, '/>')) {
                    return substr($tag, 0, -2) . $extra . ' />';
                }
                return substr($tag, 0, -1) . $extra . '>';
            },
            $html
        );

        return is_string($result) ? $result : $html;
    }

    public static function calculateReadingTime(string $html): int
    {
        $wordCount = str_word_count(strip_tags($html));
        return max(1, (int)ceil($wordCount / 200));
    }

    public static function formatDate(string $date, string $format = 'F j, Y'): string
    {
        $timestamp = strtotime($date);
        return $timestamp ? date($format, $timestamp) : $date;
    }

    /**
     * Merge per-post meta (post.seo) into the rendered template HTML.
     *
     * Schema lives at $post['seo']['locales'][lc] (lc defaults to 'default').
     * Anything left blank falls back to derived defaults: canonical from
     * collection.base_path + slug, og:image from featured_image, og:type
     * "article", article:published_time from published_at, twitter:card
     * inferred from og:image. JSON-LD BlogPosting is auto-generated only
     * if the user hasn't supplied their own json_ld array.
     */
    private static function applyPostMeta(string $html, array $post, ?array $collection, ?array $author): string
    {
        $baseUrl = rtrim(self::$config['base_url'] ?? '', '/');
        $basePath = trim($collection['base_path'] ?? '', '/');
        $slug = $post['slug'] ?? '';
        $url = $baseUrl . '/' . trim($basePath . '/' . $slug, '/') . '/';

        $locale = 'default'; // multi-locale hedge — keyed already, only one for now
        $userMeta = $post['seo']['locales'][$locale] ?? $post['seo'] ?? [];

        $title = (string)($userMeta['title'] ?? $post['title'] ?? '');
        $desc  = (string)($userMeta['description'] ?? $post['excerpt'] ?? '');
        $img   = (string)($userMeta['og_image'] ?? $post['featured_image'] ?? '');
        $imgAlt = (string)($userMeta['og_image_alt'] ?? $post['featured_image_alt'] ?? $title);

        $updates = [];
        if ($title !== '') $updates['title'] = $title;
        if ($desc !== '')  $updates['description'] = $desc;
        $updates['canonical'] = $userMeta['canonical'] ?? $url;
        $updates['og'] = array_filter([
            'title'       => $title,
            'description' => $desc,
            'type'        => 'article',
            'url'         => $url,
            'image'       => $img,
            'site_name'   => self::$config['site_name'] ?? null,
        ], fn($v) => $v !== null && $v !== '');
        if (!empty($post['published_at'])) {
            $updates['og']['article:published_time'] = $post['published_at'];
        }
        $updates['twitter'] = array_filter([
            'card'        => $img !== '' ? 'summary_large_image' : 'summary',
            'title'       => $title,
            'description' => $desc,
            'image'       => $img,
            'image:alt'   => $img !== '' ? $imgAlt : null,
        ], fn($v) => $v !== null && $v !== '');

        // JSON-LD: user-supplied wins; otherwise auto-generate BlogPosting.
        $userLd = $userMeta['json_ld'] ?? null;
        if (is_array($userLd) && !empty($userLd)) {
            $updates['json_ld'] = $userLd;
        } else {
            $ld = array_filter([
                '@context'         => 'https://schema.org',
                '@type'            => 'BlogPosting',
                'headline'         => $title,
                'description'      => $desc,
                'image'            => $img !== '' ? $img : null,
                'datePublished'    => $post['published_at'] ?? null,
                'dateModified'     => $post['modified_at'] ?? ($post['published_at'] ?? null),
                'mainEntityOfPage' => $url,
                'author'           => $author ? ['@type' => 'Person', 'name' => $author['name'] ?? ''] : null,
            ], fn($v) => $v !== null && $v !== '');
            $updates['json_ld'] = [$ld];
        }

        $pm = new PageMeta();
        return $pm->apply($html, $updates);
    }
}
