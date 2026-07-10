<?php
/**
 * Admin Roles & Permissions Grid
 *
 * Editable matrix of role x capability. Persists to config/roles.json via
 * Permissions::saveGrid(). The owner role is always all-capable and locked.
 * Gated behind 'users.manage'.
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_capability('users.manage');
require_once __DIR__ . '/../core/CSRF.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    try {
        // $_POST['caps'] = [ role => [ capability => 'on', ... ], ... ]
        $submitted = $_POST['caps'] ?? [];
        $grid = [];
        foreach ($permissions->roles() as $role => $_def) {
            if ($role === 'owner') {
                continue; // fixed
            }
            $grid[$role] = array_keys($submitted[$role] ?? []);
        }
        $permissions->saveGrid($grid);
        $successMessage = 'Permissions updated.';
        // Reload so the grid reflects what was saved (and applies to this request).
        $permissions = new Permissions(__DIR__ . '/../config/roles.json');
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

$catalog = Permissions::CATALOG;
$roles = $permissions->roles();

$pageTitle = 'Roles & Permissions';
$activePage = 'roles';

require __DIR__ . '/includes/header.php';
?>

<div class="flex items-center justify-between mb-2">
    <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Roles &amp; Permissions</h1>
    <a href="/cms/admin/users.php" class="text-sm text-accent-600 hover:text-accent-700">&larr; Back to Users</a>
</div>
<p class="text-gray-500 dark:text-gray-400 mb-6 text-sm">
    Tick the capabilities each role should have. The <strong>Owner</strong> role always has every permission and cannot be changed.
    Changes apply to every user with that role.
</p>

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

<form method="post">
    <?php echo CSRF::inputField(); ?>
    <div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft dark:shadow-dark-soft border border-surface-200 dark:border-dark-200 overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="border-b border-surface-200 dark:border-dark-200">
                    <th class="px-5 py-4 text-xs uppercase tracking-wider text-gray-400 sticky left-0 bg-white dark:bg-dark-400">Capability</th>
                    <?php foreach ($roles as $roleKey => $roleDef): ?>
                        <th class="px-4 py-4 text-center">
                            <div class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($roleDef['label']); ?></div>
                            <?php if ($roleKey === 'owner'): ?>
                                <div class="text-[10px] uppercase tracking-wide text-gray-400">locked</div>
                            <?php endif; ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($catalog as $groupName => $caps): ?>
                    <tr class="bg-surface-50 dark:bg-dark-300/50">
                        <td class="px-5 py-2 text-xs font-semibold uppercase tracking-wider text-gray-400" colspan="<?php echo count($roles) + 1; ?>">
                            <?php echo htmlspecialchars($groupName); ?>
                        </td>
                    </tr>
                    <?php foreach ($caps as $capKey => $capLabel): ?>
                        <tr class="border-b border-surface-100 dark:border-dark-300 hover:bg-surface-50 dark:hover:bg-dark-300/30">
                            <td class="px-5 py-3 sticky left-0 bg-white dark:bg-dark-400">
                                <div class="text-sm text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($capLabel); ?></div>
                                <div class="text-[11px] font-mono text-gray-400"><?php echo htmlspecialchars($capKey); ?></div>
                            </td>
                            <?php foreach ($roles as $roleKey => $roleDef): ?>
                                <?php
                                    $isOwner = $roleKey === 'owner';
                                    $checked = $permissions->roleCan($roleKey, $capKey);
                                ?>
                                <td class="px-4 py-3 text-center">
                                    <input type="checkbox"
                                        <?php echo $checked ? 'checked' : ''; ?>
                                        <?php echo $isOwner ? 'disabled' : ''; ?>
                                        <?php echo $isOwner ? '' : 'name="caps[' . htmlspecialchars($roleKey) . '][' . htmlspecialchars($capKey) . ']"'; ?>
                                        class="w-4 h-4 rounded border-gray-300 text-accent-600 focus:ring-accent-500 <?php echo $isOwner ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'; ?>">
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="mt-6 flex items-center gap-3">
        <button type="submit" class="btn-primary text-white font-medium px-5 py-2.5 rounded-lg">Save permissions</button>
        <span class="text-sm text-gray-400">Owner permissions are fixed and always granted.</span>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
