<?php
/**
 * Web Installer for MCP Flat-file CMS
 *
 * Modes:
 * - Install: Initial setup when config doesn't exist
 * - Reconfigure: Update settings when config exists
 */

// Check PHP version
$minPhpVersion = '8.0.0';
if (version_compare(PHP_VERSION, $minPhpVersion, '<')) {
    die("PHP {$minPhpVersion}+ is required. Current version: " . PHP_VERSION);
}

// Determine mode
$configPath = __DIR__ . '/config/config.php';
$usersPath = __DIR__ . '/config/users.json';
$configExamplePath = __DIR__ . '/config/config.example.php';
$usersExamplePath = __DIR__ . '/config/users.example.json';
$isReconfigure = file_exists($configPath);

// SECURITY: Once the CMS is installed, the installer must never be usable
// by unauthenticated visitors. Anyone hitting install.php (including
// ?reconfigure=1) while a config already exists must be authenticated as
// an admin owner. This prevents the critical C-1 unauthenticated takeover.
if ($isReconfigure) {
    require_once __DIR__ . '/core/Auth.php';
    $auth = new Auth($usersPath);
    $currentUser = $auth->getCurrentUser();

    if (!$auth->isLoggedIn() || ($currentUser['role'] ?? null) !== 'owner') {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Forbidden</title>';
        echo '<style>body{font-family:system-ui,sans-serif;max-width:560px;margin:80px auto;padding:0 24px;color:#111}';
        echo 'h1{font-size:24px;margin-bottom:8px}p{color:#555;line-height:1.55}a{color:#2563eb}</style></head><body>';
        echo '<h1>Forbidden</h1>';
        echo '<p>This CMS is already installed. The installer is disabled for unauthenticated users.</p>';
        echo '<p>If you need to reconfigure, sign in first at <a href="/cms/admin/login.php">/cms/admin/login.php</a> as an owner, then return to this URL.</p>';
        echo '<p>Administrators: for maximum safety, delete <code>cms/install.php</code> from the server once setup is complete.</p>';
        echo '</body></html>';
        exit;
    }
}

// Copy-on-first-run: bootstrap real config files from the committed
// examples so the engine has something to load before the installer
// finishes writing the real values. Skipped silently if the targets
// already exist. Runs after the auth gate so a fresh install (where
// neither file exists yet) is still able to reach the form.
if (!file_exists($configPath) && file_exists($configExamplePath)) {
    @copy($configExamplePath, $configPath);
}
if (!file_exists($usersPath) && file_exists($usersExamplePath)) {
    @copy($usersExamplePath, $usersPath);
}

