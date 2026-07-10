<?php
/**
 * Admin User Management
 *
 * List, create, edit and delete admin users and assign each a role. Roles map
 * to capabilities via the permission grid (admin/roles.php). Gated behind the
 * 'users.manage' capability — by default only the owner role holds it.
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_capability('users.manage');
require_once __DIR__ . '/../core/CSRF.php';

$usersFile = __DIR__ . '/../config/users.json';

// Selectable roles come straight from the permission grid.
$roleOptions = array_keys($permissions->roles());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $username = trim($_POST['username'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $role     = $_POST['role'] ?? 'viewer';

            if ($username === '' || $email === '') {
                throw new Exception('Username and email are required.');
            }
            if (strlen($password) < 8) {
                throw new Exception('Password must be at least 8 characters.');
            }
            if (!in_array($role, $roleOptions, true)) {
                throw new Exception('Unknown role.');
            }
            $auth->createUser($username, $email, $password, $role);
            $successMessage = 'User created.';

        } elseif ($action === 'update') {
            $username = $_POST['username'] ?? '';
            $email    = trim($_POST['email'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $role     = $_POST['role'] ?? '';

            if (!in_array($role, $roleOptions, true)) {
                throw new Exception('Unknown role.');
            }
            if ($password !== '' && strlen($password) < 8) {
                throw new Exception('Password must be at least 8 characters.');
            }
            $auth->updateUser(
                $username,
                $email !== '' ? $email : null,
                $role,
                $password !== '' ? $password : null
            );
            $successMessage = 'User updated.';

        } elseif ($action === 'delete') {
            $username = $_POST['username'] ?? '';
            if ($username === ($currentUser['username'] ?? '')) {
                throw new Exception('You cannot delete your own account.');
            }
            $auth->deleteUser($username);
            $successMessage = 'User deleted.';
        }
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

$users = $auth->listUsers();
$editUser = null;
if (!empty($_GET['edit'])) {
    foreach ($users as $u) {
        if ($u['username'] === $_GET['edit']) {
            $editUser = $u;
            break;
        }
    }
}

$pageTitle = 'Users';
$activePage = 'users';

require __DIR__ . '/includes/header.php';
?>

<div class="flex items-center justify-between mb-6">
    <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Users</h1>
    <a href="/cms/admin/roles.php" class="text-sm text-accent-600 hover:text-accent-700">Manage roles &amp; permissions &rarr;</a>
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

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- User list -->
    <div class="lg:col-span-2 bg-white dark:bg-dark-400 rounded-2xl shadow-soft dark:shadow-dark-soft border border-surface-200 dark:border-dark-200 overflow-hidden">
        <table class="table-modern w-full text-left">
            <thead>
                <tr class="border-b border-surface-200 dark:border-dark-200 text-xs uppercase tracking-wider text-gray-400">
                    <th class="px-5 py-3">User</th>
                    <th class="px-5 py-3">Role</th>
                    <th class="px-5 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr class="border-b border-surface-100 dark:border-dark-300">
                    <td class="px-5 py-3">
                        <div class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($u['username']); ?></div>
                        <div class="text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($u['email']); ?></div>
                    </td>
                    <td class="px-5 py-3">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-accent-50 dark:bg-accent-900/20 text-accent-700 dark:text-accent-400">
                            <?php echo htmlspecialchars($permissions->roleLabel($u['role'])); ?>
                        </span>
                    </td>
                    <td class="px-5 py-3 text-right whitespace-nowrap">
                        <a href="?edit=<?php echo urlencode($u['username']); ?>" class="text-accent-600 dark:text-accent-400 hover:text-accent-700 font-medium mr-3">Edit</a>
                        <?php if ($u['username'] !== ($currentUser['username'] ?? '')): ?>
                        <form method="post" class="inline" onsubmit="return confirm('Delete user &quot;<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>&quot;?');">
                            <?php echo CSRF::inputField(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="username" value="<?php echo htmlspecialchars($u['username']); ?>">
                            <button type="submit" class="text-red-500 dark:text-red-400 hover:text-red-600 font-medium">Delete</button>
                        </form>
                        <?php else: ?>
                        <span class="text-xs text-gray-400">(you)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Add / edit form -->
    <div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft dark:shadow-dark-soft border border-surface-200 dark:border-dark-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
            <?php echo $editUser ? 'Edit user' : 'Add user'; ?>
        </h2>
        <form method="post" class="space-y-4">
            <?php echo CSRF::inputField(); ?>
            <input type="hidden" name="action" value="<?php echo $editUser ? 'update' : 'create'; ?>">

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Username</label>
                <?php if ($editUser): ?>
                    <input type="hidden" name="username" value="<?php echo htmlspecialchars($editUser['username']); ?>">
                    <input type="text" value="<?php echo htmlspecialchars($editUser['username']); ?>" disabled
                           class="input-modern w-full px-3 py-2 rounded-lg bg-surface-100 dark:bg-dark-300 text-gray-500">
                <?php else: ?>
                    <input type="text" name="username" required autocomplete="off"
                           class="input-modern w-full px-3 py-2 rounded-lg dark:bg-dark-300 dark:text-white">
                <?php endif; ?>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email</label>
                <input type="email" name="email" required value="<?php echo htmlspecialchars($editUser['email'] ?? ''); ?>"
                       class="input-modern w-full px-3 py-2 rounded-lg dark:bg-dark-300 dark:text-white">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Password <?php echo $editUser ? '<span class="text-gray-400 font-normal">(leave blank to keep)</span>' : ''; ?>
                </label>
                <input type="password" name="password" <?php echo $editUser ? '' : 'required'; ?> autocomplete="new-password"
                       minlength="8"
                       class="input-modern w-full px-3 py-2 rounded-lg dark:bg-dark-300 dark:text-white">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Role</label>
                <select name="role" class="input-modern w-full px-3 py-2 rounded-lg dark:bg-dark-300 dark:text-white">
                    <?php foreach ($roleOptions as $r): ?>
                        <option value="<?php echo htmlspecialchars($r); ?>" <?php echo (($editUser['role'] ?? 'viewer') === $r) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($permissions->roleLabel($r)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="btn-primary text-white font-medium px-4 py-2 rounded-lg">
                    <?php echo $editUser ? 'Save changes' : 'Create user'; ?>
                </button>
                <?php if ($editUser): ?>
                    <a href="/cms/admin/users.php" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
