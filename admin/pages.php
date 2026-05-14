<?php
/**
 * Admin Pages Listing
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/PageManager.php';
require_once __DIR__ . '/../core/BackupManager.php';
require_once __DIR__ . '/../core/SitemapGenerator.php';
require_once __DIR__ . '/../core/PageSettings.php';
require_once __DIR__ . '/../core/CSRF.php';

$reservedFolders = $config['reserved_folders'] ?? ['cms'];
$backupManager = new BackupManager($config['backups_dir'], $config['max_backups_per_page']);
$sitemapGenerator = new SitemapGenerator($config['root_dir'], $config['base_url'] ?? 'http://localhost', $reservedFolders, $config['drafts_dir'] ?? null);
$pageSettings = new PageSettings($config['cms_dir'] . '/settings');
$pageManager = new PageManager($config['root_dir'], $reservedFolders, $config['drafts_dir'] ?? null, $backupManager, $sitemapGenerator, $pageSettings);

// Handle page actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $action = $_POST['action'] ?? '';
    $pageId = $_POST['page_id'] ?? '';

    try {
        switch ($action) {
            case 'delete':
                $pageManager->deletePage($pageId);
                $successMessage = "Page deleted successfully.";
                break;

            case 'publish':
                $pageManager->publishDraft($pageId);
                $successMessage = "Draft published successfully.";
                break;

            case 'discard':
                $pageManager->discardDraft($pageId);
                $successMessage = "Draft discarded successfully.";
                break;
        }
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

$pages = $pageManager->listPages();

// Build a tree structure for hierarchical display
function buildPageTree($pages) {
    $tree = [];
    $lookup = [];

    // First pass: create lookup and identify top-level pages
    foreach ($pages as $page) {
        $id = $page['id'] ?: '';
        $lookup[$id] = array_merge($page, ['children' => []]);
    }

    // Second pass: build tree
    foreach ($lookup as $id => &$page) {
        if ($id === '' || $id === 'index') {
            // Root/index page - always top level
            $tree[] = &$page;
        } else {
            $parentId = dirname($id);
            if ($parentId === '.') $parentId = '';

            // Check if parent exists
            if (isset($lookup[$parentId])) {
                $lookup[$parentId]['children'][] = &$page;
            } else {
                // No parent exists, treat as top-level
                $tree[] = &$page;
            }
        }
    }
    unset($page);

    // Sort function for pages
    $sortPages = function(&$pages) use (&$sortPages) {
        usort($pages, function($a, $b) {
            $nameA = basename($a['id'] ?: 'index');
            $nameB = basename($b['id'] ?: 'index');
            // Index/root always first
            if ($a['id'] === '' || $a['id'] === 'index') return -1;
            if ($b['id'] === '' || $b['id'] === 'index') return 1;
            return strcasecmp($nameA, $nameB);
        });
        foreach ($pages as &$page) {
            if (!empty($page['children'])) {
                $sortPages($page['children']);
            }
        }
    };
    $sortPages($tree);

    return $tree;
}

$pageTree = buildPageTree($pages);

$pageTitle = 'Pages';
$activePage = 'pages';

require __DIR__ . '/includes/header.php';
?>

<!-- Page Header -->
<div class="mb-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-gray-900 dark:text-white tracking-tight">All Pages</h1>
            <p class="mt-2 text-gray-500 dark:text-gray-400">Manage your website pages and content.</p>
        </div>
        <a href="/cms/admin/create.php" class="btn-primary inline-flex items-center gap-2 px-5 py-2.5 text-white rounded-xl font-medium text-sm shadow-lg shadow-accent-500/25">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
            </svg>
            New Page
        </a>
    </div>
</div>

<?php if (isset($successMessage)): ?>
    <div class="mb-6 p-4 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-xl flex items-center gap-3 animate-fade-in">
        <div class="w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
        </div>
        <p class="text-emerald-800 dark:text-emerald-300 font-medium"><?php echo htmlspecialchars($successMessage); ?></p>
    </div>
<?php endif; ?>

<?php if (isset($errorMessage)): ?>
    <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl flex items-center gap-3 animate-fade-in">
        <div class="w-8 h-8 rounded-lg bg-red-100 dark:bg-red-900/30 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </div>
        <p class="text-red-800 dark:text-red-300 font-medium"><?php echo htmlspecialchars($errorMessage); ?></p>
    </div>
<?php endif; ?>

<!-- Pages List -->
<div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 overflow-hidden">
    <?php if (empty($pages)): ?>
        <div class="p-12 text-center">
            <div class="w-20 h-20 mx-auto mb-6 rounded-2xl bg-surface-100 dark:bg-dark-300 flex items-center justify-center">
                <svg class="w-10 h-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">No pages found</h3>
            <p class="text-gray-500 dark:text-gray-400 mb-6">Get started by creating your first page.</p>
            <a href="/cms/admin/create.php" class="btn-primary inline-flex items-center gap-2 px-5 py-2.5 text-white rounded-xl font-medium text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                Create Your First Page
            </a>
        </div>
    <?php else: ?>
        <!-- Table Header
             Uses CSS grid with auto-sized first column (Page), so it expands
             to fit the widest page name across all rows (wraps when too long).
             Status is min-content (just the badge + tiny discard). Actions
             column takes the rest and never wraps. -->
        <div class="px-6 py-4 bg-surface-50 dark:bg-dark-300 border-b border-surface-200 dark:border-dark-200 grid items-center gap-4" style="grid-template-columns: minmax(0,auto) minmax(0,max-content) 1fr;">
            <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Page</div>
            <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</div>
            <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider text-right">Actions</div>
        </div>

        <!-- Table Body -->
        <?php
        // Recursive function to render page rows
        function renderPageRow($page, $pageManager, $depth = 0) {
            $hasDraft = $pageManager->hasDraft($page['id']);
            $pageName = $page['id'] ? basename($page['id']) : '/';
            $hasChildren = !empty($page['children']);
            $uniqueId = 'page_' . md5($page['id']);
            ?>
            <div class="border-b border-surface-100 dark:border-dark-200 last:border-b-0"
                 <?php if ($hasChildren): ?>x-data="{ expanded: false }"<?php endif; ?>>
                <div class="px-6 py-3 grid items-center gap-4 hover:bg-surface-50 dark:hover:bg-dark-300 transition-colors group" style="grid-template-columns: minmax(0,auto) minmax(0,max-content) 1fr;">
                    <!-- Page Info -->
                    <div class="min-w-0 flex items-center gap-2">
                        <?php if ($depth > 0): ?>
                            <div style="width: <?php echo ($depth - 1) * 24 + 8; ?>px;"></div>
                            <svg class="w-3 h-3 text-gray-300 dark:text-gray-600 flex-shrink-0" viewBox="0 0 12 12" fill="none">
                                <path d="M3 0v6h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        <?php endif; ?>
                        <?php if ($hasChildren): ?>
                            <button @click="expanded = !expanded" class="w-6 h-6 rounded flex items-center justify-center hover:bg-surface-200 dark:hover:bg-dark-200 transition-colors flex-shrink-0">
                                <svg class="w-4 h-4 text-gray-400 transition-transform" :class="expanded ? 'rotate-90' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        <?php else: ?>
                            <div class="w-6 flex-shrink-0"></div>
                        <?php endif; ?>
                        <div class="w-8 h-8 rounded-lg <?php echo $hasChildren ? 'bg-accent-100 dark:bg-accent-900/30' : 'bg-surface-100 dark:bg-dark-300'; ?> group-hover:bg-accent-100 dark:group-hover:bg-accent-900/30 transition-colors flex items-center justify-center flex-shrink-0">
                            <?php if ($hasChildren): ?>
                                <svg class="w-4 h-4 text-accent-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                                </svg>
                            <?php else: ?>
                                <svg class="w-4 h-4 text-gray-400 group-hover:text-accent-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                            <?php endif; ?>
                        </div>
                        <div class="min-w-0 flex items-center gap-2">
                            <code class="text-sm font-medium text-gray-900 dark:text-white font-mono break-words" style="word-break: break-word;"><?php echo htmlspecialchars($pageName); ?></code>
                            <?php if ($hasChildren): ?>
                                <span class="text-xs text-gray-400 dark:text-gray-500 flex-shrink-0">(<?php echo count($page['children']); ?>)</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Status (compact; discard sits inline with the draft badge) -->
                    <div class="flex items-center gap-1 whitespace-nowrap">
                        <?php if ($hasDraft): ?>
                            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                                Draft
                            </span>
                            <form method="post" class="inline" onsubmit="return confirm('Discard the draft for this page?');">
                                <?php echo CSRF::inputField(); ?>
                                <input type="hidden" name="action" value="discard">
                                <input type="hidden" name="page_id" value="<?php echo htmlspecialchars($page['id']); ?>">
                                <button type="submit" title="Discard draft" class="inline-flex items-center w-6 h-6 justify-center rounded-md text-gray-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                Live
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Actions
                         Order in HTML matters with justify-end: rightmost is LAST.
                         Always-visible buttons (Edit, Edit with AI, View) come last so
                         they stay anchored at the right edge across every row.
                         Conditional buttons (Discard / Publish / Draft / Delete) come
                         first so they extend leftward when present. -->
                    <div class="flex items-center justify-end gap-1 whitespace-nowrap flex-nowrap">
                        <?php if ($page['id'] !== '' && $page['id'] !== 'index'): ?>
                            <form method="post" class="inline" onsubmit="return confirm('Delete this page?');">
                                <?php echo CSRF::inputField(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="page_id" value="<?php echo htmlspecialchars($page['id']); ?>">
                                <button type="submit" class="inline-flex items-center px-2 py-1 text-sm font-medium text-red-500 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 rounded-lg transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($hasDraft): ?>
                            <form method="post" class="inline" onsubmit="return confirm('Publish this draft?');">
                                <?php echo CSRF::inputField(); ?>
                                <input type="hidden" name="action" value="publish">
                                <input type="hidden" name="page_id" value="<?php echo htmlspecialchars($page['id']); ?>">
                                <button type="submit" class="inline-flex items-center gap-1 px-2.5 py-1 text-sm font-medium text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/30 rounded-lg transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                    Publish
                                </button>
                            </form>

                            <a href="/cms/admin/preview.php?page_id=<?php echo urlencode($page['id']); ?>&draft=1" target="_blank" class="inline-flex items-center gap-1 px-2.5 py-1 text-sm font-medium text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/30 rounded-lg transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                                Draft
                            </a>
                        <?php endif; ?>

                        <a href="/cms/admin/preview.php?page_id=<?php echo urlencode($page['id']); ?>" target="_blank" class="inline-flex items-center gap-1 px-2.5 py-1 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-emerald-600 dark:hover:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/30 rounded-lg transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                            </svg>
                            View
                        </a>

                        <a href="/cms/admin/edit-ai.php?page_id=<?php echo urlencode($page['id']); ?>" class="inline-flex items-center gap-1 px-2.5 py-1 text-sm font-medium text-accent-600 dark:text-accent-400 hover:bg-accent-50 dark:hover:bg-accent-900/30 rounded-lg transition-colors" title="Edit with AI assistant">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                            </svg>
                            Edit with AI
                        </a>

                        <a href="/cms/admin/edit.php?page_id=<?php echo urlencode($page['id']); ?>" class="inline-flex items-center gap-1 px-2.5 py-1 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-accent-600 hover:bg-accent-50 dark:hover:bg-accent-900/30 rounded-lg transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                            </svg>
                            Edit
                        </a>
                    </div>
                </div>

                <?php if ($hasChildren): ?>
                    <div x-show="expanded" x-collapse class="bg-surface-50/50 dark:bg-dark-500/50">
                        <?php foreach ($page['children'] as $child): ?>
                            <?php renderPageRow($child, $pageManager, $depth + 1); ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php
        }
        ?>
        <div>
            <?php foreach ($pageTree as $page): ?>
                <?php renderPageRow($page, $pageManager, 0); ?>
            <?php endforeach; ?>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 bg-surface-50 dark:bg-dark-300 border-t border-surface-200 dark:border-dark-200">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Showing <span class="font-medium text-gray-900 dark:text-white"><?php echo count($pages); ?></span> pages
            </p>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