// Handle form submission
$success = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $siteName = $_POST['site_name'] ?? 'My Site';
        $baseUrl = rtrim(trim((string)($_POST['base_url'] ?? '')), '/');
        $adminEmail = $_POST['admin_email'] ?? '';
        $adminUsername = $_POST['admin_username'] ?? '';
        $adminPassword = $_POST['admin_password'] ?? '';
        $adminPasswordConfirm = $_POST['admin_password_confirm'] ?? '';
        $mcpToken = $_POST['mcp_token'] ?? '';
        $maxBackups = (int)($_POST['max_backups'] ?? 10);

        // AI provider (optional)
        $aiProvider = $_POST['ai_provider'] ?? '';
        if (!in_array($aiProvider, ['', 'anthropic', 'openai', 'gemini'], true)) {
            $aiProvider = '';
        }
        $aiApiKey = trim((string)($_POST['ai_api_key'] ?? ''));
        $aiModel = trim((string)($_POST['ai_model'] ?? ''));
        if ($aiProvider === '') {
            $aiApiKey = '';
            $aiModel = '';
        } else {
            $defaultModels = [
                'anthropic' => 'claude-sonnet-4-6',
                'openai'    => 'gpt-5',
                'gemini'    => 'gemini-2.0-pro',
            ];
            if ($aiModel === '') {
                $aiModel = $defaultModels[$aiProvider];
            }
            if (!preg_match('/^[A-Za-z0-9._\-]+$/', $aiModel)) {
                throw new Exception('Invalid AI model identifier');
            }
        }

        // Validate inputs
        if (!$adminEmail || !$adminUsername || (!$isReconfigure && !$adminPassword)) {
            throw new Exception('All fields are required');
        }

        if (!$isReconfigure && $adminPassword !== $adminPasswordConfirm) {
            throw new Exception('Passwords do not match');
        }

        // Generate MCP token if empty
        if (!$mcpToken) {
            $mcpToken = bin2hex(random_bytes(32));
        }

        // Create required directories
        $dirs = [
            __DIR__ . '/drafts',
            __DIR__ . '/backups',
            __DIR__ . '/logs',
            __DIR__ . '/modules',
            __DIR__ . '/config',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    throw new Exception("Failed to create directory: {$dir}");
                }
            }
        }

        // Write config.php.
        // root_dir / cms_dir must be the unresolved web-root paths so the CMS
        // writes pages into the served web root, not into the engine's real
        // filesystem location. realpath(__DIR__) resolves through symlinks,
        // which is wrong for symlinked installs (engine outside web root,
        // exposed via a pub/cms symlink). DOCUMENT_ROOT + the URL prefix of
        // this script gives the correct served paths in both cases.
        $documentRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
        $cmsUrlPrefix = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/cms/install.php'), '/');
        if ($cmsUrlPrefix === '' || $cmsUrlPrefix === '.') {
            $cmsUrlPrefix = '/cms';
        }
        if ($documentRoot !== '') {
            $rootDir = $documentRoot;
            $cmsDir = $documentRoot . $cmsUrlPrefix;
        } else {
            $rootDir = realpath(__DIR__ . '/..');
            $cmsDir = realpath(__DIR__);
        }
        $draftsDir = $cmsDir . '/drafts';
        $backupsDir = $cmsDir . '/backups';

        // Preserve any existing config keys on reconfigure; only overwrite what the installer manages
        $existingForMerge = $isReconfigure && file_exists($configPath) ? (include $configPath) : [];
        $newConfig = array_merge(is_array($existingForMerge) ? $existingForMerge : [], [
            'mcp_token'            => $mcpToken,
            'root_dir'             => $rootDir,
            'cms_dir'              => $cmsDir,
            'drafts_dir'           => $draftsDir,
            'backups_dir'          => $backupsDir,
            'max_backups_per_page' => $maxBackups,
            'site_name'            => $siteName,
            'language'             => $existingForMerge['language'] ?? 'en',
            'base_url'             => $baseUrl,
            'ai_provider'          => $aiProvider,
            'ai_api_key'           => $aiApiKey,
            'ai_model'             => $aiModel,
        ]);

        $configContent = "<?php\n/**\n * Core configuration for flat MCP CMS.\n */\nreturn " . var_export($newConfig, true) . ";\n";

        if (file_put_contents($configPath, $configContent) === false) {
            throw new Exception('Failed to write config.php');
        }

        // Create or update users.json
        if ($isReconfigure && file_exists($usersPath)) {
            // Update existing user
            $usersData = json_decode(file_get_contents($usersPath), true);
            if (isset($usersData['users'][0])) {
                $usersData['users'][0]['email'] = $adminEmail;
                $usersData['users'][0]['username'] = $adminUsername;
                if ($adminPassword) {
                    $usersData['users'][0]['password_hash'] = password_hash($adminPassword, PASSWORD_DEFAULT);
                }
            }
        } else {
            // Create new users file
            $usersData = [
                'users' => [
                    [
                        'username' => $adminUsername,
                        'email' => $adminEmail,
                        'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
                        'role' => 'owner',
                    ],
                ],
            ];
        }

        if (file_put_contents($usersPath, json_encode($usersData, JSON_PRETTY_PRINT)) === false) {
            throw new Exception('Failed to write users.json');
        }

        // Create installation flag file
        $flagFile = __DIR__ . '/.installed';
        file_put_contents($flagFile, date('Y-m-d H:i:s'));

        $success = true;
        $generatedToken = $mcpToken;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Check if already installed using flag file
$flagFile = __DIR__ . '/.installed';
$isAlreadyInstalled = file_exists($flagFile) && !isset($_GET['reconfigure']);

// If reconfigure parameter is present, allow reinstallation
if (isset($_GET['reconfigure']) && file_exists($flagFile)) {
    // Remove flag to allow reconfiguration
    @unlink($flagFile);
}

