<?php
/**
 * File Manager - Browse, upload, edit, and manage files
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/CSRF.php';

$pageTitle = 'File Manager';
$activePage = 'files';

// Forbidden directories that should not be accessible
$forbiddenDirs = ['cms', '.git', 'node_modules', 'vendor'];

// Allowlist of file extensions that may be created via the file manager
$allowedCreateExtensions = ['html', 'htm', 'txt', 'md', 'json', 'css', 'js', 'xml', 'svg', 'csv', 'yml', 'yaml'];

// Get root directory from config
$rootDir = rtrim($config['root_dir'], '/');

// Get current path from query parameter and sanitize it
$requestedPath = $_GET['path'] ?? '';
$requestedPath = trim($requestedPath, '/');

// Security: Prevent directory traversal
$requestedPath = str_replace(['..', '\\'], '', $requestedPath);

// Build absolute path
$currentPath = $requestedPath ? $rootDir . '/' . $requestedPath : $rootDir;

// Security: Verify path is within root
$realCurrentPath = realpath($currentPath);
$realRootPath = realpath($rootDir);

if (!$realCurrentPath || strpos($realCurrentPath, $realRootPath) !== 0) {
    $errorMessage = 'Invalid path';
    $currentPath = $rootDir;
    $requestedPath = '';
    $realCurrentPath = $realRootPath;
}

// Check if first segment is forbidden
$pathSegments = array_filter(explode('/', $requestedPath));
if (!empty($pathSegments) && in_array($pathSegments[0], $forbiddenDirs)) {
    $errorMessage = 'Access denied to this directory';
    $currentPath = $rootDir;
    $requestedPath = '';
    $realCurrentPath = $realRootPath;
}

// Handle file operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'delete':
                $itemPath = $_POST['item_path'] ?? '';
                $itemPath = str_replace(['..', '\\'], '', $itemPath);
                $fullPath = $rootDir . '/' . $itemPath;

                if (realpath($fullPath) && strpos(realpath($fullPath), $realRootPath) === 0) {
                    if (is_file($fullPath)) {
                        if (!unlink($fullPath)) {
                            throw new Exception('Failed to delete file');
                        }
                        $successMessage = 'File deleted successfully';
                    } elseif (is_dir($fullPath)) {
                        if (!rmdir($fullPath)) {
                            throw new Exception('Failed to delete folder (must be empty)');
                        }
                        $successMessage = 'Folder deleted successfully';
                    }
                } else {
                    throw new Exception('Invalid path');
                }
                break;

            case 'create_folder':
                $folderName = $_POST['folder_name'] ?? '';
                $folderName = preg_replace('/[^a-zA-Z0-9_-]/', '', $folderName);

                if (!$folderName) {
                    throw new Exception('Invalid folder name');
                }

                $newFolderPath = $currentPath . '/' . $folderName;

                if (!mkdir($newFolderPath, 0755)) {
                    throw new Exception('Failed to create folder');
                }
                $successMessage = 'Folder created successfully';
                break;

            case 'create_file':
                $fileName = $_POST['file_name'] ?? '';
                $fileName = basename($fileName);
                $fileName = preg_replace('/[^a-zA-Z0-9_.-]/', '', $fileName);

                if (!$fileName || $fileName[0] === '.') {
                    throw new Exception('Invalid file name');
                }

                // Reject double extensions and require a single allowed extension
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                if (!in_array($fileExt, $allowedCreateExtensions, true)) {
                    throw new Exception('File type not allowed');
                }

                // Block any filename that contains a disallowed inner extension
                // (e.g. shell.php.html, evil.phtml.txt)
                $blockedInner = ['php', 'phtml', 'phar', 'pl', 'py', 'sh', 'cgi', 'htaccess', 'htpasswd'];
                $nameLower = strtolower($fileName);
                foreach ($blockedInner as $bad) {
                    if (strpos($nameLower, '.' . $bad . '.') !== false || strpos($nameLower, '.' . $bad) === strlen($nameLower) - strlen($bad) - 1) {
                        // Last check above also catches trailing .php which is already
                        // excluded by the allowlist; the inner-dot check is the key one.
                        if (strpos($nameLower, '.' . $bad . '.') !== false) {
                            throw new Exception('File type not allowed');
                        }
                    }
                }

                // Verify the resolved target stays inside the allowed root and
                // outside the cms/ tree
                $realCurrentPath = realpath($currentPath);
                $realRootCheck = realpath($rootDir);
                if (!$realCurrentPath || !$realRootCheck || strpos($realCurrentPath, $realRootCheck) !== 0) {
                    throw new Exception('Invalid path');
                }
                $realCmsPath = realpath($rootDir . '/cms');
                if ($realCmsPath && strpos($realCurrentPath, $realCmsPath) === 0) {
                    throw new Exception('Access denied');
                }

                $newFilePath = $realCurrentPath . '/' . $fileName;

                if (file_exists($newFilePath)) {
                    throw new Exception('File already exists');
                }

                if (file_put_contents($newFilePath, '') === false) {
                    throw new Exception('Failed to create file');
                }
                $successMessage = 'File created successfully';
                break;
        }
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

// Build breadcrumb navigation
$breadcrumbs = [['name' => 'Root', 'path' => '']];
if ($requestedPath) {
    $pathParts = explode('/', $requestedPath);
    $accumulated = '';
    foreach ($pathParts as $part) {
        $accumulated .= ($accumulated ? '/' : '') . $part;
        $breadcrumbs[] = ['name' => $part, 'path' => $accumulated];
    }
}

// List files and folders in current directory
$items = [];
if (is_dir($realCurrentPath)) {
    $entries = @scandir($realCurrentPath);
    if ($entries !== false) {
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            // Skip forbidden directories at root level
            if (!$requestedPath && in_array($entry, $forbiddenDirs)) {
                continue;
            }

            // Skip hidden files at root level
            if (!$requestedPath && strpos($entry, '.') === 0) {
                continue;
            }

            $fullPath = $realCurrentPath . '/' . $entry;
            $relativePath = $requestedPath ? $requestedPath . '/' . $entry : $entry;

            if (is_dir($fullPath)) {
                $items[] = [
                    'type' => 'folder',
                    'name' => $entry,
                    'path' => $relativePath,
                    'modified' => date('Y-m-d H:i', filemtime($fullPath)),
                    'size' => '-',
                ];
            } else {
                $size = filesize($fullPath);
                $items[] = [
                    'type' => 'file',
                    'name' => $entry,
                    'path' => $relativePath,
                    'modified' => date('Y-m-d H:i', filemtime($fullPath)),
                    'size' => $size < 1024 ? $size . ' B' : ($size < 1048576 ? round($size / 1024, 1) . ' KB' : round($size / 1048576, 1) . ' MB'),
                    'ext' => pathinfo($entry, PATHINFO_EXTENSION),
                ];
            }
        }
    }
}

// Sort: folders first, then files, alphabetically
usort($items, function($a, $b) {
    if ($a['type'] !== $b['type']) {
        return $a['type'] === 'folder' ? -1 : 1;
    }
    return strcasecmp($a['name'], $b['name']);
});

require __DIR__ . '/includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900 mb-2">File Manager</h1>
    <p class="text-gray-600">Browse and manage your website files</p>
</div>

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

<!-- Action Bar -->
<div class="bg-white rounded-lg shadow-md p-4 mb-6">
    <div class="flex flex-wrap gap-3">
        <button onclick="showCreateFileModal()" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 transition flex items-center gap-2 whitespace-nowrap">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            New File
        </button>
        <button onclick="showCreateFolderModal()" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 transition flex items-center gap-2 whitespace-nowrap">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z" />
            </svg>
            New Folder
        </button>
    </div>
</div>

<!-- Breadcrumb Navigation -->
<div class="bg-white rounded-lg shadow-md p-4 mb-6">
    <nav class="flex" aria-label="Breadcrumb">
        <ol class="inline-flex items-center space-x-1 md:space-x-3">
            <?php foreach ($breadcrumbs as $index => $crumb): ?>
                <li class="inline-flex items-center">
                    <?php if ($index > 0): ?>
                        <svg class="w-3 h-3 text-gray-400 mx-1" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path>
                        </svg>
                    <?php endif; ?>
                    <a href="?path=<?php echo urlencode($crumb['path']); ?>" class="inline-flex items-center text-sm font-medium text-gray-700 hover:text-blue-600">
                        <?php if ($index === 0): ?>
                            <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"></path>
                            </svg>
                        <?php endif; ?>
                        <?php echo htmlspecialchars($crumb['name']); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ol>
    </nav>
</div>

<!-- File Browser -->
<?php if (empty($items)): ?>
    <div class="bg-white rounded-lg shadow-md p-12 text-center">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 text-gray-400 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
        </svg>
        <h3 class="text-lg font-medium text-gray-900 mb-2">This folder is empty</h3>
        <p class="text-gray-600 mb-6">Create a new file or folder to get started</p>
    </div>
<?php else: ?>
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <!-- Table Header -->
        <div class="bg-gray-50 px-6 py-3 border-b border-gray-200">
            <div class="grid grid-cols-12 gap-4 text-xs font-medium text-gray-700 uppercase tracking-wider">
                <div class="col-span-6">Name</div>
                <div class="col-span-3">Modified</div>
                <div class="col-span-2">Size</div>
                <div class="col-span-1 text-right">Actions</div>
            </div>
        </div>

        <!-- File/Folder List -->
        <div class="divide-y divide-gray-200">
            <?php foreach ($items as $item): ?>
                <div class="px-6 py-4 hover:bg-gray-50 transition">
                    <div class="grid grid-cols-12 gap-4 items-center">
                        <!-- Name with Icon -->
                        <div class="col-span-6 flex items-center gap-3">
                            <?php if ($item['type'] === 'folder'): ?>
                                <!-- Folder Icon -->
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-blue-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                                </svg>
                                <a href="?path=<?php echo urlencode($item['path']); ?>" class="font-medium text-blue-600 hover:text-blue-800 hover:underline">
                                    <?php echo htmlspecialchars($item['name']); ?>
                                </a>
                            <?php else: ?>
                                <!-- File Icon -->
                                <?php
                                $iconColor = 'text-gray-500';
                                if ($item['ext'] === 'php') $iconColor = 'text-purple-500';
                                elseif ($item['ext'] === 'html') $iconColor = 'text-orange-500';
                                elseif ($item['ext'] === 'css') $iconColor = 'text-blue-500';
                                elseif ($item['ext'] === 'js') $iconColor = 'text-yellow-500';
                                elseif (in_array($item['ext'], ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'])) $iconColor = 'text-green-500';
                                ?>
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 <?php echo $iconColor; ?> flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <span class="font-medium text-gray-900">
                                    <?php echo htmlspecialchars($item['name']); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Modified Date -->
                        <div class="col-span-3 text-sm text-gray-600">
                            <?php echo htmlspecialchars($item['modified']); ?>
                        </div>

                        <!-- Size -->
                        <div class="col-span-2 text-sm text-gray-600">
                            <?php echo htmlspecialchars($item['size']); ?>
                        </div>

                        <!-- Actions -->
                        <div class="col-span-1 flex justify-end gap-2">
                            <?php if ($item['type'] === 'file'): ?>
                                <a href="/cms/admin/file-edit.php?file=<?php echo urlencode($item['path']); ?>" class="text-blue-600 hover:text-blue-800 p-1" title="Edit">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                </a>
                            <?php endif; ?>
                            <button onclick="confirmDelete('<?php echo htmlspecialchars($item['path'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>', '<?php echo $item['type']; ?>')" class="text-red-600 hover:text-red-800 p-1" title="Delete">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Create Folder Modal -->
<div id="createFolderModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg p-6 w-full max-w-md">
        <h2 class="text-xl font-semibold text-gray-900 mb-4">Create New Folder</h2>
        <form method="post">
            <?php echo CSRF::inputField(); ?>
            <input type="hidden" name="action" value="create_folder">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Folder Name:</label>
                <input type="text" name="folder_name" required pattern="[a-zA-Z0-9_-]+" class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                <p class="mt-1 text-sm text-gray-500">Use only letters, numbers, hyphens, and underscores</p>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">Create</button>
                <button type="button" onclick="hideCreateFolderModal()" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Create File Modal -->
<div id="createFileModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg p-6 w-full max-w-md">
        <h2 class="text-xl font-semibold text-gray-900 mb-4">Create New File</h2>
        <form method="post">
            <?php echo CSRF::inputField(); ?>
            <input type="hidden" name="action" value="create_file">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">File Name:</label>
                <input type="text" name="file_name" required pattern="[a-zA-Z0-9_.-]+" class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                <p class="mt-1 text-sm text-gray-500">Use only letters, numbers, hyphens, underscores, and dots (e.g., page.php, style.css)</p>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">Create</button>
                <button type="button" onclick="hideCreateFileModal()" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Form (hidden) -->
<form id="deleteForm" method="post" class="hidden">
    <?php echo CSRF::inputField(); ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="item_path" id="deleteItemPath">
</form>

<script>
function showCreateFolderModal() {
    document.getElementById('createFolderModal').classList.remove('hidden');
}

function hideCreateFolderModal() {
    document.getElementById('createFolderModal').classList.add('hidden');
}

function showCreateFileModal() {
    document.getElementById('createFileModal').classList.remove('hidden');
}

function hideCreateFileModal() {
    document.getElementById('createFileModal').classList.add('hidden');
}

function confirmDelete(path, name, type) {
    if (confirm(`Are you sure you want to delete this ${type}?\n\n${name}`)) {
        document.getElementById('deleteItemPath').value = path;
        document.getElementById('deleteForm').submit();
    }
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
