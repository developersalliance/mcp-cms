<?php
/**
 * MCP Activity — what AI clients changed through the MCP endpoint.
 *
 * Reads {cms_dir}/logs/mcp-activity.jsonl (written by mcp/index.php via
 * McpActivityLog). Write-type tool calls and every failed call are logged;
 * read-only calls that succeeded are not.
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_capability('settings.manage');
require_once __DIR__ . '/../core/CSRF.php';
require_once __DIR__ . '/../core/McpActivityLog.php';

$successMessage = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    if (($_POST['action'] ?? '') === 'clear') {
        $path = McpActivityLog::path($config);
        $ok = true;
        foreach ([$path, $path . '.1'] as $f) {
            if (is_file($f) && !@unlink($f)) $ok = false;
        }
        if ($ok) {
            header('Location: /cms/admin/mcp-activity.php?cleared=1');
            exit;
        }
        $errorMessage = 'Could not delete the log file — check permissions on cms/logs/.';
    }
}
if (isset($_GET['cleared'])) {
    $successMessage = 'Activity log cleared.';
}

$entries = McpActivityLog::tail($config, 200);

$filterTool = trim((string)($_GET['tool'] ?? ''));
$filterPrincipal = trim((string)($_GET['principal'] ?? ''));
$filterFailed = isset($_GET['failed']);

$toolNames = [];
$principals = [];
foreach ($entries as $e) {
    if (!empty($e['tool'])) $toolNames[$e['tool']] = true;
    if (!empty($e['principal'])) $principals[$e['principal']] = true;
}
ksort($toolNames);
ksort($principals);

$visible = array_values(array_filter($entries, function ($e) use ($filterTool, $filterPrincipal, $filterFailed) {
    if ($filterTool !== '' && ($e['tool'] ?? '') !== $filterTool) return false;
    if ($filterPrincipal !== '' && ($e['principal'] ?? '') !== $filterPrincipal) return false;
    if ($filterFailed && !empty($e['ok'])) return false;
    return true;
}));

$logPath = McpActivityLog::path($config);
$logSize = is_file($logPath) ? filesize($logPath) : 0;

$pageTitle = 'MCP Activity';
$activePage = 'settings';

require __DIR__ . '/includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900 dark:text-white">MCP Activity</h1>
    <p class="text-gray-600 dark:text-gray-400 mt-1">Changes made through the MCP endpoint by AI clients. Successful read-only calls are not recorded.</p>
</div>

<?php if ($successMessage): ?>
<div class="bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-800 dark:text-green-200 rounded-xl px-4 py-3 mb-6"><?php echo htmlspecialchars($successMessage); ?></div>
<?php endif; ?>
<?php if ($errorMessage): ?>
<div class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 rounded-xl px-4 py-3 mb-6"><?php echo htmlspecialchars($errorMessage); ?></div>
<?php endif; ?>

<div class="bg-white dark:bg-dark-400 rounded-2xl shadow-md p-5 mb-6">
    <form method="get" class="flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Tool</label>
            <select name="tool" class="px-3 py-2 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-sm text-gray-900 dark:text-white">
                <option value="">All tools</option>
                <?php foreach (array_keys($toolNames) as $t): ?>
                <option value="<?php echo htmlspecialchars($t); ?>" <?php echo $filterTool === $t ? 'selected' : ''; ?>><?php echo htmlspecialchars($t); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Principal</label>
            <select name="principal" class="px-3 py-2 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-sm text-gray-900 dark:text-white">
                <option value="">All principals</option>
                <?php foreach (array_keys($principals) as $p): ?>
                <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $filterPrincipal === $p ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 pb-2">
            <input type="checkbox" name="failed" value="1" <?php echo $filterFailed ? 'checked' : ''; ?> class="h-4 w-4 rounded border-gray-300">
            Failed only
        </label>
        <button type="submit" class="px-4 py-2 rounded-xl text-sm font-medium bg-accent-600 text-white hover:bg-accent-700">Filter</button>
        <a href="/cms/admin/mcp-activity.php" class="px-4 py-2 rounded-xl text-sm font-medium bg-surface-100 dark:bg-dark-300 text-gray-700 dark:text-gray-300">Reset</a>
        <div class="flex-1"></div>
        <form method="post" onsubmit="return confirm('Delete the whole MCP activity log?');" class="inline">
            <?php echo CSRF::inputField(); ?>
            <input type="hidden" name="action" value="clear">
            <button type="submit" class="px-4 py-2 rounded-xl text-sm font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30">Clear log</button>
        </form>
    </form>
    <p class="text-xs text-gray-400 mt-3">
        Showing <?php echo count($visible); ?> of the last <?php echo count($entries); ?> entries ·
        log file <code class="font-mono">cms/logs/mcp-activity.jsonl</code> (<?php echo number_format($logSize / 1024, 1); ?> KB, rotates at 2 MB)
    </p>
</div>

<div class="bg-white dark:bg-dark-400 rounded-2xl shadow-md overflow-hidden">
    <?php if (!$visible): ?>
    <div class="p-8 text-center text-gray-500 dark:text-gray-400">
        <?php echo $entries ? 'No entries match the filter.' : 'No MCP activity recorded yet. Once an AI client changes something through the endpoint, it shows up here.'; ?>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-surface-50 dark:bg-dark-300 text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
                <tr>
                    <th class="text-left px-4 py-3 font-medium">Time</th>
                    <th class="text-left px-4 py-3 font-medium">Principal</th>
                    <th class="text-left px-4 py-3 font-medium">Client</th>
                    <th class="text-left px-4 py-3 font-medium">Tool</th>
                    <th class="text-left px-4 py-3 font-medium">Target</th>
                    <th class="text-left px-4 py-3 font-medium">Result</th>
                    <th class="text-right px-4 py-3 font-medium">ms</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-surface-100 dark:divide-dark-200">
                <?php foreach ($visible as $e): $ok = !empty($e['ok']); ?>
                <tr class="<?php echo $ok ? '' : 'bg-red-50/60 dark:bg-red-900/10'; ?>">
                    <td class="px-4 py-2.5 whitespace-nowrap text-gray-700 dark:text-gray-300 font-mono text-xs">
                        <?php echo htmlspecialchars(str_replace('T', ' ', substr((string)($e['ts'] ?? ''), 0, 19))); ?>
                        <div class="text-gray-400"><?php echo htmlspecialchars((string)($e['ip'] ?? '')); ?></div>
                    </td>
                    <td class="px-4 py-2.5 text-gray-900 dark:text-white font-mono text-xs"><?php echo htmlspecialchars((string)($e['principal'] ?? '')); ?></td>
                    <td class="px-4 py-2.5 text-gray-600 dark:text-gray-400 text-xs"><?php echo htmlspecialchars((string)($e['client'] ?? '')) ?: '<span class="text-gray-400">—</span>'; ?></td>
                    <td class="px-4 py-2.5 font-mono text-xs text-gray-900 dark:text-white"><?php echo htmlspecialchars((string)($e['tool'] ?? '')); ?></td>
                    <td class="px-4 py-2.5 text-gray-600 dark:text-gray-400 text-xs break-all max-w-xs"><?php echo htmlspecialchars((string)($e['target'] ?? '')); ?></td>
                    <td class="px-4 py-2.5 text-xs">
                        <?php if ($ok): ?>
                            <span class="inline-block px-2 py-0.5 rounded-full bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300">ok</span>
                        <?php else: ?>
                            <span class="inline-block px-2 py-0.5 rounded-full bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300">failed</span>
                            <div class="text-red-700 dark:text-red-300 mt-1 break-words max-w-xs"><?php echo htmlspecialchars((string)($e['error'] ?? '')); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-2.5 text-right font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo (int)($e['ms'] ?? 0); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
