<?php
/**
 * Admin Blog Post Editor — JSON content storage
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/BlogManager.php';
require_once __DIR__ . '/../core/AuthorManager.php';
require_once __DIR__ . '/../core/BackupManager.php';
require_once __DIR__ . '/../core/SitemapGenerator.php';
require_once __DIR__ . '/../core/CategoryManager.php';
require_once __DIR__ . '/../core/CollectionTheme.php';
require_once __DIR__ . '/../core/CSRF.php';

$reservedFolders = $config['reserved_folders'] ?? ['cms'];
$backupManager = new BackupManager($config['backups_dir'], $config['max_backups_per_page']);
$sitemapGenerator = new SitemapGenerator($config['root_dir'], $config['base_url'] ?? 'http://localhost', $reservedFolders, $config['drafts_dir'] ?? null);
$blogManager = new BlogManager($config['root_dir'], $config['cms_dir'], $sitemapGenerator, $backupManager);
$authorManager = new AuthorManager($config['cms_dir'] . '/config');
$categoryManager = new CategoryManager($config['cms_dir']);

$collectionId = $_GET['collection'] ?? 'blog';
$slug = $_GET['slug'] ?? '';
$isNew = empty($slug);
$authors = $authorManager->listAuthors();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $newSlug = $_POST['slug'] ?? '';
            $newTitle = $_POST['title'] ?? '';
            if (!empty($newSlug)) {
                $post = $blogManager->createPost($collectionId, $newSlug, ['title' => $newTitle]);
                header('Location: /cms/admin/blog-edit.php?collection=' . urlencode($collectionId) . '&slug=' . urlencode($post['slug']));
                exit;
            }
        } elseif ($action === 'save' || $action === 'save_and_publish' || $action === 'schedule') {
            $post = $blogManager->getPost($collectionId, $slug);
            if (!$post) throw new Exception('Post not found');

            // Update metadata fields
            $post['title'] = $_POST['title'] ?? $post['title'];
            $post['author_id'] = $_POST['author_id'] ?? $post['author_id'];
            $post['excerpt'] = $_POST['excerpt'] ?? $post['excerpt'];
            $post['featured_image'] = $_POST['featured_image'] ?? $post['featured_image'];
            $post['featured_image_alt'] = $_POST['featured_image_alt'] ?? $post['featured_image_alt'];
            $post['featured'] = isset($_POST['featured']);
            // SEO is keyed by locale ("default" only for now — multi-locale hedge).
            // Atomic JSON-LD: parse + reject any string field containing "</" as
            // defense-in-depth against breaking out of the rendered <script>.
            $jsonLdRaw = trim((string)($_POST['seo_json_ld'] ?? ''));
            $jsonLd = null;
            $jsonLdError = null;
            $containsBreakout = function ($v) use (&$containsBreakout) {
                if (is_string($v)) return strpos($v, '</') !== false;
                if (is_array($v)) {
                    foreach ($v as $vv) if ($containsBreakout($vv)) return true;
                }
                return false;
            };
            if ($jsonLdRaw !== '') {
                $decoded = json_decode($jsonLdRaw, true);
                if (!is_array($decoded)) {
                    $jsonLdError = 'JSON-LD is not valid JSON. Saved without it.';
                } elseif ($containsBreakout($decoded)) {
                    $jsonLdError = 'JSON-LD contains </. Rejected as unsafe.';
                } else {
                    $jsonLd = isset($decoded[0]) ? $decoded : [$decoded];
                }
            }
            $post['seo'] = [
                'locales' => [
                    'default' => array_filter([
                        'title'        => $_POST['seo_title'] ?? '',
                        'description'  => $_POST['seo_description'] ?? '',
                        'og_image'     => $_POST['seo_og_image'] ?? '',
                        'og_image_alt' => $_POST['seo_og_image_alt'] ?? '',
                        'canonical'    => $_POST['seo_canonical'] ?? '',
                        'json_ld'      => $jsonLd,
                    ], fn($v) => $v !== null && $v !== ''),
                ],
            ];
            if ($jsonLdError) {
                $errorMessage = $jsonLdError;
            }

            // Categories: selected IDs from tree picker, plus any pending
            // new ones staged locally on the form (created here transactionally).
            $selectedIds = isset($_POST['category_ids']) && is_array($_POST['category_ids'])
                ? array_values(array_filter(array_map('strval', $_POST['category_ids'])))
                : [];
            $pendingRaw = (string)($_POST['pending_categories'] ?? '');
            $pending = $pendingRaw !== '' ? json_decode($pendingRaw, true) : [];
            $pending = is_array($pending) ? $pending : [];

            // Always read fresh, in case the page has been open a while.
            $existingByIdx = $categoryManager->read($collectionId);
            $existing = $existingByIdx['list'];
            $etag = $existingByIdx['etag'];
            foreach ($pending as $p) {
                $name = trim((string)($p['name'] ?? ''));
                if ($name === '') continue;
                $createdResp = $categoryManager->create($collectionId, [
                    'name'      => ['default' => $name, 'locales' => new stdClass()],
                    'parent_id' => $p['parent_id'] ?? null,
                ], $etag);
                $etag = $createdResp['etag'];
                $existing = $createdResp['list'];
                $selectedIds[] = $createdResp['result']['id'];
            }
            $byId = [];
            foreach ($existing as $c) { $byId[$c['id']] = $c; }
            $catRefs = [];
            foreach (array_unique($selectedIds) as $cid) {
                if (!isset($byId[$cid])) continue;
                $c = $byId[$cid];
                $catRefs[] = [
                    'id'            => $c['id'],
                    'slug'          => $c['slug'],
                    'name_snapshot' => $categoryManager->displayName($c),
                ];
            }
            $post['categories'] = $catRefs;
            $post['tags'] = !empty($_POST['tags'])
                ? array_map('trim', explode(',', $_POST['tags']))
                : [];

            // Publish date
            if (!empty($_POST['published_at'])) {
                $post['published_at'] = $_POST['published_at'];
            }

            // Content
            $post['content'] = $_POST['content'] ?? $post['content'];

            $blogManager->savePost($collectionId, $slug, $post);

            if ($action === 'save_and_publish') {
                $blogManager->publishPost($collectionId, $slug);
                $successMessage = 'Post saved and published.';
            } elseif ($action === 'schedule') {
                $scheduledAt = $_POST['scheduled_at'] ?? '';
                if (empty($scheduledAt)) {
                    throw new Exception('Scheduled date is required');
                }
                $blogManager->schedulePost($collectionId, $slug, $scheduledAt);
                $successMessage = 'Post scheduled for ' . htmlspecialchars($scheduledAt) . '.';
            } else {
                $successMessage = 'Post saved.';
            }

            // Reload post data
            $post = $blogManager->getPost($collectionId, $slug);
        } elseif ($action === 'publish') {
            $blogManager->publishPost($collectionId, $slug);
            $successMessage = 'Post published.';
        } elseif ($action === 'unpublish') {
            $blogManager->unpublishPost($collectionId, $slug);
            $successMessage = 'Post unpublished.';
        }
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

// Load post data
$post = null;
if (!$isNew) {
    $post = $blogManager->getPost($collectionId, $slug);
    if (!$post) {
        $errorMessage = 'Post not found.';
    }
}

$pageTitle = $isNew ? 'Create New Post' : 'Edit: ' . htmlspecialchars($post['title'] ?? $slug);
$activePage = 'blog';

require __DIR__ . '/includes/header.php';
?>

<div class="mb-6">
    <div class="flex items-center justify-between">
        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">
            <?php echo $isNew ? 'Create New Post' : 'Edit Post'; ?>
        </h1>
        <a href="/cms/admin/blog.php?collection=<?php echo urlencode($collectionId); ?>" class="text-sm text-accent-600 hover:text-accent-700">&larr; Back to Posts</a>
    </div>
<?php if ($post): ?>
    <div class="flex items-center gap-3 mt-2 text-sm">
        <code class="bg-surface-100 dark:bg-dark-300 px-2 py-1 rounded text-accent-600 text-xs">/<?php echo htmlspecialchars($collectionId . '/' . $slug); ?>/</code>
        <span class="badge px-2 py-0.5 rounded-full text-xs <?php
            $st = $post['status'] ?? 'draft';
            echo $st === 'published' ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400' :
                ($st === 'scheduled' ? 'bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400' :
                'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400');
        ?>"><?php echo ucfirst($st); ?></span>
<?php if ($st === 'published'): ?>
        <a href="/<?php echo htmlspecialchars($collectionId . '/' . $slug); ?>/" target="_blank" class="text-blue-600 dark:text-blue-400 hover:underline">View Live &rarr;</a>
<?php endif; ?>
<?php if ($st === 'scheduled' && !empty($post['scheduled_at'])): ?>
        <span class="text-gray-500 dark:text-gray-400">Scheduled: <?php echo htmlspecialchars($post['scheduled_at']); ?></span>
<?php endif; ?>
    </div>
<?php endif; ?>
</div>

<?php if (isset($successMessage)): ?>
    <div class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-xl p-4 mb-6">
        <p class="text-emerald-800 dark:text-emerald-300 font-medium"><?php echo htmlspecialchars($successMessage); ?></p>
    </div>
<?php endif; ?>
<?php if (isset($errorMessage)): ?>
    <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl p-4 mb-6">
        <p class="text-red-800 dark:text-red-300 font-medium"><?php echo htmlspecialchars($errorMessage); ?></p>
    </div>
<?php endif; ?>

<?php if ($isNew): ?>
<!-- New post form -->
<div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 p-6" x-data="{
    title: '',
    slug: '',
    slugEdited: false,
    generateSlug(title) {
        return title.toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9\s-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '');
    }
}">
    <form method="post">
        <?php echo CSRF::inputField(); ?>
        <input type="hidden" name="action" value="create">

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Post Title</label>
            <input type="text" name="title" x-model="title" @input="if(!slugEdited) slug = generateSlug(title)" required
                   placeholder="My Blog Post"
                   class="w-full px-4 py-3 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white focus:border-accent-500 transition-all text-lg">
        </div>

        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">URL Slug</label>
            <div class="flex items-center gap-2">
                <span class="text-gray-500 dark:text-gray-400">/<?php echo htmlspecialchars($collectionId); ?>/</span>
                <input type="text" name="slug" x-model="slug" @input="slugEdited = true" required
                       placeholder="my-blog-post"
                       class="flex-1 px-4 py-3 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white font-mono focus:border-accent-500 transition-all">
                <span class="text-gray-500 dark:text-gray-400">/</span>
            </div>
        </div>

        <button type="submit" class="btn-primary px-6 py-2.5 text-white rounded-xl font-medium shadow-lg shadow-accent-500/25">Create Post</button>
    </form>
</div>

<?php else: ?>
<!-- Edit post form -->
<?php
// Theme extraction for the WYSIWYG: stylesheet URLs, inline CSS, the
// ancestor class stack around the bound post-body block, and whether
// the collection template uses Tailwind CDN (the client snapshot+strips
// it so the JIT scanner doesn't trash typing perf).
$theme = (new CollectionTheme($config['cms_dir']))->extract($collectionId);
$validElements = BlogManager::buildTinyMceValidElements();
?>
<script>
  // Constants injected from PHP. Defining them here (outside an HTML
  // attribute) keeps the embedded JSON strings from breaking the parent
  // x-data attribute when they contain double quotes.
  window.POST_COLLECTION_ID = <?php echo json_encode($collectionId); ?>;
  window.POST_SLUG = <?php echo json_encode($slug); ?>;
  window.POST_THEME = <?php echo json_encode($theme); ?>;
  window.POST_VALID_ELEMENTS = <?php echo json_encode($validElements); ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js" referrerpolicy="origin"></script>
<form method="post" x-data="postEditor()">
    <?php echo CSRF::inputField(); ?>
    <input type="hidden" name="action" value="save" x-ref="actionField">

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main content area (2/3) -->
        <div class="lg:col-span-2 space-y-6">

            <!-- Title -->
            <div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 p-6">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Title</label>
                <input type="text" name="title" value="<?php echo htmlspecialchars($post['title'] ?? ''); ?>"
                       class="w-full px-4 py-3 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white focus:border-accent-500 transition-all text-lg font-semibold">
            </div>

            <!-- Content -->
            <div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 p-6">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Content</label>
                <textarea name="content" id="post-content-editor" rows="25"
                          class="w-full px-4 py-3 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white font-mono text-sm focus:border-accent-500 transition-all leading-relaxed"
                          style="tab-size: 4;"><?php echo htmlspecialchars($post['content'] ?? ''); ?></textarea>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">WYSIWYG inherits the collection template's styles. Use <strong>Source</strong> on the toolbar for raw HTML.</p>
            </div>

            <!-- SEO (collapsible) -->
            <div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200">
                <button type="button" @click="seoOpen = !seoOpen" class="w-full flex items-center justify-between p-6 text-left hover:bg-surface-50 dark:hover:bg-dark-300 rounded-2xl transition">
                    <span class="font-semibold text-gray-900 dark:text-white">SEO Settings</span>
                    <svg class="w-5 h-5 text-gray-400 transition-transform" :class="seoOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>
                <div x-show="seoOpen" x-cloak x-collapse class="border-t border-surface-200 dark:border-dark-200 p-6 space-y-4">
                    <?php
                    $seoLocale = $post['seo']['locales']['default'] ?? $post['seo'] ?? [];
                    $seoTitleVal       = $seoLocale['title'] ?? '';
                    $seoDescVal        = $seoLocale['description'] ?? '';
                    $seoOgImageVal     = $seoLocale['og_image'] ?? '';
                    $seoOgImageAltVal  = $seoLocale['og_image_alt'] ?? '';
                    $seoCanonicalVal   = $seoLocale['canonical'] ?? '';
                    $seoJsonLdVal      = isset($seoLocale['json_ld']) ? json_encode($seoLocale['json_ld'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
                    ?>
                    <div class="flex items-center justify-between">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Blank fields fall back to derived defaults at render time.</p>
                        <button type="button" @click="seoAiOpen = true"
                                class="px-3 py-1.5 text-xs bg-accent-600 text-white rounded-lg hover:bg-accent-700 inline-flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            Update meta with AI
                        </button>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">SEO Title <span class="text-xs text-gray-400">(50–60 chars ideal)</span></label>
                        <input type="text" name="seo_title" value="<?php echo htmlspecialchars($seoTitleVal); ?>" maxlength="120"
                               placeholder="Custom title for search engines"
                               class="w-full px-4 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white focus:border-accent-500 transition-all">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">SEO Description <span class="text-xs text-gray-400">(150–160 chars ideal)</span></label>
                        <textarea name="seo_description" rows="2" maxlength="320"
                                  placeholder="Custom description for search engines"
                                  class="w-full px-4 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white focus:border-accent-500 transition-all"><?php echo htmlspecialchars($seoDescVal); ?></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Open Graph image URL</label>
                        <input type="text" name="seo_og_image" value="<?php echo htmlspecialchars($seoOgImageVal); ?>"
                               placeholder="/uploads/... (falls back to Featured image)"
                               class="w-full px-4 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white focus:border-accent-500 transition-all font-mono text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Open Graph image alt</label>
                        <input type="text" name="seo_og_image_alt" value="<?php echo htmlspecialchars($seoOgImageAltVal); ?>"
                               placeholder="Short description of the OG image"
                               class="w-full px-4 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white focus:border-accent-500 transition-all">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Canonical URL <span class="text-xs text-gray-400">(blank = auto)</span></label>
                        <input type="text" name="seo_canonical" value="<?php echo htmlspecialchars($seoCanonicalVal); ?>"
                               placeholder="Leave blank to use the auto-derived URL"
                               class="w-full px-4 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white focus:border-accent-500 transition-all font-mono text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">JSON-LD <span class="text-xs text-gray-400">(BlogPosting; blank = auto-generated)</span></label>
                        <textarea name="seo_json_ld" rows="8"
                                  placeholder='{"@context":"https://schema.org","@type":"BlogPosting",...}'
                                  class="w-full px-4 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white focus:border-accent-500 transition-all font-mono text-xs"><?php echo htmlspecialchars($seoJsonLdVal); ?></textarea>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Stored verbatim if valid JSON; rejected if any string contains <code>&lt;/</code>.</p>
                    </div>
                </div>
            </div>

            <!-- AI Meta Diff Modal -->
            <div x-show="seoAiOpen" x-cloak @keydown.escape.window="seoAiOpen = false"
                 class="fixed inset-0 z-50 bg-black/50 flex items-start justify-center overflow-y-auto p-6">
                <div class="bg-white dark:bg-dark-400 rounded-2xl shadow-2xl w-full max-w-3xl my-8" @click.outside="seoAiOpen = false">
                    <div class="flex items-center justify-between p-5 border-b border-surface-200 dark:border-dark-200">
                        <h3 class="font-semibold text-gray-900 dark:text-white">Update meta with AI</h3>
                        <button type="button" @click="seoAiOpen = false" class="text-gray-400 hover:text-gray-600">&times;</button>
                    </div>
                    <div class="p-5 space-y-4">
                        <button type="button" @click="seoAiRun()" :disabled="seoAiState === 'loading'"
                                class="px-4 py-2 bg-accent-600 text-white rounded-lg text-sm disabled:opacity-50">
                            <span x-show="seoAiState !== 'loading'">Generate proposal</span>
                            <span x-show="seoAiState === 'loading'">Thinking…</span>
                        </button>
                        <p x-show="seoAiError" x-text="seoAiError" class="text-sm text-red-600"></p>
                        <template x-if="seoAiResult">
                            <div class="space-y-4">
                                <template x-for="field in ['title','description','og_image_alt']" :key="field">
                                    <div class="border border-surface-200 dark:border-dark-200 rounded-lg p-3">
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="font-medium text-sm" x-text="field"></span>
                                            <button type="button" @click="seoAiAccept(field)" class="text-xs text-accent-700 hover:underline">Accept ←</button>
                                        </div>
                                        <div class="grid grid-cols-2 gap-2 text-xs">
                                            <div><div class="text-gray-500 mb-1">Current</div><div x-text="seoAiCurrent[field] || '—'" class="font-mono break-all"></div></div>
                                            <div><div class="text-gray-500 mb-1">Proposed</div><div x-text="seoAiResult[field] || '—'" class="font-mono break-all text-accent-700 dark:text-accent-300"></div></div>
                                        </div>
                                    </div>
                                </template>
                                <div class="border border-surface-200 dark:border-dark-200 rounded-lg p-3">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="font-medium text-sm">json_ld <span class="text-xs text-gray-500">(accept all or none)</span></span>
                                        <button type="button" @click="seoAiAcceptJsonLd()" class="text-xs text-accent-700 hover:underline">Accept ←</button>
                                    </div>
                                    <pre class="text-xs font-mono whitespace-pre-wrap break-all" x-text="seoAiResult.json_ld ? JSON.stringify(seoAiResult.json_ld, null, 2) : '—'"></pre>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar (1/3) -->
        <div class="space-y-6">

            <!-- Actions -->
            <div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 p-6">
                <h3 class="font-semibold text-gray-900 dark:text-white mb-4">Actions</h3>
                <div class="space-y-3">
                    <button type="submit" class="w-full btn-primary px-4 py-2.5 text-white rounded-xl font-medium shadow-lg shadow-accent-500/25 text-sm">
                        Save Draft
                    </button>
                    <a href="/cms/admin/blog-preview.php?collection=<?php echo urlencode($collectionId); ?>&slug=<?php echo urlencode($slug); ?>"
                       target="_blank"
                       class="block w-full text-center px-4 py-2.5 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 rounded-xl font-medium text-sm transition">
                        Preview
                    </a>
<?php if (($post['status'] ?? 'draft') !== 'published'): ?>
                    <button type="submit" @click="$refs.actionField.value = 'save_and_publish'"
                            class="w-full px-4 py-2.5 bg-emerald-600 text-white rounded-xl font-medium hover:bg-emerald-700 transition text-sm">
                        Save &amp; Publish
                    </button>
<?php else: ?>
                    <button type="submit" @click="$refs.actionField.value = 'save_and_publish'"
                            class="w-full px-4 py-2.5 bg-emerald-600 text-white rounded-xl font-medium hover:bg-emerald-700 transition text-sm">
                        Save &amp; Republish
                    </button>
<?php endif; ?>

                    <!-- Schedule -->
                    <div>
                        <button type="button" @click="schedulerOpen = !schedulerOpen"
                                class="w-full px-4 py-2.5 bg-purple-600 text-white rounded-xl font-medium hover:bg-purple-700 transition text-sm">
                            Schedule Publish
                        </button>
                        <div x-show="schedulerOpen" x-cloak class="mt-3 space-y-2">
                            <input type="datetime-local" name="scheduled_at"
                                   value="<?php echo !empty($post['scheduled_at']) ? date('Y-m-d\TH:i', strtotime($post['scheduled_at'])) : ''; ?>"
                                   class="w-full px-3 py-2 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white text-sm focus:border-accent-500 transition-all">
                            <button type="submit" @click="$refs.actionField.value = 'schedule'"
                                    class="w-full px-3 py-2 bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400 rounded-xl font-medium text-sm hover:bg-purple-200 transition">
                                Confirm Schedule
                            </button>
                        </div>
                    </div>

<?php if (($post['status'] ?? 'draft') === 'published'): ?>
                    <form method="post" class="mt-2" onsubmit="return confirm('Unpublish this post?');">
                        <?php echo CSRF::inputField(); ?>
                        <input type="hidden" name="action" value="unpublish">
                        <button type="submit" class="w-full px-4 py-2.5 bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 rounded-xl font-medium text-sm hover:bg-amber-200 transition">
                            Unpublish
                        </button>
                    </form>
<?php endif; ?>
                </div>
            </div>

            <!-- Metadata -->
            <div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 p-6 space-y-4">
                <h3 class="font-semibold text-gray-900 dark:text-white">Metadata</h3>

                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Author</label>
                    <select name="author_id" class="w-full px-3 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white text-sm focus:border-accent-500 transition-all">
                        <option value="">— Select —</option>
<?php foreach ($authors as $a): ?>
                        <option value="<?php echo htmlspecialchars($a['id']); ?>" <?php echo ($post['author_id'] ?? '') === $a['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($a['name']); ?>
                        </option>
<?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Publish Date</label>
                    <input type="date" name="published_at"
                           value="<?php echo htmlspecialchars($post['published_at'] ?? date('Y-m-d')); ?>"
                           class="w-full px-3 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white text-sm focus:border-accent-500 transition-all">
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Excerpt</label>
                    <textarea name="excerpt" rows="3"
                              placeholder="Brief summary of the post..."
                              class="w-full px-3 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white text-sm focus:border-accent-500 transition-all"><?php echo htmlspecialchars($post['excerpt'] ?? ''); ?></textarea>
                </div>

                <?php
                $catRead = $categoryManager->read($collectionId);
                $catList = $catRead['list'];
                $selectedRefs = $post['categories'] ?? [];
                $selectedIds = [];
                foreach ($selectedRefs as $r) {
                    if (is_array($r) && !empty($r['id'])) $selectedIds[] = $r['id'];
                }
                ?>
                <div x-data="catPicker(<?php echo htmlspecialchars(json_encode([
                    'list' => $catList,
                    'selected' => $selectedIds,
                ]), ENT_QUOTES, 'UTF-8'); ?>)">
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Categories</label>
                        <a href="/cms/admin/blog-categories.php?collection=<?php echo urlencode($collectionId); ?>" target="_blank"
                           class="text-xs text-accent-700 dark:text-accent-300 hover:underline">Manage tree ↗</a>
                    </div>
                    <div class="max-h-56 overflow-y-auto bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl p-2">
                        <template x-if="tree.length === 0">
                            <p class="text-xs text-gray-500 dark:text-gray-400 p-2">No categories yet. Add one below.</p>
                        </template>
                        <ul class="space-y-0.5">
                            <template x-for="n in tree" :key="n.id">
                                <li>
                                    <span x-html="renderNode(n, 0)"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                    <p x-show="pending.length > 0" class="text-[11px] text-amber-700 dark:text-amber-300 mt-1" x-cloak>
                        Pending categories will be created when you save the post.
                    </p>

                    <!-- Add new (staged) -->
                    <div class="mt-2 flex gap-1">
                        <input type="text" x-model="newName" @keydown.enter.prevent="stage()"
                               placeholder="+ New category"
                               class="flex-1 px-2 py-1.5 bg-surface-50 dark:bg-dark-300 border border-surface-200 dark:border-dark-200 rounded-md text-xs">
                        <select x-model="newParent" class="px-2 py-1.5 bg-surface-50 dark:bg-dark-300 border border-surface-200 dark:border-dark-200 rounded-md text-xs">
                            <option value="">(top)</option>
                            <template x-for="opt in flatOptions()" :key="opt.id">
                                <option :value="opt.id" x-text="opt.label"></option>
                            </template>
                        </select>
                        <button type="button" @click="stage()" class="px-3 py-1.5 bg-accent-600 hover:bg-accent-700 text-white text-xs rounded-md">Add</button>
                    </div>
                    <p class="text-[11px] text-gray-400 mt-1">Existing checked + pending entries are sent on Save.</p>

                    <!-- Hidden inputs the save handler reads. Real ids only;
                         pending entries travel as JSON and get created server-side. -->
                    <template x-for="id in selectedRealIds" :key="id">
                        <input type="hidden" name="category_ids[]" :value="id">
                    </template>
                    <input type="hidden" name="pending_categories" :value="JSON.stringify(pendingPayload)">
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Tags</label>
                    <input type="text" name="tags"
                           value="<?php echo htmlspecialchars(implode(', ', $post['tags'] ?? [])); ?>"
                           placeholder="PHP, CMS, Tutorial"
                           class="w-full px-3 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white text-sm focus:border-accent-500 transition-all">
                    <p class="text-xs text-gray-400 mt-0.5">Comma-separated</p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Featured Image</label>
                    <input type="text" name="featured_image"
                           value="<?php echo htmlspecialchars($post['featured_image'] ?? ''); ?>"
                           placeholder="/assets/content/image.jpg"
                           class="w-full px-3 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white text-sm focus:border-accent-500 transition-all">
                    <a href="/cms/admin/media.php" target="_blank" class="text-xs text-accent-600 hover:text-accent-700 mt-1 inline-block">Browse Media &rarr;</a>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Image Alt Text</label>
                    <input type="text" name="featured_image_alt"
                           value="<?php echo htmlspecialchars($post['featured_image_alt'] ?? ''); ?>"
                           placeholder="Describe the image"
                           class="w-full px-3 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white text-sm focus:border-accent-500 transition-all">
                </div>

                <label class="flex items-center gap-2 cursor-pointer pt-2">
                    <input type="checkbox" name="featured"
                           <?php echo ($post['featured'] ?? false) ? 'checked' : ''; ?>
                           class="h-4 w-4 text-accent-600 rounded border-gray-300 dark:border-gray-600">
                    <span class="text-sm text-gray-700 dark:text-gray-300">Featured post</span>
                </label>
            </div>
        </div>
    </div>
</form>
<?php endif; ?>

<script>
function postEditor() {
  return {
    seoOpen: false,
    schedulerOpen: false,
    seoAiOpen: false,
    seoAiState: 'idle',
    seoAiError: '',
    seoAiResult: null,
    init() {
      // Defer until TinyMCE finishes loading. tinymce is a global from the
      // <script src="...tinymce.min.js"> tag above.
      const start = Date.now();
      const tryInit = () => {
        if (typeof tinymce === 'undefined') {
          if (Date.now() - start > 8000) return; // give up gracefully
          return setTimeout(tryInit, 100);
        }
        this.initTinyMce();
      };
      tryInit();
    },
    initTinyMce() {
      const theme = window.POST_THEME || {};
      const stylesheets = (theme.stylesheet_urls || []).map(u => {
        // Resolve to absolute against site origin if relative
        if (/^https?:/i.test(u) || u.startsWith('//')) return u;
        return new URL(u, window.location.origin).href;
      });
      const inlineCss = theme.inline_css || '';
      const ancestors = theme.ancestor_classes || [];
      const hasTailwind = !!theme.has_tailwind_cdn;
      const validElements = window.POST_VALID_ELEMENTS || '';

      tinymce.init({
        selector: '#post-content-editor',
        license_key: 'gpl',
        height: 540,
        menubar: false,
        branding: false,
        promotion: false,
        plugins: 'lists link image code table hr autoresize',
        toolbar: 'undo redo | blocks | bold italic underline | bullist numlist | link image blockquote hr | table code',
        block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3; Preformatted=pre',
        valid_elements: validElements,
        content_css: stylesheets,
        content_style: inlineCss,
        image_list: '/cms/admin/media.php?json=1&tinymce=1',
        image_caption: true,
        // Paste behavior: keep text, drop weird styles. Server sanitizer is
        // the backstop (BlogManager::sanitizeBodyHtml).
        paste_data_images: false,
        smart_paste: true,
        relative_urls: false,
        convert_urls: false,
        autoresize_bottom_margin: 24,
        setup: (editor) => {
          editor.on('init', () => {
            const doc = editor.getDoc();
            // 1. Wrap the editor body's children in the ancestor class
            //    stack so .prose / .post-content selectors match.
            if (ancestors.length) {
              const body = doc.body;
              let inner = body.innerHTML;
              let openHtml = '';
              let closeHtml = '';
              ancestors.forEach(cls => {
                openHtml += '<div class="' + cls.replace(/"/g, '&quot;') + '">';
                closeHtml = '</div>' + closeHtml;
              });
              body.innerHTML = openHtml + inner + closeHtml;
              // Set "editing root" so TinyMCE inserts new content inside
              // the innermost wrapper rather than at <body> level.
              const deepest = doc.querySelectorAll('.' + (ancestors[ancestors.length-1] || '').split(/\s+/)[0]);
              // We don't reassign editor root; we just nudge cursor in.
            }
            // 2. If Tailwind CDN was injected via content_css/script, the
            //    JIT MutationObserver scans on every keystroke. Snapshot
            //    every loaded stylesheet into a single inline <style> and
            //    nuke any tailwind script tag in the doc head.
            if (hasTailwind) {
              try {
                let css = '';
                for (const sheet of doc.styleSheets) {
                  try {
                    for (const rule of sheet.cssRules) {
                      css += rule.cssText + '\n';
                    }
                  } catch (e) { /* cross-origin sheet */ }
                }
                if (css) {
                  const s = doc.createElement('style');
                  s.textContent = css;
                  doc.head.appendChild(s);
                }
                doc.querySelectorAll('script[src*="tailwind"]').forEach(n => n.remove());
              } catch (e) { /* best-effort */ }
            }
          });
        },
      });
    },
    get seoAiCurrent() {
      return {
        title: this.$el.querySelector('[name=seo_title]')?.value || '',
        description: this.$el.querySelector('[name=seo_description]')?.value || '',
        og_image_alt: this.$el.querySelector('[name=seo_og_image_alt]')?.value || '',
      };
    },
    async seoAiRun() {
      this.seoAiState = 'loading'; this.seoAiError = ''; this.seoAiResult = null;
      try {
        const fd = new FormData();
        fd.append('csrf_token', document.querySelector('input[name=csrf_token]').value);
        fd.append('title', this.$el.querySelector('[name=title]').value);
        fd.append('content', this.$el.querySelector('[name=content]').value.slice(0, 4000));
        fd.append('featured_image', document.querySelector('[name=featured_image]')?.value || '');
        fd.append('collection_id', window.POST_COLLECTION_ID);
        fd.append('slug', window.POST_SLUG);
        const r = await fetch('/cms/admin/post-meta-ai.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (!j.success) throw new Error(j.error || 'AI failed');
        this.seoAiResult = j.proposal;
        this.seoAiState = 'idle';
      } catch (e) { this.seoAiError = String(e.message || e); this.seoAiState = 'idle'; }
    },
    seoAiAccept(field) {
      if (!this.seoAiResult) return;
      const map = { title: 'seo_title', description: 'seo_description', og_image_alt: 'seo_og_image_alt' };
      const sel = this.$el.querySelector('[name=' + map[field] + ']');
      if (sel) sel.value = this.seoAiResult[field] || '';
    },
    seoAiAcceptJsonLd() {
      if (!this.seoAiResult || !this.seoAiResult.json_ld) return;
      const sel = this.$el.querySelector('[name=seo_json_ld]');
      if (sel) sel.value = JSON.stringify(this.seoAiResult.json_ld, null, 2);
    },
  };
}