// Pre-flight checks — hard checks must pass to install; soft checks warn only.
$rootDirForChecks = realpath(__DIR__ . '/..') ?: __DIR__ . '/..';

$hardChecks = [
    'PHP Version >= ' . $minPhpVersion => version_compare(PHP_VERSION, $minPhpVersion, '>='),
    'json extension available' => function_exists('json_encode') && function_exists('json_decode'),
    'password_hash/verify available' => function_exists('password_hash') && function_exists('password_verify'),
    'mbstring extension available' => extension_loaded('mbstring'),
    'dom extension available' => extension_loaded('dom') && class_exists('DOMDocument'),
    // gd is required for image uploads (resampling + WebP conversion). Without
    // it, every image upload throws a fatal "undefined function" and the AJAX
    // response is empty — confusing UX. Function-level checks because some
    // distros ship a gd-stub that loads but lacks the actual primitives.
    'gd extension with image primitives (apt install php-gd)' =>
        extension_loaded('gd')
        && function_exists('imagecreatefromstring')
        && function_exists('imagecreatetruecolor')
        && function_exists('imagecopyresampled'),
    'cms/ writable' => is_writable(__DIR__),
    'cms/config/ writable' => is_writable(__DIR__ . '/config') || (!file_exists(__DIR__ . '/config') && is_writable(__DIR__)),
];

// Convert post_max_size like "8M" to bytes for comparison
$postMaxSizeBytes = (function ($val) {
    $val = trim((string)$val);
    if ($val === '') return 0;
    $last = strtolower($val[strlen($val) - 1]);
    $n = (int)$val;
    return match ($last) {
        'g' => $n * 1024 * 1024 * 1024,
        'm' => $n * 1024 * 1024,
        'k' => $n * 1024,
        default => $n,
    };
})(ini_get('post_max_size'));

$webRootExecCheck = runWebRootExecCheck($rootDirForChecks);

$softChecks = [
    'Web root writable (for publishing pages)' => [
        'pass' => is_writable($rootDirForChecks),
        'detail' => is_writable($rootDirForChecks) ? $rootDirForChecks : 'Not writable: ' . $rootDirForChecks . ' — publishing blog posts will fail.',
    ],
    'Web root executes PHP (served via PHP, index.php in DirectoryIndex)' => $webRootExecCheck,
    'WebP support in gd (function_exists imagewebp)' => [
        'pass' => function_exists('imagewebp'),
        'detail' => 'gd is loaded but lacks WebP support — uploads will skip the WebP variant. Rebuild gd with libwebp or use a php-gd package compiled with WebP.',
    ],
    'Outbound HTTP (curl or allow_url_fopen)' => [
        'pass' => extension_loaded('curl') || (bool)ini_get('allow_url_fopen'),
        'detail' => 'AI template import and future integrations require outbound HTTP. Enable curl or allow_url_fopen.',
    ],
    'post_max_size >= 20M (for photos from phones)' => [
        'pass' => $postMaxSizeBytes >= 20 * 1024 * 1024,
        'detail' => 'Current: ' . ini_get('post_max_size') . '. Phone photos commonly exceed 8M and the base64 wrapper adds ~33%. Bump post_max_size and upload_max_filesize in php.ini (e.g. 40M).',
    ],
    'HTTPS detected' => [
        'pass' => ($_SERVER['HTTPS'] ?? 'off') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        'detail' => 'Installer and MCP tokens will be transmitted over plain HTTP. Use HTTPS in production.',
    ],
];

$allChecksPassed = !in_array(false, $hardChecks, true);

