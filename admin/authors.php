<?php
/**
 * Admin Authors Management
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/AuthorManager.php';
require_once __DIR__ . '/../core/CSRF.php';

$authorManager = new AuthorManager($config['cms_dir'] . '/config');

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $id = $_POST['id'] ?? '';
            if (empty($id)) throw new Exception('Author ID is required');
            $authorManager->createAuthor($id, [
                'name' => $_POST['name'] ?? '',
                'email' => $_POST['email'] ?? '',
                'bio' => $_POST['bio'] ?? '',
                'avatar' => $_POST['avatar'] ?? '',
                'role' => $_POST['role'] ?? 'Author',
                'social' => array_filter([
                    'twitter' => $_POST['social_twitter'] ?? '',
                    'github' => $_POST['social_github'] ?? '',
                    'linkedin' => $_POST['social_linkedin'] ?? '',
                    'website' => $_POST['social_website'] ?? '',
                ]),
            ]);
            $successMessage = 'Author created.';
        } elseif ($action === 'update') {
            $id = $_POST['id'] ?? '';
            $authorManager->updateAuthor($id, [
                'name' => $_POST['name'] ?? '',
                'email' => $_POST['email'] ?? '',
                'bio' => $_POST['bio'] ?? '',
                'avatar' => $_POST['avatar'] ?? '',
                'role' => $_POST['role'] ?? 'Author',
                'social' => array_filter([
                    'twitter' => $_POST['social_twitter'] ?? '',
                    'github' => $_POST['social_github'] ?? '',
                    'linkedin' => $_POST['social_linkedin'] ?? '',
                    'website' => $_POST['social_website'] ?? '',
                ]),
            ]);
            $successMessage = 'Author updated.';
        } elseif ($action === 'delete') {
            $authorManager->deleteAuthor($_POST['id'] ?? '');
            $successMessage = 'Author deleted.';
        }
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

$authors = $authorManager->listAuthors();
$editAuthor = null;
if (!empty($_GET['edit'])) {
    $editAuthor = $authorManager->getAuthor($_GET['edit']);
}

$pageTitle = 'Authors';
$activePage = 'blog';

require __DIR__ . '/includes/header.php';
?>

<div class="flex items-center justify-between mb-6">
    <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Authors</h1>
    <a href="/cms/admin/blog.php" class="text-sm text-accent-600 hover:text-accent-700">&larr; Back to Posts</a>
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

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    <!-- Author Form -->
    <div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
            <?php echo $editAuthor ? 'Edit Author' : 'New Author'; ?>
        </h2>
        <form method="post" class="space-y-4">
            <?php echo CSRF::inputField(); ?>
            <input type="hidden" name="action" value="<?php echo $editAuthor ? 'update' : 'create'; ?>">

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">ID (slug)</label>
                    <input type="text" name="id" value="<?php echo htmlspecialchars($editAuthor['id'] ?? ''); ?>"
                           <?php echo $editAuthor ? 'readonly' : ''; ?>
                           placeholder="john-doe" required
                           class="w-full px-3 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white text-sm font-mono <?php echo $editAuthor ? 'opacity-60' : ''; ?>">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Name</label>
                    <input type="text" name="name" value="<?php echo htmlspecialchars($editAuthor['name'] ?? ''); ?>"
                           placeholder="John Doe" required
                           class="w-full px-3 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white text-sm">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Email</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($editAuthor['email'] ?? ''); ?>"
                           placeholder="john@example.com"
                           class="w-full px-3 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Role</label>
                    <input type="text" name="role" value="<?php echo htmlspecialchars($editAuthor['role'] ?? 'Author'); ?>"
                           placeholder="Editor"
                           class="w-full px-3 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white text-sm">
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Avatar URL</label>
                <input type="text" name="avatar" value="<?php echo htmlspecialchars($editAuthor['avatar'] ?? ''); ?>"
                       placeholder="/assets/content/authors/avatar.jpg"
                       class="w-full px-3 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white text-sm">
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Bio</label>
                <textarea name="bio" rows="3"
                          placeholder="Short bio..."
                          class="w-full px-3 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white text-sm"><?php echo htmlspecialchars($editAuthor['bio'] ?? ''); ?></textarea>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Twitter</label>
                    <input type="text" name="social_twitter" value="<?php echo htmlspecialchars($editAuthor['social']['twitter'] ?? ''); ?>"
                           placeholder="@handle"
                           class="w-full px-3 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">GitHub</label>
                    <input type="text" name="social_github" value="<?php echo htmlspecialchars($editAuthor['social']['github'] ?? ''); ?>"
                           placeholder="username"
                           class="w-full px-3 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">LinkedIn</label>
                    <input type="text" name="social_linkedin" value="<?php echo htmlspecialchars($editAuthor['social']['linkedin'] ?? ''); ?>"
                           placeholder="in/username"
                           class="w-full px-3 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Website</label>
                    <input type="text" name="social_website" value="<?php echo htmlspecialchars($editAuthor['social']['website'] ?? ''); ?>"
                           placeholder="https://example.com"
                           class="w-full px-3 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white text-sm">
                </div>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="btn-primary px-5 py-2.5 text-white rounded-xl font-medium shadow-lg shadow-accent-500/25 text-sm">
                    <?php echo $editAuthor ? 'Update Author' : 'Create Author'; ?>
                </button>
<?php if ($editAuthor): ?>
                <a href="/cms/admin/authors.php" class="px-5 py-2.5 bg-surface-100 dark:bg-dark-300 text-gray-700 dark:text-gray-300 rounded-xl text-sm hover:bg-surface-200 dark:hover:bg-dark-200 transition inline-flex items-center">Cancel</a>
<?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Authors List -->
    <div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-surface-200 dark:border-dark-200">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">All Authors (<?php echo count($authors); ?>)</h2>
        </div>
<?php if (empty($authors)): ?>
        <div class="p-6 text-center text-gray-500 dark:text-gray-400">No authors yet.</div>
<?php else: ?>
        <div class="divide-y divide-surface-100 dark:divide-dark-200">
<?php foreach ($authors as $a): ?>
            <div class="px-6 py-4 flex items-center justify-between hover:bg-surface-50 dark:hover:bg-dark-300 transition">
                <div class="flex items-center gap-3">
<?php if (!empty($a['avatar'])): ?>
                    <img src="<?php echo htmlspecialchars($a['avatar']); ?>" alt="" class="w-10 h-10 rounded-full object-cover">
<?php else: ?>
                    <div class="w-10 h-10 rounded-full bg-surface-200 dark:bg-dark-200 flex items-center justify-center text-gray-500 dark:text-gray-400 font-semibold text-sm">
                        <?php echo strtoupper(substr($a['name'] ?: $a['id'], 0, 1)); ?>
                    </div>
<?php endif; ?>
                    <div>
                        <div class="font-semibold text-sm text-gray-900 dark:text-white"><?php echo htmlspecialchars($a['name'] ?: $a['id']); ?></div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            <code><?php echo htmlspecialchars($a['id']); ?></code>
                            <?php if (!empty($a['role'])): ?> &middot; <?php echo htmlspecialchars($a['role']); ?><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="flex gap-2">
                    <a href="?edit=<?php echo urlencode($a['id']); ?>" class="text-xs text-accent-600 hover:text-accent-700 font-medium">Edit</a>
                    <form method="post" class="inline" onsubmit="return confirm('Delete this author?');">
                        <?php echo CSRF::inputField(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($a['id']); ?>">
                        <button type="submit" class="text-xs text-red-500 hover:text-red-600 font-medium">Delete</button>
                    </form>
                </div>
            </div>
<?php endforeach; ?>
        </div>
<?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
