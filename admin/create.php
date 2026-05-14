<?php
/**
 * Admin Page Creation (via duplication or HTML)
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/PageManager.php';
require_once __DIR__ . '/../core/PageSettings.php';
require_once __DIR__ . '/../core/SitemapGenerator.php';
require_once __DIR__ . '/../core/CSRF.php';

$reservedFolders = $config['reserved_folders'] ?? ['cms'];
$pageSettings = new PageSettings($config['cms_dir'] . '/settings');
$sitemapGenerator = new SitemapGenerator($config['root_dir'], $config['base_url'] ?? 'http://localhost', $reservedFolders, $config['drafts_dir'] ?? null);
$pageManager = new PageManager($config['root_dir'], $reservedFolders, null, null, $sitemapGenerator, $pageSettings);
$pages = $pageManager->listPages();

// Handle page creation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $creationType = $_POST['creation_type'] ?? 'duplicate';
    $parentPage = $_POST['parent_page'] ?? '';
    $pageName = trim($_POST['page_name'] ?? '', '/');

    try {
        if (!$pageName) {
            throw new Exception('Page name is required');
        }

        // Auto-slugify: lowercase, spaces → hyphens, strip everything that
        // isn't a-z / 0-9 / hyphen / underscore. Matches PageManager's
        // validatePageId grammar so the saved file path is sane.
        require_once __DIR__ . '/../core/Slug.php';
        $rawName = $pageName;
        $pageName = Slug::make($pageName, 60, '');
        if ($pageName === '') {
            throw new Exception('Page name "' . htmlspecialchars($rawName) . '" has no usable characters. Use letters, numbers, hyphens, or underscores.');
        }
        // Final check against the engine's id grammar — belt + suspenders.
        if (!preg_match('/^[a-z0-9_\-]+$/', $pageName)) {
            throw new Exception('Invalid page name "' . htmlspecialchars($pageName) . '" after sanitization. Allowed: letters, numbers, hyphens, underscores.');
        }

        // Combine parent and page name to create full page ID
        $newPageId = $parentPage ? trim($parentPage, '/') . '/' . $pageName : $pageName;

        if ($creationType === 'duplicate') {
            // Duplicate existing page
            $sourcePageId = $_POST['source_page_id'] ?? '';
            $pageManager->duplicatePage($sourcePageId, $newPageId);
        } else {
            // Create from HTML
            $htmlContent = $_POST['html_content'] ?? '';
            if (!$htmlContent) {
                throw new Exception('HTML content is required');
            }

            // Create page from HTML
            $pageManager->createPageFromHtml($newPageId, $htmlContent);
        }

        // Redirect to edit the new page
        header('Location: /cms/admin/edit.php?page_id=' . urlencode($newPageId));
        exit;

    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

$pageTitle = 'Create New Page';
$activePage = 'create';

require __DIR__ . '/includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">Create New Page</h1>
    <p class="text-gray-600 dark:text-gray-400">
        <a href="/cms/admin/pages.php" class="text-accent-600 hover:text-accent-700">&larr; Back to Pages</a>
    </p>
</div>

<?php if (isset($errorMessage)): ?>
    <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl p-4 mb-6 flex items-center gap-3">
        <div class="w-8 h-8 rounded-lg bg-red-100 dark:bg-red-900/30 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </div>
        <p class="text-red-800 dark:text-red-300 font-medium"><?php echo htmlspecialchars($errorMessage); ?></p>
    </div>
<?php endif; ?>

<div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 p-6" x-data="{ activeTab: 'html' }">
    <!-- Tab Navigation -->
    <div class="flex border-b border-surface-200 dark:border-dark-200 mb-6">
        <button
            @click="activeTab = 'html'"
            :class="activeTab === 'html' ? 'border-accent-600 text-gray-900 dark:text-white' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
            class="px-6 py-3 font-medium border-b-2 focus:outline-none transition-colors"
        >
            Create from HTML
        </button>
        <button
            @click="activeTab = 'duplicate'"
            :class="activeTab === 'duplicate' ? 'border-accent-600 text-gray-900 dark:text-white' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
            class="px-6 py-3 font-medium border-b-2 focus:outline-none transition-colors"
        >
            Duplicate Page
        </button>
    </div>

    <!-- Create from HTML Tab -->
    <div x-show="activeTab === 'html'" x-cloak>
        <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">Create from HTML</h2>
        <p class="text-gray-600 dark:text-gray-400 mb-4">Paste complete HTML to create a new page. Include CMS block markers to define editable regions.</p>

        <div class="bg-accent-50 dark:bg-accent-900/20 border border-accent-200 dark:border-accent-800 rounded-xl p-4 mb-6">
            <h3 class="font-semibold text-accent-900 dark:text-accent-300 mb-2">Block Syntax:</h3>
            <p class="text-sm text-accent-800 dark:text-accent-400 mb-2">Wrap editable content with block markers:</p>
            <code class="block bg-white dark:bg-dark-300 px-3 py-2 rounded-lg text-xs font-mono text-gray-800 dark:text-gray-200 mb-2">
                &lt;?php /* CMS:BLOCK name=block_name start */ ?&gt;<br>
                &nbsp;&nbsp;Your editable content here<br>
                &lt;?php /* CMS:BLOCK name=block_name end */ ?&gt;
            </code>
            <p class="text-sm text-accent-800 dark:text-accent-400 mt-3 mb-1"><strong>Optional attributes:</strong></p>
            <ul class="text-sm text-accent-800 dark:text-accent-400 list-disc list-inside space-y-1">
                <li><code class="bg-white dark:bg-dark-300 px-1 rounded">role=meta</code> - For metadata blocks (title, description)</li>
                <li><code class="bg-white dark:bg-dark-300 px-1 rounded">role=navigation</code> - For navigation menus</li>
                <li><code class="bg-white dark:bg-dark-300 px-1 rounded">custom=true</code> - Skip this block during global sync</li>
            </ul>
        </div>

        <form method="post" class="space-y-6">
            <?php echo CSRF::inputField(); ?>
            <input type="hidden" name="creation_type" value="html">

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Parent Page (optional):
                </label>
                <select name="parent_page" class="w-full px-4 py-3 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white focus:border-accent-500 focus:ring-4 focus:ring-accent-500/10 transition-all">
                    <option value="">-- No Parent (Top Level) --</option>
                    <?php foreach ($pages as $page): ?>
                        <option value="<?php echo htmlspecialchars($page['id']); ?>">
                            <?php echo htmlspecialchars($page['id'] ?: '/ (Home)'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="mt-1.5 text-sm text-gray-500 dark:text-gray-400">Select a parent page to create a sub-page, or leave blank for top-level page.</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Page Name:
                </label>
                <input type="text" name="page_name" placeholder="e.g., about, team, contact" required class="w-full px-4 py-3 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:border-accent-500 focus:ring-4 focus:ring-accent-500/10 transition-all">
                <p class="mt-1.5 text-sm text-gray-500 dark:text-gray-400">Use lowercase letters, numbers, and hyphens only. Select a parent page above to create nested pages.</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    HTML Content:
                </label>
                <textarea name="html_content" rows="22" required placeholder="<!doctype html>
<html lang=&quot;en&quot;>
<head>
<?php /* CMS:BLOCK name=head role=meta start */ ?>
    <meta charset=&quot;utf-8&quot;>
    <title>My Page Title</title>
    <meta name=&quot;description&quot; content=&quot;Page description&quot;>
<?php /* CMS:BLOCK name=head role=meta end */ ?>
</head>
<body>
<?php /* CMS:BLOCK name=navbar role=navigation start */ ?>
    <nav>
        <a href=&quot;/&quot;>Home</a>
        <a href=&quot;/about&quot;>About</a>
    </nav>
<?php /* CMS:BLOCK name=navbar role=navigation end */ ?>

<?php /* CMS:BLOCK name=hero custom=true start */ ?>
    <header>
        <h1>Welcome to My Site</h1>
    </header>
<?php /* CMS:BLOCK name=hero custom=true end */ ?>

<?php /* CMS:BLOCK name=content start */ ?>
    <main>
        <p>Main page content goes here.</p>
    </main>
<?php /* CMS:BLOCK name=content end */ ?>

<?php /* CMS:BLOCK name=footer start */ ?>
    <footer>
        <p>&copy; 2024 My Company</p>
    </footer>
<?php /* CMS:BLOCK name=footer end */ ?>
</body>
</html>" class="w-full px-4 py-3 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:border-accent-500 focus:ring-4 focus:ring-accent-500/10 transition-all font-mono text-sm"></textarea>
            </div>

            <div>
                <button type="submit" class="btn-primary px-6 py-3 text-white rounded-xl font-medium shadow-lg shadow-accent-500/25">Create Page</button>
            </div>
        </form>
    </div>

    <!-- Duplicate Page Tab -->
    <div x-show="activeTab === 'duplicate'" x-cloak>
        <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">Duplicate Existing Page</h2>
        <p class="text-gray-600 dark:text-gray-400 mb-6">Create a new page by duplicating an existing one. You can then edit the blocks in the new page.</p>

        <form method="post" class="space-y-6">
            <?php echo CSRF::inputField(); ?>
            <input type="hidden" name="creation_type" value="duplicate">

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Source Page:
                </label>
                <select name="source_page_id" required class="w-full px-4 py-3 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white focus:border-accent-500 focus:ring-4 focus:ring-accent-500/10 transition-all">
                    <option value="">-- Select a page to duplicate --</option>
                    <?php foreach ($pages as $page): ?>
                        <option value="<?php echo htmlspecialchars($page['id']); ?>">
                            <?php echo htmlspecialchars($page['id'] ?: '/ (Home)'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Parent Page (optional):
                </label>
                <select name="parent_page" class="w-full px-4 py-3 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white focus:border-accent-500 focus:ring-4 focus:ring-accent-500/10 transition-all">
                    <option value="">-- No Parent (Top Level) --</option>
                    <?php foreach ($pages as $page): ?>
                        <option value="<?php echo htmlspecialchars($page['id']); ?>">
                            <?php echo htmlspecialchars($page['id'] ?: '/ (Home)'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="mt-1.5 text-sm text-gray-500 dark:text-gray-400">Select a parent page to create a sub-page, or leave blank for top-level page.</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Page Name:
                </label>
                <input type="text" name="page_name" placeholder="e.g., about, team, contact" required class="w-full px-4 py-3 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:border-accent-500 focus:ring-4 focus:ring-accent-500/10 transition-all">
                <p class="mt-1.5 text-sm text-gray-500 dark:text-gray-400">Use lowercase letters, numbers, and hyphens only. Select a parent page above to create nested pages.</p>
            </div>

            <div>
                <button type="submit" class="btn-primary px-6 py-3 text-white rounded-xl font-medium shadow-lg shadow-accent-500/25">Create Page</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