function runWebRootExecCheck(string $rootDir): array
{
    if (php_sapi_name() === 'cli-server') {
        return ['pass' => true, 'detail' => 'Skipped: PHP built-in server is single-threaded and cannot self-probe. Check runs normally on Apache/nginx.'];
    }
    if (!is_writable($rootDir)) {
        return ['pass' => false, 'detail' => 'Web root not writable — skipped PHP execution probe.'];
    }

    $dirSuffix = bin2hex(random_bytes(6));
    $testDir = $rootDir . '/.cms-preflight-' . $dirSuffix;
    $token = 'cms_preflight_' . bin2hex(random_bytes(8));

    if (!@mkdir($testDir, 0755)) {
        return ['pass' => false, 'detail' => 'Could not create probe directory in web root.'];
    }

    try {
        $testFile = $testDir . '/index.php';
        if (file_put_contents($testFile, "<?php echo '{$token}'; ?>") === false) {
            return ['pass' => false, 'detail' => 'Could not write probe file.'];
        }

        $scheme = (($_SERVER['HTTPS'] ?? 'off') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/cms/install.php';
        $basePath = preg_replace('#/cms/install\.php$#', '', $scriptName);
        $url = $scheme . '://' . $host . $basePath . '/.cms-preflight-' . $dirSuffix . '/';

        $response = fetchProbeUrl($url, 5);

        if ($response === null) {
            return ['pass' => false, 'detail' => 'Could not fetch probe URL (' . $url . '). Server may be unreachable from itself; verify manually.'];
        }
        if (trim($response) === $token) {
            return ['pass' => true, 'detail' => ''];
        }
        if (strpos($response, '<?php') !== false || strpos($response, $token) === false && strlen(trim($response)) > 0 && $response[0] === '<' && strpos($response, 'echo') !== false) {
            return ['pass' => false, 'detail' => 'Server returned PHP source instead of executing it. Enable PHP for this vhost (PHP-FPM or mod_php).'];
        }
        if (stripos($response, '<title>Index of') !== false || stripos($response, 'Directory listing') !== false) {
            return ['pass' => false, 'detail' => 'DirectoryIndex does not include index.php — add it to your Apache/nginx config.'];
        }
        return ['pass' => false, 'detail' => 'Unexpected probe response — check server config. Snippet: ' . substr(trim($response), 0, 120)];
    } finally {
        @unlink($testDir . '/index.php');
        @rmdir($testDir);
    }
}

function fetchProbeUrl(string $url, int $timeout): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response === false ? null : (string)$response;
    }
    if (!ini_get('allow_url_fopen')) {
        return null;
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => $timeout, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $response = @file_get_contents($url, false, $ctx);
    return $response === false ? null : $response;
}

