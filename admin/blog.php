<?php
/**
 * Admin Blog Posts Listing
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/BlogManager.php';
require_once __DIR__ . '/../core/AuthorManager.php';
require_once __DIR__ . '/../core/BackupManager.php';
require_once __DIR__ . '/../core/SitemapGenerator.php';
require_once __DIR__ . '/../core/CSRF.php';

$reservedFolders = $config['reserved_folders'] ?? ['cms'];
$backupManager = new BackupManager($config['backups_dir'], $config['max_backups_per_page']);
$sitemapGenerator = new SitemapGenerator($config['root_dir'], $config['base_url'] ?? 'http://localhost', $reservedFolders, $config['drafts_dir'] ?? null);
$blogManager = new BlogManager($config['root_dir'], $config['cms_dir'], $sitemapGenerator, $backupManager);
$authorManager = new AuthorManager($config['cms_dir'] . '/config');

// Publish scheduled posts on page load
$blogManager->publishScheduledPosts();

$collectionId = $_GET['collection'] ?? 'blog';
$collections = $blogManager->getCollections();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $action = $_POST['action'] ?? '';
    $slug = $_POST['slug'] ?? '';
    $collectionId = $_POST['collection'] ?? $collectionId;

    try {
        switch ($action) {
            case 'publish':
                $blogManager->publishPost($collectionId, $slug);
                $successMessage = "Post published successfully.";
                break;
            case 'unpublish':
                $blogManager->unpublishPost($collectionId, $slug);
                $successMessage = "Post unpublished successfully.";
                break;
            case 'delete':
                $blogManager->deletePost($collectionId, $slug);
                $successMessage = "Post deleted successfully.";
                break;
        }
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

// Get posts
try {
    $posts = $blogManager->listPosts($collectionId);
} catch (Exception $e) {
    $posts = [];
    $errorMessage = $e->getMessage();
}

// Separate by status
$drafts = array_filter($posts, fn($p) => ($p['status'] ?? 'draft') === 'draft');
$published = array_filter($posts, fn($p) => ($p['status'] ?? '') === 'published');
$scheduled = array_filter($posts, fn($p) => ($p['status'] ?? '') === 'scheduled');

// Resolve authors
$authorsById = [];
foreach ($authorManager->listAuthors() as $a) {
    $authorsById[$a['id']] = $a;
}

$pageTitle = 'Blog Posts';
$activePage = 'blog';

require __DIR__ . '/includes/header.php';
?>

<div class="flex items-center justify-between mb-6">
    <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Blog Posts</h1>
    <div class="flex gap-3">
        <a href="/cms/admin/authors.php" class="px-4 py-2.5 bg-surface-100 dark:bg-dark-300 text-gray-700 dark:text-gray-300 rounded-xl hover:bg-surface-200 dark:hover:bg-dark-200 transition text-sm font-medium">
            Manage Authors
        </a>
        <a href="/cms/admin/blog-edit.php?collection=<?php echo urlencode($collectionId); ?>" class="btn-primary px-5 py-2.5 text-white rounded-xl font-medium shadow-lg shadow-accent-500/25 text-sm">
            + New Post
        </a>
    </div>
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

<!-- Collection selector -->
<?php if (count($collections) > 1): ?>
<div class="mb-6">
    <select onchange="window.location.href='?collection=' + this.value" class="px-4 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white">
        <?php foreach ($collections as $coll): ?>
            <option value="<?php echo htmlspecialchars($coll['id']); ?>" <?php echo $coll['id'] === $collectionId ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($coll['label']); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>

<?php if (empty($posts)): ?>
    <div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 p-12 text-center">
        <p class="text-gray-500 dark:text-gray-400 text-lg mb-4">No posts in this collection yet.</p>
        <a href="/cms/admin/blog-edit.php?collection=<?php echo urlencode($collectionId); ?>" class="btn-primary px-6 py-2.5 text-white rounded-xl font-medium inline-block">Create Your First Post</a>
    </div>
<?php else: ?>

<?php
function renderPostTable($posts, $authorsById, $collectionId, $statusLabel, $statusColor, $showScheduled = false) {
    if (empty($posts)) return;
?>
    <div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 mb-6 overflow-hidden">
        <div class="px-6 py-4 border-b border-surface-200 dark:border-dark-200">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white"><?php echo $statusLabel; ?> <span class="text-sm font-normal text-gray-500 dark:text-gray-400">(<?php echo count($posts); ?>)</span></h2>
        </div>
        <div class="overflow-x-auto">
            <table class="table-modern min-w-full">
                <thead>
                    <tr class="bg-surface-50 dark:bg-dark-300">
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Title</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Author</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-surface-100 dark:divide-dark-200">
                    <?php foreach ($posts as $post):
                        $author = $authorsById[$post['author_id'] ?? ''] ?? null;
                        $date = $post['published_at'] ?? $post['created_at'] ?? '';
                    ?>
                    <tr>
                        <td class="px-6 py-4">
                            <div>
                                <a href="/cms/admin/blog-edit.php?collection=<?php echo urlencode($collectionId); ?>&slug=<?php echo urlencode($post['slug']); ?>"
                                   class="text-sm font-semibold text-gray-900 dark:text-white hover:text-accent-600 dark:hover:text-accent-400 transition">
                                    <?php echo htmlspecialchars($post['title'] ?? $post['slug']); ?>
                                </a>
                                <?php if (!empty($post['featured'])): ?>
                                    <span class="ml-2 px-1.5 py-0.5 text-xs font-semibold bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 rounded">Featured</span>
                                <?php endif; ?>
                                <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5 font-mono">/<?php echo htmlspecialchars($post['slug']); ?></div>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">
                            <?php echo htmlspecialchars($author['name'] ?? ($post['author_id'] ?? '—')); ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">
                            <?php if ($showScheduled && !empty($post['scheduled_at'])): ?>
                                <span title="Scheduled for <?php echo htmlspecialchars($post['scheduled_at']); ?>">
                                    <?php echo htmlspecialchars($post['scheduled_at']); ?>
                                </span>
                            <?php else: ?>
                                <?php echo $date ? date('M j, Y', strtotime($date)) : '—'; ?>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4">
                            <span class="badge px-2 py-1 rounded-full <?php echo $statusColor; ?>">
                                <?php echo ucfirst($post['status'] ?? 'draft'); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right text-sm whitespace-nowrap">
                            <a href="/cms/admin/blog-edit.php?collection=<?php echo urlencode($collectionId); ?>&slug=<?php echo urlencode($post['slug']); ?>"
                               class="text-accent-600 dark:text-accent-400 hover:text-accent-700 font-medium mr-3">Edit</a>

                            <a href="/cms/admin/blog-edit-ai.php?collection=<?php echo urlencode($collectionId); ?>&slug=<?php echo urlencode($post['slug']); ?>"
                               class="text-violet-600 dark:text-violet-400 hover:text-violet-700 font-medium mr-3" title="AI-assisted editing">Edit with AI</a>

                            <a href="/cms/admin/blog-preview.php?collection=<?php echo urlencode($collectionId); ?>&slug=<?php echo urlencode($post['slug']); ?>"
                               target="_blank"
                               class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 font-medium mr-3">Preview</a>

                            <?php if (($post['status'] ?? 'draft') === 'draft' || ($post['status'] ?? '') === 'scheduled'): ?>
                            <form method="post" class="inline" onsubmit="return confirm('Publish this post?');">
                                <?php echo CSRF::inputField(); ?>
                                <input type="hidden" name="action" value="publish">
                                <input type="hidden" name="collection" value="<?php echo htmlspecialchars($collectionId); ?>">
                                <input type="hidden" name="slug" value="<?php echo htmlspecialchars($post['slug']); ?>">
                                <button type="submit" class="text-emerald-600 dark:text-emerald-400 hover:text-emerald-700 font-medium mr-3">Publish</button>
                            </form>
                            <?php endif; ?>

                            <?php if (($post['status'] ?? '') === 'published'): ?>
                            <a href="/<?php echo htmlspecialchars($collectionId . '/' . $post['slug']); ?>/" target="_blank"
                               class="text-blue-600 dark:text-blue-400 hover:text-blue-700 font-medium mr-3">View</a>
                            <form method="post" class="inline" onsubmit="return confirm('Unpublish this post?');">
                                <?php echo CSRF::inputField(); ?>
                                <input type="hidden" name="action" value="unpublish">
                                <input type="hidden" name="collection" value="<?php echo htmlspecialchars($collectionId); ?>">
                                <input type="hidden" name="slug" value="<?php echo htmlspecialchars($post['slug']); ?>">
                                <button type="submit" class="text-amber-600 dark:text-amber-400 hover:text-amber-700 font-medium mr-3">Unpublish</button>
                            </form>
                            <?php endif; ?>

                            <form method="post" class="inline" onsubmit="return confirm('Delete this post? This cannot be undone!');">
                                <?php echo CSRF::inputField(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="collection" value="<?php echo htmlspecialchars($collectionId); ?>">
                                <input type="hidden" name="slug" value="<?php echo htmlspecialchars($post['slug']); ?>">
                                <button type="submit" class="text-red-500 dark:text-red-400 hover:text-red-600 font-medium">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php } ?>

<?php renderPostTable($scheduled, $authorsById, $collectionId, 'Scheduled', 'bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400', true); ?>
<?php renderPostTable($drafts, $authorsById, $collectionId, 'Drafts', 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400'); ?>
<?php renderPostTable($published, $authorsById, $collectionId, 'Published', 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400'); ?>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
