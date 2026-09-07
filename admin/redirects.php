<?php
/**
 * Admin Redirects Management
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_capability('settings.manage');
require_once __DIR__ . '/../core/RedirectManager.php';
require_once __DIR__ . '/../core/CSRF.php';

$redirectManager = new RedirectManager($config['cms_dir'] . '/settings', $config['root_dir'] ?? null);

$htaccessResult = null;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $entry = $redirectManager->addRedirect(
                $_POST['from'] ?? '',
                $_POST['to'] ?? '',
                (int)($_POST['code'] ?? 301)
            );
            $successMessage = 'Redirect saved: ' . $entry['from'] . ' → ' . $entry['to'];
            $htaccessResult = $redirectManager->syncHtaccess();
        } elseif ($action === 'delete') {
            if ($redirectManager->deleteRedirect($_POST['from'] ?? '')) {
                $successMessage = 'Redirect deleted.';
                $htaccessResult = $redirectManager->syncHtaccess();
            } else {
                $errorMessage = 'Redirect not found.';
            }
        }
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

$redirects = $redirectManager->listRedirects();

$pageTitle = 'Redirects';
$activePage = 'settings';

require __DIR__ . '/includes/header.php';
?>

<div class="flex items-center justify-between mb-6">
    <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Redirects</h1>
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
<?php if ($htaccessResult !== null): ?>
    <div class="<?php echo $htaccessResult['synced'] ? 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-800' : 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800'; ?> border rounded-xl p-4 mb-6">
        <p class="<?php echo $htaccessResult['synced'] ? 'text-emerald-800 dark:text-emerald-300' : 'text-amber-800 dark:text-amber-300'; ?> text-sm"><?php echo htmlspecialchars($htaccessResult['message']); ?></p>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    <!-- Redirect Form -->
    <div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">New Redirect</h2>
        <form method="post" class="space-y-4">
            <?php echo CSRF::inputField(); ?>
            <input type="hidden" name="action" value="create">

            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">From (old path)</label>
                <input type="text" name="from" placeholder="/old-page" required
                       class="w-full px-3 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white text-sm font-mono">
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">A site-relative path. Saving the same path again replaces the existing redirect.</p>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">To (new location)</label>
                <input type="text" name="to" placeholder="/new-page or https://example.com/page" required
                       class="w-full px-3 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white text-sm font-mono">
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Type</label>
                <select name="code"
                        class="w-full px-3 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white text-sm">
                    <option value="301">301 — Permanent (search engines update their index)</option>
                    <option value="302">302 — Temporary</option>
                </select>
            </div>

            <div>
                <button type="submit" class="btn-primary px-5 py-2.5 text-white rounded-xl font-medium shadow-lg shadow-accent-500/25 text-sm">
                    Save Redirect
                </button>
            </div>
        </form>

        <div class="mt-6 pt-4 border-t border-surface-200 dark:border-dark-200 text-xs text-gray-500 dark:text-gray-400 leading-relaxed">
            On Apache, saved redirects are also written into the site's <code>.htaccess</code>
            between <code>CMS-REDIRECTS-BEGIN/END</code> markers. On nginx, wire the 404 handler
            to <code>/cms/redirect.php</code> — see <code>docs/redirects.md</code> in the engine.
        </div>
    </div>

    <!-- Redirects List -->
    <div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-surface-200 dark:border-dark-200">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">All Redirects (<?php echo count($redirects); ?>)</h2>
        </div>
<?php if (empty($redirects)): ?>
        <div class="p-6 text-center text-gray-500 dark:text-gray-400">No redirects yet.</div>
<?php else: ?>
        <div class="divide-y divide-surface-100 dark:divide-dark-200">
<?php foreach ($redirects as $r): ?>
            <div class="px-6 py-4 flex items-center justify-between hover:bg-surface-50 dark:hover:bg-dark-300 transition">
                <div class="min-w-0">
                    <div class="font-mono text-sm text-gray-900 dark:text-white truncate">
                        <?php echo htmlspecialchars($r['from']); ?>
                        <span class="text-gray-400">&rarr;</span>
                        <?php echo htmlspecialchars($r['to']); ?>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        <span class="inline-block px-1.5 py-0.5 rounded bg-surface-100 dark:bg-dark-300 font-medium"><?php echo (int)$r['code']; ?></span>
                        <?php if (!empty($r['created_at'])): ?> &middot; added <?php echo htmlspecialchars(date('M d, Y', strtotime($r['created_at']) ?: time())); ?><?php endif; ?>
                    </div>
                </div>
                <form method="post" class="inline ml-4 shrink-0" onsubmit="return confirm('Delete this redirect?');">
                    <?php echo CSRF::inputField(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="from" value="<?php echo htmlspecialchars($r['from']); ?>">
                    <button type="submit" class="text-xs text-red-500 hover:text-red-600 font-medium">Delete</button>
                </form>
            </div>
<?php endforeach; ?>
        </div>
<?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
