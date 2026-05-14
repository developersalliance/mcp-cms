<?php
/**
 * Admin Settings / Configuration
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/CSRF.php';

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    try {
        // Load current config
        $configPath = __DIR__ . '/../config/config.php';
        $currentConfig = require $configPath;

        // Validate and cast each user-supplied field
        $siteName = (string)($_POST['site_name'] ?? 'My Site');
        $maxBackups = max(1, min(100, (int)($_POST['max_backups'] ?? 10)));
        $reservedFoldersRaw = (string)($_POST['reserved_folders'] ?? 'cms,blog,assets,uploads,index');
        $reservedFolders = array_values(array_filter(array_map('trim', explode(',', $reservedFoldersRaw)), function($v) {
            return $v !== '' && preg_match('/^[a-zA-Z0-9_-]+$/', $v);
        }));
        if (empty($reservedFolders)) {
            $reservedFolders = ['cms', 'blog', 'assets', 'uploads', 'index'];
        }

        // Upload settings
        $uploadsDir = (string)($_POST['uploads_dir'] ?? 'assets/content/');
        // Restrict uploads_dir to a safe relative path
        if (!preg_match('#^[a-zA-Z0-9_./-]+/$#', $uploadsDir) || strpos($uploadsDir, '..') !== false) {
            throw new Exception('Invalid uploads directory');
        }
        $imageThumbnailWidth = max(50, min(1000, (int)($_POST['image_thumbnail_width'] ?? 300)));
        $imageThumbnailHeight = max(50, min(1000, (int)($_POST['image_thumbnail_height'] ?? 300)));
        $imageFullWidth = max(100, min(5000, (int)($_POST['image_full_width'] ?? 1920)));
        $imageFullHeight = max(100, min(5000, (int)($_POST['image_full_height'] ?? 1080)));

        // MCP Security settings
        $mcpRateLimitEnabled = isset($_POST['mcp_rate_limit_enabled']);
        $mcpRateLimitRequests = max(1, min(1000, (int)($_POST['mcp_rate_limit_requests'] ?? 60)));
        $mcpRateLimitWindow = max(1, min(3600, (int)($_POST['mcp_rate_limit_window'] ?? 60)));
        $mcpIpWhitelistRaw = trim((string)($_POST['mcp_ip_whitelist'] ?? ''));
        // Normalise IP whitelist to a safe comma-separated string of IP-shaped tokens
        $mcpIpWhitelist = '';
        if ($mcpIpWhitelistRaw !== '') {
            $ips = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $mcpIpWhitelistRaw)), function($v) {
                return $v !== '' && preg_match('/^[0-9a-fA-F:.]+$/', $v);
            }));
            $mcpIpWhitelist = implode(', ', $ips);
        }

        // Mutate the loaded config array in-place so unrelated keys are preserved
        $newConfig = $currentConfig;
        $newConfig['site_name'] = $siteName;
        $newConfig['max_backups_per_page'] = $maxBackups;
        $newConfig['reserved_folders'] = $reservedFolders;
        $newConfig['uploads_dir'] = $uploadsDir;
        $newConfig['image_thumbnail_width'] = $imageThumbnailWidth;
        $newConfig['image_thumbnail_height'] = $imageThumbnailHeight;
        $newConfig['image_full_width'] = $imageFullWidth;
        $newConfig['image_full_height'] = $imageFullHeight;
        $newConfig['mcp_rate_limit_enabled'] = $mcpRateLimitEnabled;
        $newConfig['mcp_rate_limit_requests'] = $mcpRateLimitRequests;
        $newConfig['mcp_rate_limit_window'] = $mcpRateLimitWindow;
        $newConfig['mcp_ip_whitelist'] = $mcpIpWhitelist;

        // Emit config via var_export which safely escapes all string values
        $configContent = "<?php\n/**\n * Core configuration for flat MCP CMS.\n */\nreturn " . var_export($newConfig, true) . ";\n";

        if (file_put_contents($configPath, $configContent) === false) {
            throw new Exception('Failed to update config file');
        }

        // Redirect to show fresh values
        header('Location: /cms/admin/settings.php?saved=1');
        exit;
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

