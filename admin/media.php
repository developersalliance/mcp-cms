<?php
/**
 * Media Manager
 * Upload and manage images and files
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/UploadManager.php';
require_once __DIR__ . '/../core/CSRF.php';

$uploadManager = new UploadManager(
    $config['root_dir'],
    $config['uploads_dir'] ?? 'assets/content/',
    $config['image_thumbnail_width'] ?? 300,
    $config['image_thumbnail_height'] ?? 300,
    $config['image_full_width'] ?? 1920,
    $config['image_full_height'] ?? 1080
);

$uploadsDir = $config['root_dir'] . '/' . trim($config['uploads_dir'] ?? 'assets/content/', '/');
$uploadsWebPath = '/' . trim($config['uploads_dir'] ?? 'assets/content/', '/');

// Ensure uploads directory exists
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    CSRF::verifyOrDie();

    if ($_POST['action'] === 'upload') {
        header('Content-Type: application/json');
        try {
            $subdir = $_POST['subdir'] ?? '';

            // Two accepted shapes: (a) multipart upload via $_FILES['file']
            // (preferred — no base64 inflation, doesn't trip post_max_size),
            // (b) legacy base64 in $_POST['file_data'] for any external caller.
            if (!empty($_FILES['file']) && isset($_FILES['file']['tmp_name'])) {
                $upload = $_FILES['file'];
                if ($upload['error'] !== UPLOAD_ERR_OK) {
                    $codes = [
                        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize (' . ini_get('upload_max_filesize') . ')',
                        UPLOAD_ERR_FORM_SIZE => 'File exceeds form MAX_FILE_SIZE',
                        UPLOAD_ERR_PARTIAL => 'Upload was interrupted',
                        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                        UPLOAD_ERR_NO_TMP_DIR => 'Server missing temp dir',
                        UPLOAD_ERR_CANT_WRITE => 'Server failed to write file',
                        UPLOAD_ERR_EXTENSION => 'PHP extension blocked the upload',
                    ];
                    throw new Exception($codes[$upload['error']] ?? ('Upload error code ' . $upload['error']));
                }
                $fileName = (string)$upload['name'];
                $fileType = (string)($upload['type'] ?? '');
                if (!$fileName) throw new Exception('Missing filename');
                $isImage = strpos($fileType, 'image/') === 0 || preg_match('/\.(jpe?g|png|gif|webp|svg)$/i', $fileName);
                $bytes = @file_get_contents($upload['tmp_name']);
                if ($bytes === false) throw new Exception('Could not read uploaded file');
                // UploadManager expects RAW base64 (strict mode). No data: prefix.
                $b64 = base64_encode($bytes);
                $result = $isImage
                    ? $uploadManager->uploadImage($b64, $fileName, $subdir)
                    : $uploadManager->uploadFile($b64, $fileName, $subdir);
            } else {
                $fileData = $_POST['file_data'] ?? '';
                $fileName = $_POST['file_name'] ?? '';
                $fileType = $_POST['file_type'] ?? '';
                if (!$fileData || !$fileName) {
                    throw new Exception('Missing file data or filename (POST body may have exceeded post_max_size=' . ini_get('post_max_size') . ')');
                }
                // Strip a "data:image/...;base64," prefix if the caller sent
                // a full data URL — UploadManager uses strict base64_decode().
                if (str_starts_with($fileData, 'data:')) {
                    $comma = strpos($fileData, ',');
                    if ($comma !== false) {
                        $fileData = substr($fileData, $comma + 1);
                    }
                }
                $isImage = strpos($fileType, 'image/') === 0;
                $result = $isImage
                    ? $uploadManager->uploadImage($fileData, $fileName, $subdir)
                    : $uploadManager->uploadFile($fileData, $fileName, $subdir);
            }

            if (!($result['success'] ?? false)) {
                http_response_code(400);
            }
            echo json_encode($result);
            exit;

        } catch (Exception $e) {
            error_log("Upload exception: " . $e->getMessage());
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    } elseif ($_POST['action'] === 'delete') {
        header('Content-Type: application/json');
        try {
            $filePath = $_POST['file_path'] ?? '';
            if (!$filePath) {
                throw new Exception('Missing file path');
            }

            // Convert web path to filesystem path
            $fullPath = $config['root_dir'] . $filePath;

            // Security: ensure file is within uploads directory
            $realPath = realpath($fullPath);
            $realUploadsDir = realpath($uploadsDir);

            if (!$realPath) {
                throw new Exception('File does not exist: ' . $fullPath);
            }

            if (strpos($realPath, $realUploadsDir) !== 0) {
                throw new Exception('Security violation: File must be within uploads directory. Real: ' . $realPath . ', Uploads: ' . $realUploadsDir);
            }

            if (file_exists($fullPath)) {
                // Delete ALL files sharing the same base name (handles every
                // generated format + thumb at once): both `{base}.*` and
                // `{base}-thumb.*`. "Delete All" should not require a second
                // click just because one variant (e.g., the original jpg)
                // wasn't in the alt-of-webp/png pair.
                $pathInfo = pathinfo($fullPath);
                $baseName = $pathInfo['filename'];
                // If the user clicked a thumbnail variant, normalize back to
                // the canonical basename so we still nuke the full sizes too.
                if (substr($baseName, -6) === '-thumb') {
                    $baseName = substr($baseName, 0, -6);
                }
                $dir = $pathInfo['dirname'];
                $candidates = array_merge(
                    glob($dir . '/' . $baseName . '.*') ?: [],
                    glob($dir . '/' . $baseName . '-thumb.*') ?: []
                );
                $deleted = 0;
                foreach ($candidates as $cand) {
                    $candReal = realpath($cand);
                    if ($candReal && strpos($candReal, $realUploadsDir) === 0 && is_file($candReal)) {
                        if (@unlink($candReal)) $deleted++;
                    }
                }
                echo json_encode(['success' => true, 'message' => 'Deleted ' . $deleted . ' file(s)']);
            } else {
                throw new Exception('File not found');
            }
            exit;

        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }
}

// JSON list endpoint for in-page pickers (block-editor media modal, etc.)
// Returns { success, images:[...], files:[...] } from scanMediaDirectory.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['json']) || ($_GET['action'] ?? '') === 'list')) {
    header('Content-Type: application/json');
    $result = scanMediaDirectory($uploadsDir, $config['root_dir'], $uploadsWebPath);
    // Normalise images for picker consumers: pick best display URL + a thumbnail
    $images = [];
    foreach ($result['images'] as $key => $img) {
        $formats = $img['formats'] ?? [];
        // Prefer PNG / JPG for the inserted img.src so it shows up reliably
        // in every editor preview. WebP is kept as an alternative the user
        // can opt into later (via <picture> markup) but isn't the default.
        $full = $formats['full_png']['url']
            ?? $formats['full_jpg']['url']
            ?? $formats['full_jpeg']['url']
            ?? $formats['full_gif']['url']
            ?? $formats['full_svg']['url']
            ?? $formats['full_webp']['url']
            ?? null;
        // Thumb can stay WebP for the grid (smaller payload, just a preview)
        $thumb = $formats['thumb_webp']['url']
            ?? $formats['thumb_png']['url']
            ?? $formats['thumb_jpg']['url']
            ?? $formats['thumb_jpeg']['url']
            ?? $full;
        if (!$full) continue;
        $images[] = [
            'name' => $img['name'] ?? '',
            'subdir' => trim($img['subdir'] ?? '', '/'),
            'url' => $full,
            'thumb' => $thumb,
            'modified' => $img['modified'] ?? null,
            'formats' => $formats,
        ];
    }
    if (isset($_GET['tinymce'])) {
        // TinyMCE image_list expects [{title, value}] — value is the URL it
        // inserts as src. Skip files; only return images.
        $out = [];
        foreach ($images as $img) {
            $label = trim(($img['subdir'] !== '' ? $img['subdir'] . '/' : '') . ($img['name'] ?? basename($img['url'])));
            $out[] = ['title' => $label, 'value' => $img['url']];
        }
        echo json_encode($out);
        exit;
    }
    echo json_encode(['success' => true, 'images' => $images, 'files' => $result['files'] ?? []]);
    exit;
}

// Get list of all uploaded files and group images by base name
function scanMediaDirectory($dir, $baseDir, $webPath) {
    $files = [];
    $imageGroups = [];

    if (!is_dir($dir)) {
        return ['files' => $files, 'images' => []];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            // Get path relative to uploads directory (not root directory)
            $relativePath = substr($file->getPathname(), strlen($dir) + 1);
            $webUrl = $webPath . '/' . str_replace('\\', '/', $relativePath);

            $ext = strtolower($file->getExtension());
            $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);

            // For images, check if it's a thumbnail
            $isThumbnail = strpos($file->getFilename(), '-thumb.') !== false;

            if ($isImage) {
                // Get base name without extension and -thumb suffix
                $baseName = pathinfo($file->getFilename(), PATHINFO_FILENAME);
                if ($isThumbnail) {
                    $baseName = str_replace('-thumb', '', $baseName);
                }

                // Get subdirectory path
                $subdir = str_replace($baseDir, '', $file->getPath());
                $groupKey = $subdir . '/' . $baseName;

                if (!isset($imageGroups[$groupKey])) {
                    $imageGroups[$groupKey] = [
                        'name' => $baseName,
                        'path' => $file->getPath(),
                        'subdir' => $subdir,
                        'modified' => $file->getMTime(),
                        'formats' => []
                    ];
                }

                // Add this file to the group
                $formatKey = $isThumbnail ? 'thumb_' . $ext : 'full_' . $ext;
                $imageGroups[$groupKey]['formats'][$formatKey] = [
                    'url' => $webUrl,
                    'size' => $file->getSize()
                ];

                // Update modified time to most recent
                $imageGroups[$groupKey]['modified'] = max($imageGroups[$groupKey]['modified'], $file->getMTime());
            } else {
                // Regular file
                $files[] = [
                    'path' => $webUrl,
                    'name' => $file->getFilename(),
                    'size' => $file->getSize(),
                    'modified' => $file->getMTime(),
                    'ext' => $ext
                ];
            }
        }
    }

    // Sort images by modified time (newest first)
    uasort($imageGroups, function($a, $b) {
        return $b['modified'] - $a['modified'];
    });

    // Sort files by modified time (newest first)
    usort($files, function($a, $b) {
        return $b['modified'] - $a['modified'];
    });

    return ['files' => $files, 'images' => array_values($imageGroups)];
}

$result = scanMediaDirectory($uploadsDir, $config['root_dir'], $uploadsWebPath);
$images = $result['images'];
$files = $result['files'];

$pageTitle = 'Media Manager';
$activePage = 'media';

require __DIR__ . '/includes/header.php';
?>

<style>
.media-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 1.5rem;
}

.media-item {
    position: relative;
    border-radius: 0.5rem;
    overflow: hidden;
    background: white;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    transition: transform 0.2s, box-shadow 0.2s;
}

.media-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px rgba(0,0,0,0.15);
}

.media-image {
    width: 100%;
    height: 200px;
    object-fit: cover;
    background: #f3f4f6;
}

.dropzone {
    border: 3px dashed #cbd5e0;
    border-radius: 0.5rem;
    padding: 3rem;
    text-align: center;
    transition: all 0.3s;
    cursor: pointer;
    background: #f7fafc;
}

.dropzone.dragover {
    border-color: #4299e1;
    background: #ebf8ff;
}

.copy-btn {
    transition: background-color 0.2s;
}

.copy-btn.copied {
    background-color: #48bb78 !important;
}
</style>

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900 mb-2">Media Manager</h1>
    <p class="text-gray-600">Upload and manage images and files</p>
</div>

<div x-data="mediaManager()" x-init="init()">
    <!-- Upload Area -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
        <h2 class="text-xl font-semibold text-gray-900 mb-4">Upload Files</h2>

        <div
            @drop.prevent="handleDrop($event)"
            @dragover.prevent="dragover = true"
            @dragleave.prevent="dragover = false"
            @click="$refs.fileInput.click()"
            :class="dragover ? 'dragover' : ''"
            class="dropzone">
            <svg class="mx-auto h-12 w-12 text-gray-400 mb-4" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <p class="text-lg font-medium text-gray-700 mb-2">Drop files here or click to upload</p>
            <p class="text-sm text-gray-500">Images will be automatically optimized and resized</p>
            <input
                type="file"
                x-ref="fileInput"
                @change="handleFileSelect($event)"
                multiple
                class="hidden"
                accept="image/*,application/pdf,.doc,.docx,.txt,.zip">
        </div>

        <!-- Optional subdirectory -->
        <div class="mt-4">
            <label class="block text-sm font-medium text-gray-700 mb-2">Subdirectory (optional):</label>
            <input
                type="text"
                x-model="subdir"
                placeholder="e.g., blog, products, documents"
                class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
            <p class="mt-1 text-sm text-gray-500">Files will be organized in this subdirectory within uploads</p>
        </div>

        <!-- Upload Progress -->
        <div x-show="uploading" class="mt-4">
            <div class="bg-blue-50 border-l-4 border-blue-500 p-4">
                <p class="text-blue-700" x-text="uploadMessage"></p>
            </div>
        </div>

        <!-- Upload Error -->
        <div x-show="uploadError" class="mt-4">
            <div class="bg-red-50 border-l-4 border-red-500 p-4">
                <p class="text-red-700" x-text="uploadError"></p>
            </div>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="mb-4">
        <div class="border-b border-gray-200">
            <nav class="-mb-px flex space-x-8">
                <button
                    @click="activeTab = 'images'"
                    :class="activeTab === 'images' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                    Images (<?php echo count($images); ?>)
                </button>
                <button
                    @click="activeTab = 'files'"
                    :class="activeTab === 'files' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                    Files (<?php echo count($files); ?>)
                </button>
            </nav>
        </div>
    </div>

    <!-- Images Grid -->
    <div x-show="activeTab === 'images'" class="media-grid">
        <?php foreach ($images as $index => $image): ?>
        <div class="media-item" x-data="{ activeFormat: 'webp' }">
            <?php
            // Get thumbnail for preview (prefer webp)
            $previewUrl = $image['formats']['thumb_webp']['url'] ?? $image['formats']['thumb_png']['url'] ?? $image['formats']['full_webp']['url'] ?? $image['formats']['full_png']['url'] ?? '';
            ?>
            <img src="<?php echo htmlspecialchars($previewUrl); ?>"
                 alt="<?php echo htmlspecialchars($image['name']); ?>"
                 class="media-image">

            <div class="p-4">
                <p class="text-sm font-medium text-gray-900 truncate mb-2" title="<?php echo htmlspecialchars($image['name']); ?>">
                    <?php echo htmlspecialchars($image['name']); ?>
                </p>
                <p class="text-xs text-gray-500 mb-3">
                    <?php echo date('M d, Y', $image['modified']); ?>
                </p>

                <!-- Format Tabs -->
                <div class="mb-3">
                    <div class="flex border-b border-gray-200">
                        <?php if (isset($image['formats']['full_webp']) || isset($image['formats']['thumb_webp'])): ?>
                        <button
                            @click="activeFormat = 'webp'"
                            :class="activeFormat === 'webp' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                            class="flex-1 py-2 px-3 text-xs font-medium border-b-2 transition">
                            WebP
                        </button>
                        <?php endif; ?>
                        <?php if (isset($image['formats']['full_png']) || isset($image['formats']['thumb_png'])): ?>
                        <button
                            @click="activeFormat = 'png'"
                            :class="activeFormat === 'png' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                            class="flex-1 py-2 px-3 text-xs font-medium border-b-2 transition">
                            PNG
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="space-y-2">
                    <!-- WebP Format URLs -->
                    <?php if (isset($image['formats']['full_webp']) || isset($image['formats']['thumb_webp'])): ?>
                    <div x-show="activeFormat === 'webp'">
                        <?php if (isset($image['formats']['full_webp'])): ?>
                        <div class="mb-2">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Full (WebP):</label>
                            <div class="flex gap-2">
                                <input
                                    type="text"
                                    value="<?php echo htmlspecialchars($image['formats']['full_webp']['url']); ?>"
                                    readonly
                                    class="flex-1 text-xs px-2 py-1 border border-gray-300 rounded bg-gray-50 font-mono">
                                <button
                                    @click="copyToClipboard('<?php echo htmlspecialchars($image['formats']['full_webp']['url']); ?>', $event)"
                                    class="copy-btn px-3 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700 transition">
                                    Copy
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (isset($image['formats']['thumb_webp'])): ?>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Thumb (WebP):</label>
                            <div class="flex gap-2">
                                <input
                                    type="text"
                                    value="<?php echo htmlspecialchars($image['formats']['thumb_webp']['url']); ?>"
                                    readonly
                                    class="flex-1 text-xs px-2 py-1 border border-gray-300 rounded bg-gray-50 font-mono">
                                <button
                                    @click="copyToClipboard('<?php echo htmlspecialchars($image['formats']['thumb_webp']['url']); ?>', $event)"
                                    class="copy-btn px-3 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700 transition">
                                    Copy
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- PNG Format URLs -->
                    <?php if (isset($image['formats']['full_png']) || isset($image['formats']['thumb_png'])): ?>
                    <div x-show="activeFormat === 'png'">
                        <?php if (isset($image['formats']['full_png'])): ?>
                        <div class="mb-2">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Full (PNG):</label>
                            <div class="flex gap-2">
                                <input
                                    type="text"
                                    value="<?php echo htmlspecialchars($image['formats']['full_png']['url']); ?>"
                                    readonly
                                    class="flex-1 text-xs px-2 py-1 border border-gray-300 rounded bg-gray-50 font-mono">
                                <button
                                    @click="copyToClipboard('<?php echo htmlspecialchars($image['formats']['full_png']['url']); ?>', $event)"
                                    class="copy-btn px-3 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700 transition">
                                    Copy
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (isset($image['formats']['thumb_png'])): ?>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Thumb (PNG):</label>
                            <div class="flex gap-2">
                                <input
                                    type="text"
                                    value="<?php echo htmlspecialchars($image['formats']['thumb_png']['url']); ?>"
                                    readonly
                                    class="flex-1 text-xs px-2 py-1 border border-gray-300 rounded bg-gray-50 font-mono">
                                <button
                                    @click="copyToClipboard('<?php echo htmlspecialchars($image['formats']['thumb_png']['url']); ?>', $event)"
                                    class="copy-btn px-3 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700 transition">
                                    Copy
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Delete Button -->
                    <button
                        @click="deleteFile('<?php echo htmlspecialchars(reset($image['formats'])['url'] ?? ''); ?>')"
                        class="w-full px-3 py-2 bg-red-600 text-white text-sm rounded hover:bg-red-700 transition mt-3">
                        Delete All
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (empty($images)): ?>
        <div class="col-span-full text-center py-12 text-gray-500">
            <svg class="mx-auto h-12 w-12 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
            </svg>
            <p>No images uploaded yet</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Files List -->
    <div x-show="activeTab === 'files'">
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <?php if (!empty($files)): ?>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">File Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Size</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Modified</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">URL</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($files as $file): ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <svg class="h-5 w-5 text-gray-400 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"></path>
                                </svg>
                                <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($file['name']); ?></span>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo number_format($file['size'] / 1024, 1); ?> KB
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo date('M d, Y', $file['modified']); ?>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex gap-2">
                                <input
                                    type="text"
                                    value="<?php echo htmlspecialchars($file['path']); ?>"
                                    readonly
                                    class="flex-1 text-xs px-2 py-1 border border-gray-300 rounded bg-gray-50 font-mono">
                                <button
                                    @click="copyToClipboard('<?php echo htmlspecialchars($file['path']); ?>', $event)"
                                    class="copy-btn px-3 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700 transition">
                                    Copy
                                </button>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <button
                                @click="deleteFile('<?php echo htmlspecialchars($file['path']); ?>')"
                                class="text-red-600 hover:text-red-900">
                                Delete
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="text-center py-12 text-gray-500">
                <svg class="mx-auto h-12 w-12 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                </svg>
                <p>No files uploaded yet</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = '<?php $token = CSRF::getToken(); if (!$token) { $token = CSRF::generateToken(); } echo $token; ?>';

function mediaManager() {
    return {
        activeTab: 'images',
        dragover: false,
        uploading: false,
        uploadMessage: '',
        uploadError: '',
        subdir: '',

        init() {
        },

        handleDrop(e) {
            this.dragover = false;
            const files = e.dataTransfer.files;
            this.uploadFiles(files);
        },

        handleFileSelect(e) {
            const files = e.target.files;
            this.uploadFiles(files);
            // Reset input
            e.target.value = '';
        },

        async uploadFiles(files) {
            for (const file of files) {
                await this.uploadFile(file);
            }
        },

        async uploadFile(file) {
            this.uploading = true;
            this.uploadError = '';
            this.uploadMessage = `Uploading ${file.name}...`;

            try {
                // Read file as base64
                const base64Data = await this.fileToBase64(file);

                // Prepare form data
                const formData = new FormData();
                formData.append('action', 'upload');
                formData.append('file_data', base64Data);
                formData.append('file_name', file.name);
                formData.append('file_type', file.type);
                formData.append('subdir', this.subdir);
                formData.append('csrf_token', CSRF_TOKEN);

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });

                const responseText = await response.text();

                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (e) {
                    throw new Error('Invalid server response: ' + responseText.substring(0, 100));
                }

                if (result.success) {
                    this.uploadMessage = `Successfully uploaded ${file.name}`;
                    // Reload page after short delay
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    throw new Error(result.error || 'Upload failed');
                }

            } catch (error) {
                this.uploadError = `Error uploading ${file.name}: ${error.message}`;
            } finally {
                this.uploading = false;
            }
        },

        fileToBase64(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => {
                    // Remove data URL prefix
                    const base64 = reader.result.split(',')[1];
                    resolve(base64);
                };
                reader.onerror = reject;
                reader.readAsDataURL(file);
            });
        },

        async copyToClipboard(text, event) {
            try {
                await navigator.clipboard.writeText(text);

                // Visual feedback
                const btn = event.target;
                const originalText = btn.textContent;
                btn.classList.add('copied');
                btn.textContent = 'Copied!';

                setTimeout(() => {
                    btn.classList.remove('copied');
                    btn.textContent = originalText;
                }, 2000);
            } catch (err) {
                alert('Failed to copy: ' + err.message);
            }
        },

        async deleteFile(filePath) {
            if (!confirm('Are you sure you want to delete this file? This cannot be undone.')) {
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('file_path', filePath);
                formData.append('csrf_token', CSRF_TOKEN);

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    // Reload page
                    window.location.reload();
                } else {
                    throw new Error(result.error || 'Delete failed');
                }

            } catch (error) {
                alert('Error deleting file: ' + error.message);
            }
        }
    };
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
