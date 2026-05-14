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
        $collection = self::$blogManager->getCollection($collectionId);

        // Calculate reading time (uses the shared helper at line ~160)
        $readingTime = self::calculateReadingTime($post['content'] ?? '');

        // Check for scheduled post publishing (only on public render path —
        // preview should not cause a publish as a side effect)
        if (!$includeUnpublished) {
            self::$blogManager->publishScheduledPosts();
        }

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

    public static function renderList(string $collectionId): void
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

        // Parse filters from query string
        $filters = ['status' => 'published'];
        if (!empty($_GET['tag'])) $filters['tag'] = $_GET['tag'];
        if (!empty($_GET['category'])) $filters['category'] = $_GET['category'];
        if (!empty($_GET['author'])) $filters['author_id'] = $_GET['author'];

        $posts = self::$blogManager->listPosts($collectionId, $filters);

        // Pagination
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = $collection['posts_per_page'] ?? 10;
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
        $activeFilter = $_GET['tag'] ?? $_GET['category'] ?? null;

        include $templatePath;
    }

    /**
     * Resolve the template file path for a collection.
     * Fallback:
     *   1. collection-templates/{collectionId}-{kind}.php  (per-collection customised)
     *   2. collection-templates/default-{kind}.php         (shared default)
     *
     * Returns null when neither exists — caller is responsible for
     * fallback rendering (raw content / simple list).
     */
    private static function getTemplatePath(string $collectionId, string $kind): ?string
    {
        $dir = __DIR__ . '/../collection-templates';
        $candidates = [
            $dir . '/' . $collectionId . '-' . $kind . '.php',
            $dir . '/default-' . $kind . '.php',
        ];
        foreach ($candidates as $path) {
            if (file_exists($path)) return $path;
        }
        error_log('BlogRenderer: no template found for collection=' . $collectionId . ' kind=' . $kind . '. Tried: ' . implode(', ', $candidates));
        return null;
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