function catPicker(initial) {
  return {
    list: initial.list || [],
    selected: initial.selected || [],
    pending: [],  // [{tempId, name, parent_id}]
    pendingSeq: 0,
    newName: '',
    newParent: '',
    /**
     * Merge real categories + pending into a single virtual list so the
     * tree renderer shows them together. Pending entries get a tempId
     * like "_pending_0" so the checkbox identity is stable until save.
     */
    get virtualList() {
      const pend = this.pending.map(p => ({
        id: p.tempId,
        slug: '(pending)',
        name: { default: p.name },
        parent_id: p.parent_id || null,
        sort_order: 99999,
        _pending: true,
      }));
      return this.list.concat(pend);
    },
    get tree() {
      const all = this.virtualList;
      const byParent = {};
      all.forEach(c => {
        const k = c.parent_id || '';
        (byParent[k] = byParent[k] || []).push(c);
      });
      Object.values(byParent).forEach(b => b.sort((a,b) => (a.sort_order||0) - (b.sort_order||0)));
      const build = (pid) => (byParent[pid || ''] || []).map(n => ({ ...n, children: build(n.id) }));
      return build(null);
    },
    flatOptions() {
      const out = [];
      const walk = (nodes, prefix) => {
        nodes.forEach(n => {
          // pending entries can't be parents of new pending (they have no real id yet on the server)
          if (!n._pending) {
            out.push({ id: n.id, label: prefix + (n.name?.default || n.slug) });
          }
          walk(n.children, prefix + '— ');
        });
      };
      walk(this.tree, '');
      return out;
    },
    parentLabel(id) {
      const c = this.virtualList.find(x => x.id === id);
      return c ? (c.name?.default || c.slug) : '?';
    },
    isChecked(id) { return this.selected.includes(id); },
    toggle(id) {
      const i = this.selected.indexOf(id);
      if (i >= 0) this.selected.splice(i, 1); else this.selected.push(id);
    },
    renderNode(n, depth) {
      const pad = depth * 16;
      const checked = this.isChecked(n.id) ? 'checked' : '';
      const label = n.name?.default || '(unnamed)';
      const badge = n._pending
        ? ' <span class="text-[10px] uppercase tracking-wider text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/30 px-1.5 py-0.5 rounded">pending</span>'
        : '';
      const slugCode = n._pending
        ? ''
        : ` <code class="text-[10px] text-gray-400 font-mono">/${escapeHtml(n.slug)}</code>`;
      let html = `<label class="flex items-center gap-2 py-1 text-sm" style="padding-left:${pad}px">
        <input type="checkbox" ${checked} data-id="${n.id}" onchange="window._catToggle(this)"
               class="h-3.5 w-3.5 text-accent-600 rounded">
        <span>${escapeHtml(label)}</span>${badge}${slugCode}
      </label>`;
      n.children.forEach(c => html += this.renderNode(c, depth + 1));
      return html;
    },
    stage() {
      const name = this.newName.trim();
      if (!name) return;
      const tempId = '_pending_' + (this.pendingSeq++);
      this.pending.push({ tempId, name, parent_id: this.newParent || null });
      this.selected.push(tempId); // auto-check the new entry
      this.newName = '';
      this.newParent = '';
    },
    // Server expects {name, parent_id} only; strip tempId on form submit.
    get pendingPayload() {
      return this.pending.map(p => ({ name: p.name, parent_id: p.parent_id }));
    },
    // Selected IDs minus tempIds — the server creates pending and adds them
    // via the pending_categories payload (see save handler).
    get selectedRealIds() {
      return this.selected.filter(id => !id.startsWith('_pending_'));
    },
  };
}
function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
window._catToggle = function(el) {
  const root = el.closest('[x-data]');
  if (!root || !root._x_dataStack) return;
  const ctx = root._x_dataStack[0];
  ctx.toggle(el.dataset.id);
};
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
