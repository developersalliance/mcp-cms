<?php
/**
 * Admin Dashboard
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/PageManager.php';
require_once __DIR__ . '/../core/PageSettings.php';
require_once __DIR__ . '/../core/BlogManager.php';

$reservedFolders = $config['reserved_folders'] ?? ['cms'];
$pageSettings = new PageSettings($config['cms_dir'] . '/settings');
$pageManager = new PageManager($config['root_dir'], $reservedFolders, null, null, null, $pageSettings);
$pages = $pageManager->listPages();

// Get blog posts count
$blogManager = new BlogManager($config['root_dir'], $config['cms_dir']);
$collections = $blogManager->getCollections();
$totalPosts = 0;
foreach ($collections as $collection) {
    $posts = $blogManager->listPosts($collection['id']);
    $totalPosts += count($posts);
}

// Count drafts
$draftsCount = 0;
foreach ($pages as $page) {
    if ($pageManager->hasDraft($page['id'])) {
        $draftsCount++;
    }
}

$pageTitle = 'Dashboard';
$activePage = 'dashboard';

require __DIR__ . '/includes/header.php';
?>

<!-- Page Header -->
<div class="mb-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-gray-900 dark:text-white tracking-tight">Welcome back, <?php echo htmlspecialchars($currentUser['username']); ?></h1>
            <p class="mt-2 text-gray-500 dark:text-gray-400">Here's what's happening with your site today.</p>
        </div>
        <div class="flex gap-3">
            <a href="/cms/admin/create.php" class="btn-primary inline-flex items-center gap-2 px-5 py-2.5 text-white rounded-xl font-medium text-sm shadow-lg shadow-accent-500/25">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                New Page
            </a>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <!-- Total Pages -->
    <div class="bg-white dark:bg-dark-400 rounded-2xl p-6 shadow-soft card-hover border border-surface-200 dark:border-dark-200">
        <div class="flex items-center justify-between mb-4">
            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center shadow-lg shadow-blue-500/25">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            </div>
            <span class="text-xs font-semibold text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/30 px-2.5 py-1 rounded-full">Pages</span>
        </div>
        <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo count($pages); ?></p>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Total pages</p>
    </div>

    <!-- Drafts -->
    <div class="bg-white dark:bg-dark-400 rounded-2xl p-6 shadow-soft card-hover border border-surface-200 dark:border-dark-200">
        <div class="flex items-center justify-between mb-4">
            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-500 to-orange-500 flex items-center justify-center shadow-lg shadow-amber-500/25">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                </svg>
            </div>
            <span class="text-xs font-semibold text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 px-2.5 py-1 rounded-full">Pending</span>
        </div>
        <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $draftsCount; ?></p>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Unpublished drafts</p>
    </div>

    <!-- Blog Posts -->
    <div class="bg-white dark:bg-dark-400 rounded-2xl p-6 shadow-soft card-hover border border-surface-200 dark:border-dark-200">
        <div class="flex items-center justify-between mb-4">
            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-500 flex items-center justify-center shadow-lg shadow-emerald-500/25">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"></path>
                </svg>
            </div>
            <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/30 px-2.5 py-1 rounded-full">Posts</span>
        </div>
        <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $totalPosts; ?></p>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Blog posts</p>
    </div>

    <!-- Collections -->
    <div class="bg-white dark:bg-dark-400 rounded-2xl p-6 shadow-soft card-hover border border-surface-200 dark:border-dark-200">
        <div class="flex items-center justify-between mb-4">
            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center shadow-lg shadow-violet-500/25">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                </svg>
            </div>
            <span class="text-xs font-semibold text-violet-600 dark:text-violet-400 bg-violet-50 dark:bg-violet-900/30 px-2.5 py-1 rounded-full">Active</span>
        </div>
        <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo count($collections); ?></p>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Collections</p>
    </div>
</div>

<!-- Main Content Grid -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Recent Pages -->
    <div class="lg:col-span-2 bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 overflow-hidden">
        <div class="px-6 py-5 border-b border-surface-200 dark:border-dark-200 flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Recent Pages</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Your latest content updates</p>
            </div>
            <a href="/cms/admin/pages.php" class="text-sm font-medium text-accent-600 hover:text-accent-700 transition-colors flex items-center gap-1">
                View all
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>
            </a>
        </div>

        <?php if (empty($pages)): ?>
            <div class="p-12 text-center">
                <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-surface-100 dark:bg-dark-300 flex items-center justify-center">
                    <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                </div>
                <h3 class="text-gray-900 dark:text-white font-medium mb-1">No pages yet</h3>
                <p class="text-gray-500 dark:text-gray-400 text-sm mb-4">Create your first page to get started.</p>
                <a href="/cms/admin/create.php" class="btn-primary inline-flex items-center gap-2 px-4 py-2 text-white rounded-lg font-medium text-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    Create Page
                </a>
            </div>
        <?php else: ?>
            <div class="divide-y divide-surface-100 dark:divide-dark-200">
                <?php foreach (array_slice($pages, 0, 6) as $index => $page): ?>
                    <?php $hasDraft = $pageManager->hasDraft($page['id']); ?>
                    <div class="px-6 py-4 hover:bg-surface-50 dark:hover:bg-dark-300 transition-colors group flex items-center justify-between" style="animation: fadeIn 0.3s ease-out <?php echo ($index * 0.05); ?>s both;">
                        <div class="flex items-center gap-4">
                            <div class="w-10 h-10 rounded-xl bg-surface-100 dark:bg-dark-300 group-hover:bg-accent-100 dark:group-hover:bg-accent-900/30 transition-colors flex items-center justify-center">
                                <svg class="w-5 h-5 text-gray-400 group-hover:text-accent-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <code class="text-sm font-medium text-gray-900 dark:text-white font-mono"><?php echo htmlspecialchars($page['id'] ?: '/'); ?></code>
                                    <?php if ($hasDraft): ?>
                                        <span class="badge px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400">DRAFT</span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Last modified recently</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                            <a href="/cms/admin/edit.php?page_id=<?php echo urlencode($page['id']); ?>" class="p-2 rounded-lg hover:bg-surface-200 dark:hover:bg-dark-200 transition-colors" title="Edit">
                                <svg class="w-4 h-4 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                </svg>
                            </a>
                            <a href="/cms/admin/preview.php?page_id=<?php echo urlencode($page['id']); ?>" target="_blank" class="p-2 rounded-lg hover:bg-surface-200 dark:hover:bg-dark-200 transition-colors" title="Preview">
                                <svg class="w-4 h-4 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                </svg>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Quick Actions & Info -->
    <div class="space-y-6">
        <!-- Quick Actions -->
        <div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 overflow-hidden">
            <div class="px-6 py-5 border-b border-surface-200 dark:border-dark-200">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Quick Actions</h2>
            </div>
            <div class="p-4 space-y-2">
                <a href="/cms/admin/create.php" class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-surface-50 dark:hover:bg-dark-300 transition-colors group">
                    <div class="w-10 h-10 rounded-lg bg-accent-100 dark:bg-accent-900/30 group-hover:bg-accent-200 dark:group-hover:bg-accent-900/50 transition-colors flex items-center justify-center">
                        <svg class="w-5 h-5 text-accent-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">Create New Page</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Add a new page to your site</p>
                    </div>
                </a>

                <a href="/cms/admin/media.php" class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-surface-50 dark:hover:bg-dark-300 transition-colors group">
                    <div class="w-10 h-10 rounded-lg bg-blue-100 dark:bg-blue-900/30 group-hover:bg-blue-200 dark:group-hover:bg-blue-900/50 transition-colors flex items-center justify-center">
                        <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">Media Library</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Upload and manage images</p>
                    </div>
                </a>

                <a href="/cms/admin/blog.php" class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-surface-50 dark:hover:bg-dark-300 transition-colors group">
                    <div class="w-10 h-10 rounded-lg bg-emerald-100 dark:bg-emerald-900/30 group-hover:bg-emerald-200 dark:group-hover:bg-emerald-900/50 transition-colors flex items-center justify-center">
                        <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">Blog Posts</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Manage your blog content</p>
                    </div>
                </a>

                <a href="/cms/admin/settings.php" class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-surface-50 dark:hover:bg-dark-300 transition-colors group">
                    <div class="w-10 h-10 rounded-lg bg-gray-100 dark:bg-dark-300 group-hover:bg-gray-200 dark:group-hover:bg-dark-200 transition-colors flex items-center justify-center">
                        <svg class="w-5 h-5 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">Settings</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Configure your CMS</p>
                    </div>
                </a>
            </div>
        </div>

        <!-- Site Info -->
        <div class="bg-gradient-to-br from-gray-900 to-gray-800 dark:from-dark-300 dark:to-dark-400 rounded-2xl shadow-soft-lg p-6 text-white">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-xl bg-white/10 flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path>
                    </svg>
                </div>
                <div>
                    <p class="text-sm text-gray-400">Site Name</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($config['site_name'] ?? 'Your Website'); ?></p>
                </div>
            </div>
            <a href="/" target="_blank" class="flex items-center justify-center gap-2 w-full py-2.5 bg-white/10 hover:bg-white/20 rounded-xl transition-colors text-sm font-medium">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                </svg>
                View Live Site
            </a>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