// Load existing config if reconfiguring
$existingConfig = [];
$existingUser = [];
if ($isReconfigure && file_exists($configPath)) {
    $existingConfig = include $configPath;
    if (file_exists($usersPath)) {
        $usersData = json_decode(file_get_contents($usersPath), true);
        $existingUser = $usersData['users'][0] ?? [];
    }
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $isReconfigure ? 'Reconfigure' : 'Install'; ?> - MCP Flat CMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="bg-gray-50 min-h-screen py-12 px-4">
    <div class="max-w-2xl mx-auto">
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold text-gray-900 mb-2">
                <?php echo $isReconfigure ? 'Reconfigure' : 'Install'; ?> MCP Flat CMS
            </h1>
            <p class="text-gray-600">PHP-based flat-file CMS with MCP integration</p>
        </div>

        <?php if ($isAlreadyInstalled && !$success): ?>
            <div class="bg-white rounded-lg shadow-md p-8 mb-6">
                <div class="text-center mb-6">
                    <div class="inline-flex items-center justify-center w-16 h-16 bg-blue-100 rounded-full mb-4">
                        <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                        </svg>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-900">Already Installed</h2>
                    <p class="text-gray-600 mt-2">The CMS is already installed and configured.</p>
                </div>

                <div class="space-y-4">
                    <p class="text-center text-gray-700">
                        <a href="/cms/admin/" class="inline-block px-6 py-3 bg-blue-600 text-white font-semibold rounded-md hover:bg-blue-700 transition">
                            Go to Admin Panel
                        </a>
                    </p>
                    <p class="text-center text-sm text-gray-600">
                        Need to change settings?
                        <a href="?reconfigure=1" class="text-blue-600 hover:text-blue-800">Reconfigure</a>
                    </p>
                </div>
            </div>
        <?php elseif ($success): ?>
            <div class="bg-white rounded-lg shadow-md p-8 mb-6">
                <div class="text-center mb-6">
                    <div class="inline-flex items-center justify-center w-16 h-16 bg-green-100 rounded-full mb-4">
                        <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-900">Installation Successful!</h2>
                    <p class="text-gray-600 mt-2">Your CMS has been <?php echo $isReconfigure ? 'reconfigured' : 'installed'; ?> successfully.</p>
                </div>

                <div class="space-y-6">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Admin Access</h3>
                        <p class="text-gray-700">
                            <strong>Admin URL:</strong>
                            <a href="/cms/admin/" class="text-blue-600 hover:text-blue-800">/cms/admin/</a>
                        </p>
                    </div>

                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">MCP Configuration</h3>
                        <p class="text-gray-700 mb-2">
                            <strong>MCP Endpoint:</strong>
                        </p>
                        <code class="block bg-gray-100 p-3 rounded text-sm break-all">
                            <?php echo ($_SERVER['HTTPS'] ?? 'off') === 'on' ? 'https' : 'http'; ?>://<?php echo $_SERVER['HTTP_HOST']; ?>/cms/mcp/index.php
                        </code>
                        <p class="text-gray-700 mt-4 mb-2">
                            <strong>MCP Token (save this!):</strong>
                        </p>
                        <div class="bg-yellow-50 border-l-4 border-yellow-500 p-4">
                            <code class="text-sm break-all"><?php echo htmlspecialchars($generatedToken); ?></code>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($error): ?>
            <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
                <p class="text-red-700"><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></p>
            </div>
        <?php endif; ?>

        <?php if (!$success): ?>
            <div class="bg-white rounded-lg shadow-md p-8 mb-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Pre-flight Checks</h2>

                <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2">Required</h3>
                <div class="space-y-2 mb-6">
                    <?php foreach ($hardChecks as $name => $result): ?>
                        <div class="flex items-center">
                            <?php if ($result): ?>
                                <svg class="w-5 h-5 text-green-600 mr-2 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                            <?php else: ?>
                                <svg class="w-5 h-5 text-red-600 mr-2 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            <?php endif; ?>
                            <span class="text-gray-700"><?php echo htmlspecialchars($name); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2">Recommended</h3>
                <div class="space-y-2">
                    <?php foreach ($softChecks as $name => $info): ?>
                        <div class="flex items-start">
                            <?php if ($info['pass']): ?>
                                <svg class="w-5 h-5 text-green-600 mr-2 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                <span class="text-gray-700"><?php echo htmlspecialchars($name); ?></span>
                            <?php else: ?>
                                <svg class="w-5 h-5 text-yellow-500 mr-2 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"></path>
                                </svg>
                                <div>
                                    <div class="text-gray-700"><?php echo htmlspecialchars($name); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($info['detail']); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($allChecksPassed): ?>
                <div class="bg-white rounded-lg shadow-md p-8">
                    <form method="post" class="space-y-6">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Site Settings</h3>
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Site Name:</label>
                                    <input type="text" name="site_name" value="<?php echo htmlspecialchars($existingConfig['site_name'] ?? 'My Site'); ?>" required class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <?php
                                $defaultBaseUrl = rtrim(
                                    (($_SERVER['HTTPS'] ?? 'off') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https://' : 'http://')
                                    . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
                                    '/'
                                );
                                $baseUrlValue = $existingConfig['base_url'] ?? $defaultBaseUrl;
                                ?>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Base URL:</label>
                                    <input type="url" name="base_url" value="<?php echo htmlspecialchars($baseUrlValue); ?>" required class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="https://example.com">
                                    <p class="mt-1 text-sm text-gray-500">Public URL of this site (no trailing slash). Used by sitemap.xml and blog canonical URLs.</p>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Admin User</h3>
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Email:</label>
                                    <input type="email" name="admin_email" value="<?php echo htmlspecialchars($existingUser['email'] ?? ''); ?>" required class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Username:</label>
                                    <input type="text" name="admin_username" value="<?php echo htmlspecialchars($existingUser['username'] ?? ''); ?>" required class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Password <?php echo $isReconfigure ? '(leave empty to keep current)' : ''; ?>:
                                    </label>
                                    <input type="password" name="admin_password" <?php echo !$isReconfigure ? 'required' : ''; ?> class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Confirm Password:</label>
                                    <input type="password" name="admin_password_confirm" <?php echo !$isReconfigure ? 'required' : ''; ?> class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">MCP Settings</h3>
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">MCP Token:</label>
                                    <input type="text" name="mcp_token" value="<?php echo htmlspecialchars($existingConfig['mcp_token'] ?? ''); ?>" placeholder="Leave empty to auto-generate" class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono text-sm">
                                    <p class="mt-1 text-sm text-gray-500">This token is used by AI clients (ChatGPT, Claude) to access the CMS via MCP.</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Max Backups per Page:</label>
                                    <input type="number" name="max_backups" value="<?php echo htmlspecialchars($existingConfig['max_backups_per_page'] ?? 10); ?>" min="1" max="100" required class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>
                        </div>

                        <div x-data="{
                            provider: '<?php echo htmlspecialchars($existingConfig['ai_provider'] ?? ''); ?>',
                            defaultModels: { anthropic: 'claude-sonnet-4-6', openai: 'gpt-5', gemini: 'gemini-2.0-pro' },
                            model: '<?php echo htmlspecialchars($existingConfig['ai_model'] ?? ''); ?>',
                            setProvider(p) { this.provider = p; if (p && !this.model) this.model = this.defaultModels[p]; if (!p) this.model = ''; },
                        }">
                            <h3 class="text-lg font-semibold text-gray-900 mb-2">AI Assistant <span class="text-sm font-normal text-gray-500">(optional)</span></h3>
                            <p class="text-sm text-gray-600 mb-4">Used for template import and future content-assist features. You can skip and add a key later from Settings.</p>

                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-4">
                                <label class="flex items-center justify-center px-3 py-2 border rounded-md cursor-pointer text-sm" :class="provider === '' ? 'bg-blue-50 border-blue-500 text-blue-700' : 'border-gray-300'">
                                    <input type="radio" name="ai_provider" value="" class="sr-only" :checked="provider === ''" @change="setProvider('')">
                                    Skip
                                </label>
                                <label class="flex items-center justify-center px-3 py-2 border rounded-md cursor-pointer text-sm" :class="provider === 'anthropic' ? 'bg-blue-50 border-blue-500 text-blue-700' : 'border-gray-300'">
                                    <input type="radio" name="ai_provider" value="anthropic" class="sr-only" :checked="provider === 'anthropic'" @change="setProvider('anthropic')">
                                    Claude
                                </label>
                                <label class="flex items-center justify-center px-3 py-2 border rounded-md cursor-pointer text-sm" :class="provider === 'openai' ? 'bg-blue-50 border-blue-500 text-blue-700' : 'border-gray-300'">
                                    <input type="radio" name="ai_provider" value="openai" class="sr-only" :checked="provider === 'openai'" @change="setProvider('openai')">
                                    OpenAI
                                </label>
                                <label class="flex items-center justify-center px-3 py-2 border rounded-md cursor-pointer text-sm" :class="provider === 'gemini' ? 'bg-blue-50 border-blue-500 text-blue-700' : 'border-gray-300'">
                                    <input type="radio" name="ai_provider" value="gemini" class="sr-only" :checked="provider === 'gemini'" @change="setProvider('gemini')">
                                    Gemini
                                </label>
                            </div>

                            <div x-show="provider !== ''" x-cloak class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">API Key:</label>
                                    <input type="password" name="ai_api_key" value="<?php echo htmlspecialchars($existingConfig['ai_api_key'] ?? ''); ?>" autocomplete="off" class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono text-sm" placeholder="sk-..., AIza..., etc.">
                                    <p class="mt-1 text-xs text-gray-500">Stored in <code>cms/config/config.php</code> (readable only by the web server user).</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Model:</label>
                                    <input type="text" name="ai_model" x-model="model" class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono text-sm">
                                    <p class="mt-1 text-xs text-gray-500">Default shown on selection; override if you prefer a different model.</p>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="w-full py-3 bg-blue-600 text-white font-semibold rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition">
                            <?php echo $isReconfigure ? 'Save Changes' : 'Install'; ?>
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="bg-red-50 border-l-4 border-red-500 p-4">
                    <p class="text-red-700">
                        <strong>Cannot proceed:</strong> Some pre-flight checks failed. Please fix the issues above and refresh this page.
                    </p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
