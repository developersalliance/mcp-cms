<?php
/**
 * Admin Newsletter Subscribers
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_capability('settings.manage');
require_once __DIR__ . '/../core/SubscriberManager.php';
require_once __DIR__ . '/../core/CSRF.php';

$subscriberManager = new SubscriberManager($config['cms_dir'] . '/settings', $config);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'delete') {
            if ($subscriberManager->deleteSubscriber($_POST['email'] ?? '')) {
                $successMessage = 'Subscriber deleted.';
            } else {
                $errorMessage = 'Subscriber not found.';
            }
        }
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

$subscribers = $subscriberManager->listSubscribers();

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="subscribers-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['email', 'status', 'created_at', 'confirmed_at']);
    foreach ($subscribers as $s) {
        fputcsv($out, [$s['email'], $s['status'], $s['created_at'], $s['confirmed_at'] ?? '']);
    }
    fclose($out);
    exit;
}

$confirmedCount = count(array_filter($subscribers, fn($s) => $s['status'] === 'confirmed'));
$pendingCount = count($subscribers) - $confirmedCount;

$pageTitle = 'Subscribers';
$activePage = 'settings';

require __DIR__ . '/includes/header.php';
?>

<div class="flex items-center justify-between mb-6">
    <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Subscribers</h1>
    <a href="?export=csv" class="px-4 py-2 bg-surface-100 dark:bg-dark-300 text-gray-700 dark:text-gray-300 rounded-xl text-sm font-medium hover:bg-surface-200 dark:hover:bg-dark-200 transition">
        Export CSV
    </a>
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

<div class="grid grid-cols-2 gap-4 mb-6 max-w-md">
    <div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 p-4">
        <div class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $confirmedCount; ?></div>
        <div class="text-xs text-gray-500 dark:text-gray-400">Confirmed</div>
    </div>
    <div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 p-4">
        <div class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $pendingCount; ?></div>
        <div class="text-xs text-gray-500 dark:text-gray-400">Pending confirmation</div>
    </div>
</div>

<div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-surface-200 dark:border-dark-200">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">All Subscribers (<?php echo count($subscribers); ?>)</h2>
    </div>
<?php if (empty($subscribers)): ?>
    <div class="p-6 text-center text-gray-500 dark:text-gray-400">
        No subscribers yet. Point a signup form at <code>/cms/subscribe.php</code> to start collecting them.
    </div>
<?php else: ?>
    <div class="divide-y divide-surface-100 dark:divide-dark-200">
<?php foreach ($subscribers as $s): ?>
        <div class="px-6 py-4 flex items-center justify-between hover:bg-surface-50 dark:hover:bg-dark-300 transition">
            <div class="min-w-0">
                <div class="font-medium text-sm text-gray-900 dark:text-white truncate"><?php echo htmlspecialchars($s['email']); ?></div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
<?php if ($s['status'] === 'confirmed'): ?>
                    <span class="inline-block px-1.5 py-0.5 rounded bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 font-medium">Confirmed</span>
<?php if (!empty($s['confirmed_at'])): ?> &middot; <?php echo htmlspecialchars(date('M d, Y', strtotime($s['confirmed_at']) ?: time())); ?><?php endif; ?>
<?php else: ?>
                    <span class="inline-block px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 font-medium">Pending</span>
<?php endif; ?>
<?php if (!empty($s['created_at'])): ?> &middot; signed up <?php echo htmlspecialchars(date('M d, Y', strtotime($s['created_at']) ?: time())); ?><?php endif; ?>
                </div>
            </div>
            <form method="post" class="inline ml-4 shrink-0" onsubmit="return confirm('Delete this subscriber?');">
                <?php echo CSRF::inputField(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($s['email']); ?>">
                <button type="submit" class="text-xs text-red-500 hover:text-red-600 font-medium">Delete</button>
            </form>
        </div>
<?php endforeach; ?>
    </div>
<?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
