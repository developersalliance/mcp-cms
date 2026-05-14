<?php
/**
 * Collection Templates Settings
 *
 * Edits the DEFAULT template pair used by collections that haven't been
 * customised: collection-templates/default-detail.php +
 * collection-templates/default-list.php. Per-collection overrides
 * (e.g. blog-detail.php) are created via the "Customise template"
 * button on the Manage Collections page.
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/CSRF.php';

$templatesDir = __DIR__ . '/../collection-templates';
$templatesConfigFile = __DIR__ . '/../config/collection-templates.json';

$detailTemplateFile = $templatesDir . '/default-detail.php';
$listTemplateFile = $templatesDir . '/default-list.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_templates') {
            if (!is_dir($templatesDir)) {
                mkdir($templatesDir, 0755, true);
            }
            $detailTemplate = $_POST['detail_template'] ?? '';
            $listTemplate = $_POST['list_template'] ?? '';

            file_put_contents($detailTemplateFile, $detailTemplate);
            file_put_contents($listTemplateFile, $listTemplate);

            header('Location: /cms/admin/collection-templates.php?saved=templates');
            exit;
        } elseif ($action === 'save_defaults') {
            $tplConfig = file_exists($templatesConfigFile) ? json_decode(file_get_contents($templatesConfigFile), true) : [];

            $tplConfig['defaults'] = [
                'author' => $_POST['default_author'] ?? 'Dev Team',
                'excerpt' => $_POST['default_excerpt'] ?? 'Read this article on our blog.',
                'read_time' => $_POST['default_read_time'] ?? '5 min read'
            ];

            file_put_contents($templatesConfigFile, json_encode($tplConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            header('Location: /cms/admin/collection-templates.php?saved=defaults');
            exit;
        }
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

if (isset($_GET['saved'])) {
    $successMessage = $_GET['saved'] === 'templates' ? 'Templates saved successfully.' : 'Default values saved successfully.';
}

$detailTemplate = file_exists($detailTemplateFile) ? file_get_contents($detailTemplateFile) : '';
$listTemplate = file_exists($listTemplateFile) ? file_get_contents($listTemplateFile) : '';

$tplConfig = file_exists($templatesConfigFile) ? json_decode(file_get_contents($templatesConfigFile), true) : [];
$defaults = $tplConfig['defaults'] ?? [
    'author' => 'Dev Team',
    'excerpt' => 'Read this article on our blog.',
    'read_time' => '5 min read'
];

$pageTitle = 'Collection Templates';
$activePage = 'collection-templates';

require __DIR__ . '/includes/header.php';
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/ace.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/mode-php.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/mode-html.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/theme-chrome.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/theme-monokai.min.js"></script>
<style>
    .ace-editor-wrapper { border: 1px solid #e5e7eb; border-radius: 0.5rem; overflow: hidden; }
    .dark .ace-editor-wrapper { border-color: #374151; }
    .ace_editor { font-family: 'JetBrains Mono', 'Fira Code', monospace !important; font-size: 13px !important; }
</style>

<h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">Collection Templates</h1>
<p class="text-gray-600 dark:text-gray-400 mb-6">Edit the default detail + list templates used by every collection that hasn't been customised. To override the default for a specific collection, use <a href="/cms/admin/collections.php" class="text-accent-600 dark:text-accent-400 hover:text-accent-700">Manage Collections</a> → Customise template.</p>

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

<div class="bg-blue-50 dark:bg-blue-900/20 border-l-4 border-blue-500 p-4 mb-6">
    <p class="text-sm text-blue-800 dark:text-blue-300">
        Want to import your own design as a template?
        <a href="/cms/admin/import.php" class="underline font-medium">Go to Import Pages</a>, pick a file, then choose "Import as collection template" (requires an AI provider).
    </p>
</div>

<div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
    <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">Default Values</h2>
    <form method="post">
        <?php echo CSRF::inputField(); ?>
        <input type="hidden" name="action" value="save_defaults">

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Default Author</label>
                <input type="text" name="default_author" value="<?php echo htmlspecialchars($defaults['author']); ?>"
                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Default Excerpt</label>
                <input type="text" name="default_excerpt" value="<?php echo htmlspecialchars($defaults['excerpt']); ?>"
                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Default Read Time</label>
                <input type="text" name="default_read_time" value="<?php echo htmlspecialchars($defaults['read_time']); ?>"
                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
            </div>
        </div>

        <button type="submit" class="px-4 py-2 bg-accent-600 text-white rounded-md hover:bg-accent-700 transition">
            Save Defaults
        </button>
    </form>
</div>

<div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6" x-data="collectionTemplatesEditor()">
    <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">Default Template Files</h2>

    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Templates are plain PHP. The "Variables available" panel under each editor lists what's in scope when the template is included by BlogRenderer.</p>

    <form method="post" class="space-y-8" @submit="syncEditors()">
        <?php echo CSRF::inputField(); ?>
        <input type="hidden" name="action" value="save_templates">

        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">Detail Template (default)</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">cms/collection-templates/default-detail.php</code> — renders a single post.
            </p>
            <div class="ace-editor-wrapper">
                <div id="detail-editor" style="height: 500px;"></div>
            </div>
            <textarea name="detail_template" x-ref="detailTextarea" class="hidden"><?php echo htmlspecialchars($detailTemplate); ?></textarea>

            <details class="mt-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg border border-gray-200 dark:border-gray-700">
                <summary class="cursor-pointer px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 select-none">Variables available in detail templates</summary>
                <div class="px-4 pb-4 pt-1 text-xs text-gray-700 dark:text-gray-300 space-y-3">
                    <div>
                        <p class="font-semibold mb-1"><code>$post</code> (array) — the current post</p>
                        <ul class="ml-4 list-disc space-y-0.5">
                            <li><code>['title']</code> — post title</li>
                            <li><code>['content']</code> — already-sanitized HTML, echo raw</li>
                            <li><code>['excerpt']</code> — short summary string</li>
                            <li><code>['featured_image']</code> — URL or empty</li>
                            <li><code>['featured_image_alt']</code> — alt text for the featured image</li>
                            <li><code>['slug']</code> — URL slug for this post</li>
                            <li><code>['published_at']</code> — ISO datetime (or empty if draft)</li>
                            <li><code>['created_at']</code>, <code>['modified_at']</code> — ISO timestamps</li>
                            <li><code>['author_id']</code> — id used to resolve <code>$author</code></li>
                            <li><code>['categories']</code> — array of {id, slug, name_snapshot}</li>
                            <li><code>['tags']</code> — array of tag strings</li>
                            <li><code>['seo']['locales']['default']['title' / 'description' / 'og_image' / …]</code> — per-post SEO overrides</li>
                        </ul>
                    </div>
                    <div>
                        <p class="font-semibold mb-1"><code>$author</code> (array|null) — resolved from <code>$post['author_id']</code>, null if none</p>
                        <ul class="ml-4 list-disc space-y-0.5">
                            <li><code>['name']</code> — display name</li>
                            <li><code>['bio']</code> — short author bio</li>
                            <li><code>['avatar']</code> — URL or empty</li>
                        </ul>
                    </div>
                    <div>
                        <p class="font-semibold mb-1"><code>$collection</code> (array) — the parent collection</p>
                        <ul class="ml-4 list-disc space-y-0.5">
                            <li><code>['label']</code> — human-readable name (e.g. "Blog")</li>
                            <li><code>['base_path']</code> — URL prefix (e.g. "blog")</li>
                            <li><code>['posts_per_page']</code> — pagination size</li>
                        </ul>
                    </div>
                    <div>
                        <p class="font-semibold mb-1">Other scalars</p>
                        <ul class="ml-4 list-disc space-y-0.5">
                            <li><code>$siteName</code> — site name from config</li>
                            <li><code>$baseUrl</code> — site base URL from config</li>
                            <li><code>$readingTime</code> — estimated minutes to read (int)</li>
                        </ul>
                    </div>
                </div>
            </details>
        </div>

        <hr class="border-gray-200 dark:border-gray-700">

        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">List Template (default)</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">cms/collection-templates/default-list.php</code> — renders the collection index.
            </p>
            <div class="ace-editor-wrapper">
                <div id="list-editor" style="height: 500px;"></div>
            </div>
            <textarea name="list_template" x-ref="listTextarea" class="hidden"><?php echo htmlspecialchars($listTemplate); ?></textarea>

            <details class="mt-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg border border-gray-200 dark:border-gray-700">
                <summary class="cursor-pointer px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 select-none">Variables available in list templates</summary>
                <div class="px-4 pb-4 pt-1 text-xs text-gray-700 dark:text-gray-300 space-y-3">
                    <div>
                        <p class="font-semibold mb-1"><code>$pagedPosts</code> (array) — posts for the current page</p>
                        <p class="ml-4">Each entry has the same fields as <code>$post</code> in detail templates, plus <code>['_author']</code> already resolved.</p>
                    </div>
                    <div>
                        <p class="font-semibold mb-1"><code>$pagination</code> (Pagination object)</p>
                        <ul class="ml-4 list-disc space-y-0.5">
                            <li><code>-&gt;getCurrentPage()</code> — current 1-based page number</li>
                            <li><code>-&gt;getPreviousPage()</code> — previous page number or null</li>
                            <li><code>-&gt;getNextPage()</code> — next page number or null</li>
                            <li><code>-&gt;getTotalPages()</code> — total page count</li>
                        </ul>
                    </div>
                    <div>
                        <p class="font-semibold mb-1"><code>$activeFilter</code> (string|null) — current <code>?tag=</code> or <code>?category=</code> value if any</p>
                    </div>
                    <div>
                        <p class="font-semibold mb-1"><code>$collection</code> (array) — same shape as detail templates (<code>label</code>, <code>base_path</code>, <code>posts_per_page</code>)</p>
                    </div>
                    <div>
                        <p class="font-semibold mb-1">Other scalars</p>
                        <ul class="ml-4 list-disc space-y-0.5">
                            <li><code>$siteName</code> — site name from config</li>
                            <li><code>$baseUrl</code> — site base URL from config</li>
                        </ul>
                    </div>
                </div>
            </details>
        </div>

        <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
            <button type="submit" class="px-6 py-2 bg-accent-600 text-white rounded-md hover:bg-accent-700 transition">
                Save All Templates
            </button>
        </div>
    </form>
</div>

<script>
function collectionTemplatesEditor() {
    return {
        detailEditor: null,
        listEditor: null,

        init() {
            this.$nextTick(() => this.initEditors());
        },

        initEditors() {
            const isDark = document.documentElement.classList.contains('dark');
            const theme = isDark ? 'ace/theme/monokai' : 'ace/theme/chrome';

            this.detailEditor = ace.edit('detail-editor');
            this.detailEditor.setTheme(theme);
            this.detailEditor.session.setMode('ace/mode/php');
            this.detailEditor.setOptions({ showPrintMargin: false, wrap: true, tabSize: 4, useSoftTabs: true });
            this.detailEditor.setValue(this.$refs.detailTextarea.value, -1);

            this.listEditor = ace.edit('list-editor');
            this.listEditor.setTheme(theme);
            this.listEditor.session.setMode('ace/mode/php');
            this.listEditor.setOptions({ showPrintMargin: false, wrap: true, tabSize: 4, useSoftTabs: true });
            this.listEditor.setValue(this.$refs.listTextarea.value, -1);
        },

        syncEditors() {
            if (this.detailEditor) this.$refs.detailTextarea.value = this.detailEditor.getValue();
            if (this.listEditor) this.$refs.listTextarea.value = this.listEditor.getValue();
        },
    };
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