// Show success message after redirect
if (isset($_GET['saved'])) {
    $successMessage = 'Settings updated successfully.';
}

// Get current settings
$reservedFoldersString = is_array($config['reserved_folders'] ?? null)
    ? implode(',', $config['reserved_folders'])
    : 'cms,blog,assets,uploads,index';

$pageTitle = 'Settings';
$activePage = 'settings';

require __DIR__ . '/includes/header.php';
?>

<h1 class="text-3xl font-bold text-gray-900 mb-6">Settings</h1>

<?php if (isset($successMessage)): ?>
    <div class="bg-green-50 border-l-4 border-green-500 p-4 mb-6">
        <p class="text-green-700"><?php echo htmlspecialchars($successMessage); ?></p>
    </div>
<?php endif; ?>

<?php if (isset($errorMessage)): ?>
    <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
        <p class="text-red-700"><?php echo htmlspecialchars($errorMessage); ?></p>
    </div>
<?php endif; ?>

<div class="bg-white rounded-lg shadow-md p-6">
    <h2 class="text-xl font-semibold text-gray-900 mb-6">General Configuration</h2>

    <form method="post" class="space-y-6">
        <?php echo CSRF::inputField(); ?>

        <div>
            <h3 class="text-lg font-medium text-gray-900 mb-4">Site Information</h3>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Site Name:
                </label>
                <input
                    type="text"
                    name="site_name"
                    value="<?php echo htmlspecialchars($config['site_name'] ?? 'My Site'); ?>"
                    required
                    class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                <p class="mt-1 text-sm text-gray-500">Display name for your website</p>
            </div>
        </div>

        <hr class="border-gray-200">

        <div>
            <h3 class="text-lg font-medium text-gray-900 mb-4">Upload Settings</h3>

            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Uploads Directory:
                    </label>
                    <input
                        type="text"
                        name="uploads_dir"
                        value="<?php echo htmlspecialchars($config['uploads_dir'] ?? 'assets/content/'); ?>"
                        required
                        class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono text-sm"
                    >
                    <p class="mt-1 text-sm text-gray-500">Directory path for uploaded files and images (relative to site root, must end with /)</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Thumbnail Width (px):
                        </label>
                        <input
                            type="number"
                            name="image_thumbnail_width"
                            value="<?php echo htmlspecialchars($config['image_thumbnail_width'] ?? 300); ?>"
                            min="50"
                            max="1000"
                            required
                            class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                        <p class="mt-1 text-sm text-gray-500">Maximum width for thumbnail images</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Thumbnail Height (px):
                        </label>
                        <input
                            type="number"
                            name="image_thumbnail_height"
                            value="<?php echo htmlspecialchars($config['image_thumbnail_height'] ?? 300); ?>"
                            min="50"
                            max="1000"
                            required
                            class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                        <p class="mt-1 text-sm text-gray-500">Maximum height for thumbnail images</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Full Image Width (px):
                        </label>
                        <input
                            type="number"
                            name="image_full_width"
                            value="<?php echo htmlspecialchars($config['image_full_width'] ?? 1920); ?>"
                            min="100"
                            max="5000"
                            required
                            class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                        <p class="mt-1 text-sm text-gray-500">Maximum width for full-size images</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Full Image Height (px):
                        </label>
                        <input
                            type="number"
                            name="image_full_height"
                            value="<?php echo htmlspecialchars($config['image_full_height'] ?? 1080); ?>"
                            min="100"
                            max="5000"
                            required
                            class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                        <p class="mt-1 text-sm text-gray-500">Maximum height for full-size images</p>
                    </div>
                </div>

                <div class="mt-3 bg-blue-50 border-l-4 border-blue-500 p-4">
                    <p class="text-sm text-blue-700">
                        <strong>Note:</strong> Images will be automatically resized to fit within these dimensions while maintaining aspect ratio. Both WebP and PNG formats will be generated.
                    </p>
                </div>
            </div>
        </div>

        <hr class="border-gray-200">

        <div>
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium text-gray-900">Backup Settings</h3>
                <a href="/cms/admin/backups.php" class="inline-flex items-center gap-2 px-4 py-2 text-sm bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                    </svg>
                    View All Backups
                </a>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Maximum Backups per Page:
                </label>
                <input
                    type="number"
                    name="max_backups"
                    value="<?php echo htmlspecialchars($config['max_backups_per_page'] ?? 10); ?>"
                    min="1"
                    max="100"
                    required
                    class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                <p class="mt-1 text-sm text-gray-500">Number of backup versions to keep for each page (older backups are automatically deleted)</p>
            </div>
        </div>

        <hr class="border-gray-200">

        <div>
            <h3 class="text-lg font-medium text-gray-900 mb-4">System Protection</h3>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Reserved Folder Names:
                </label>
                <input
                    type="text"
                    name="reserved_folders"
                    value="<?php echo htmlspecialchars($reservedFoldersString); ?>"
                    required
                    class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono text-sm"
                >
                <p class="mt-1 text-sm text-gray-500">Comma-separated list of folder names that cannot be used as page IDs (e.g., cms,blog,assets,uploads)</p>

                <div class="mt-3 bg-yellow-50 border-l-4 border-yellow-500 p-4">
                    <p class="text-sm text-yellow-700">
                        <strong>Important:</strong> These folders are protected from being created as pages to prevent conflicts with system directories and media folders.
                    </p>
                </div>
            </div>
        </div>

        <hr class="border-gray-200">

        <div>
            <h3 class="text-lg font-medium text-gray-900 mb-4">MCP API Security</h3>

            <div class="space-y-4">
                <div class="flex items-start">
                    <div class="flex items-center h-5">
                        <input
                            type="checkbox"
                            id="mcp_rate_limit_enabled"
                            name="mcp_rate_limit_enabled"
                            <?php echo ($config['mcp_rate_limit_enabled'] ?? true) ? 'checked' : ''; ?>
                            class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                        >
                    </div>
                    <div class="ml-3">
                        <label for="mcp_rate_limit_enabled" class="font-medium text-gray-700">
                            Enable Rate Limiting
                        </label>
                        <p class="text-sm text-gray-500">Limit the number of API requests to prevent abuse</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Max Requests:
                        </label>
                        <input
                            type="number"
                            name="mcp_rate_limit_requests"
                            value="<?php echo htmlspecialchars($config['mcp_rate_limit_requests'] ?? 60); ?>"
                            min="1"
                            max="1000"
                            required
                            class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                        <p class="mt-1 text-sm text-gray-500">Maximum requests allowed per time window</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Time Window (seconds):
                        </label>
                        <input
                            type="number"
                            name="mcp_rate_limit_window"
                            value="<?php echo htmlspecialchars($config['mcp_rate_limit_window'] ?? 60); ?>"
                            min="1"
                            max="3600"
                            required
                            class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                        <p class="mt-1 text-sm text-gray-500">Time window for rate limiting (e.g., 60 = per minute)</p>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        IP Whitelist:
                    </label>
                    <textarea
                        name="mcp_ip_whitelist"
                        rows="3"
                        placeholder="127.0.0.1, 192.168.1.100"
                        class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono text-sm"
                    ><?php echo htmlspecialchars($config['mcp_ip_whitelist'] ?? ''); ?></textarea>
                    <p class="mt-1 text-sm text-gray-500">Comma-separated list of allowed IP addresses (leave empty to allow all IPs)</p>

                    <div class="mt-3 bg-blue-50 border-l-4 border-blue-500 p-4">
                        <p class="text-sm text-blue-700">
                            <strong>Note:</strong> If specified, only requests from these IP addresses will be accepted by the MCP API. Leave empty to accept requests from any IP.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="pt-4">
            <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition">
                Save Settings
            </button>
        </div>
    </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
