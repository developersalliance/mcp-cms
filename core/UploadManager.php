<?php
/**
 * Upload Manager
 * Handles file and image uploads with automatic resizing and format conversion
 */

class UploadManager
{
    private string $rootDir;
    private string $uploadsDir;
    private int $imageThumbnailWidth;
    private int $imageThumbnailHeight;
    private int $imageFullWidth;
    private int $imageFullHeight;

    // Strict allowlist of file extensions accepted by uploadFile()
    private const ALLOWED_FILE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'csv', 'md', 'zip',
        'mp3', 'mp4', 'webm', 'ogg',
    ];

    // Allowlist of image extensions accepted by uploadImage() inputs.
    // Note: uploadImage() always re-encodes outputs to webp/png so the
    // input extension only affects validation, never the on-disk filename.
    private const ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    // Map of allowed extensions to acceptable MIME types
    private const EXTENSION_MIME_MAP = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'svg'  => ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain'],
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls'  => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'ppt'  => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'txt'  => ['text/plain', 'application/octet-stream'],
        'csv'  => ['text/csv', 'text/plain', 'application/csv', 'application/octet-stream'],
        'md'   => ['text/plain', 'text/markdown', 'application/octet-stream'],
        'zip'  => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        'mp3'  => ['audio/mpeg', 'audio/mp3', 'application/octet-stream'],
        'mp4'  => ['video/mp4', 'application/mp4', 'application/octet-stream'],
        'webm' => ['video/webm', 'audio/webm', 'application/octet-stream'],
        'ogg'  => ['audio/ogg', 'video/ogg', 'application/octet-stream'],
    ];

    // Disallowed extension fragments that must never appear anywhere
    // inside the filename (catches double extensions like .php.jpg)
    private const BLOCKED_EXTENSION_FRAGMENTS = [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'pht',
        'pl', 'py', 'sh', 'cgi', 'asp', 'aspx', 'jsp',
        'htaccess', 'htpasswd',
    ];

    // Maximum payload size for uploads (10 MB of decoded data)
    private const MAX_FILE_BYTES = 10 * 1024 * 1024;

    public function __construct(
        string $rootDir,
        string $uploadsDir,
        int $imageThumbnailWidth = 300,
        int $imageThumbnailHeight = 300,
        int $imageFullWidth = 1920,
        int $imageFullHeight = 1080
    ) {
        $this->rootDir = rtrim($rootDir, '/');
        $this->uploadsDir = trim($uploadsDir, '/');
        $this->imageThumbnailWidth = $imageThumbnailWidth;
        $this->imageThumbnailHeight = $imageThumbnailHeight;
        $this->imageFullWidth = $imageFullWidth;
        $this->imageFullHeight = $imageFullHeight;

        // Ensure uploads directory exists
        $fullUploadPath = $this->rootDir . '/' . $this->uploadsDir;
        if (!is_dir($fullUploadPath)) {
            mkdir($fullUploadPath, 0755, true);
        }
    }

    /**
     * Upload a regular file
     *
     * @param string $base64Data Base64 encoded file data
     * @param string $filename Original filename
     * @param string|null $subdir Optional subdirectory within uploads
     * @return array Upload result with 'success', 'url', 'path', 'filename'
     */
    public function uploadFile(string $base64Data, string $filename, ?string $subdir = null): array
    {
        try {
            // Decode base64 data
            $fileData = base64_decode($base64Data, true);
            if ($fileData === false) {
                throw new Exception('Invalid base64 data');
            }

            // Enforce size limit
            if (strlen($fileData) > self::MAX_FILE_BYTES) {
                throw new Exception('File exceeds maximum allowed size');
            }

            // Sanitize the supplied filename and pull the extension
            $cleanName = $this->sanitizeFilename($filename);
            if ($cleanName === '' || $cleanName[0] === '.') {
                throw new Exception('Invalid filename');
            }
            $extension = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));

            // SVG is an active-content format; reject outright. The previous
            // sanitizeSvg() pass missed <foreignObject>, <style> CSS animations,
            // xlink:href with data:, and <use href="data:image/svg+xml…">.
            if ($extension === 'svg') {
                throw new Exception('SVG uploads are disabled for security reasons. Convert to PNG/JPG first.');
            }

            // Enforce extension allowlist
            if ($extension === '' || !in_array($extension, self::ALLOWED_FILE_EXTENSIONS, true)) {
                throw new Exception('File type not allowed');
            }

            // Reject any disallowed extension fragment anywhere in the name
            // (catches things like shell.php.jpg)
            $nameLower = strtolower($cleanName);
            foreach (self::BLOCKED_EXTENSION_FRAGMENTS as $bad) {
                if (preg_match('/(^|\.)' . preg_quote($bad, '/') . '(\.|$)/', $nameLower)) {
                    throw new Exception('File type not allowed');
                }
            }

            // Verify MIME type matches the claimed extension
            $detectedMime = $this->detectMime($fileData);
            $allowedMimes = self::EXTENSION_MIME_MAP[$extension] ?? [];
            if ($detectedMime !== null && !empty($allowedMimes) && !in_array($detectedMime, $allowedMimes, true)) {
                throw new Exception('File contents do not match the declared type');
            }

            // SVG uploads are rejected earlier (active-content format).
            // sanitizeSvg() is kept as a private method for reference but is
            // intentionally not called from any upload path.

            // Generate unique hash-based filename (do not preserve user-supplied basename)
            $hash = bin2hex(random_bytes(16));
            $safeFilename = $hash . '.' . $extension;

            // Ensure filename is unique (though hash collision is virtually impossible)
            $finalFilename = $this->getUniqueFilename($safeFilename, $subdir);

            // Build full path
            $relativePath = $this->uploadsDir;
            if ($subdir) {
                $relativePath .= '/' . trim($subdir, '/');
            }
            $relativePath .= '/' . $finalFilename;

            $fullPath = $this->rootDir . '/' . $relativePath;

            // Ensure directory exists
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Write file
            if (file_put_contents($fullPath, $fileData) === false) {
                throw new Exception('Failed to write file');
            }

            return [
                'success' => true,
                'url' => '/' . $relativePath,
                'path' => $relativePath,
                'filename' => $finalFilename
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Upload and process an image (resize, convert to WebP and PNG)
     *
     * @param string $base64Data Base64 encoded image data
     * @param string $filename Original filename
     * @param string|null $subdir Optional subdirectory within uploads
     * @return array Upload result with URLs for full and thumbnail images in both formats
     */
    public function uploadImage(string $base64Data, string $filename, ?string $subdir = null): array
    {
        try {
            // Decode base64 data
            $imageData = base64_decode($base64Data, true);
            if ($imageData === false) {
                throw new Exception('Invalid base64 data');
            }

            // Enforce size limit
            if (strlen($imageData) > self::MAX_FILE_BYTES) {
                throw new Exception('File exceeds maximum allowed size');
            }

            // Sanitize and validate the filename for extension purposes only;
            // the on-disk name will be derived from a hash regardless.
            $cleanName = $this->sanitizeFilename($filename);
            if ($cleanName !== '' && $cleanName[0] !== '.') {
                $extension = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));
                if ($extension !== '' && !in_array($extension, self::ALLOWED_IMAGE_EXTENSIONS, true)) {
                    throw new Exception('Image type not allowed');
                }
            }

            // Cross-check the actual content is a real image MIME type
            $detectedMime = $this->detectMime($imageData);
            $allowedImageMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if ($detectedMime !== null && !in_array($detectedMime, $allowedImageMimes, true)) {
                throw new Exception('File contents are not a supported image format');
            }

            // Create image from string
            $sourceImage = @imagecreatefromstring($imageData);
            if ($sourceImage === false) {
                throw new Exception('Invalid image data');
            }

            // Get original dimensions
            $originalWidth = imagesx($sourceImage);
            $originalHeight = imagesy($sourceImage);

            // Generate unique hash-based filename (no extension yet)
            $hash = bin2hex(random_bytes(16));

            // Create unique base filename (ensure uniqueness though collision is virtually impossible)
            $baseFilename = $this->getUniqueFilename($hash, $subdir, false);

            // Build directory path
            $relativePath = $this->uploadsDir;
            if ($subdir) {
                $relativePath .= '/' . trim($subdir, '/');
            }

            $fullDir = $this->rootDir . '/' . $relativePath;
            if (!is_dir($fullDir)) {
                mkdir($fullDir, 0755, true);
            }

            $result = [
                'success' => true,
                'original_width' => $originalWidth,
                'original_height' => $originalHeight,
                'full' => [],
                'thumbnail' => []
            ];

            // Generate full-size images
            $fullDimensions = $this->calculateDimensions(
                $originalWidth,
                $originalHeight,
                $this->imageFullWidth,
                $this->imageFullHeight
            );

            $fullImage = $this->resizeImage($sourceImage, $originalWidth, $originalHeight, $fullDimensions['width'], $fullDimensions['height']);

            // Save WebP full
            $fullWebpFilename = $baseFilename . '.webp';
            $fullWebpPath = $fullDir . '/' . $fullWebpFilename;
            imagewebp($fullImage, $fullWebpPath, 85);
            $result['full']['webp'] = [
                'url' => '/' . $relativePath . '/' . $fullWebpFilename,
                'path' => $relativePath . '/' . $fullWebpFilename,
                'width' => $fullDimensions['width'],
                'height' => $fullDimensions['height']
            ];

            // Save PNG full
            $fullPngFilename = $baseFilename . '.png';
            $fullPngPath = $fullDir . '/' . $fullPngFilename;
            imagepng($fullImage, $fullPngPath, 8);
            $result['full']['png'] = [
                'url' => '/' . $relativePath . '/' . $fullPngFilename,
                'path' => $relativePath . '/' . $fullPngFilename,
                'width' => $fullDimensions['width'],
                'height' => $fullDimensions['height']
            ];

            imagedestroy($fullImage);

            // Generate thumbnail images
            $thumbDimensions = $this->calculateDimensions(
                $originalWidth,
                $originalHeight,
                $this->imageThumbnailWidth,
                $this->imageThumbnailHeight
            );

            $thumbImage = $this->resizeImage($sourceImage, $originalWidth, $originalHeight, $thumbDimensions['width'], $thumbDimensions['height']);

            // Save WebP thumbnail
            $thumbWebpFilename = $baseFilename . '-thumb.webp';
            $thumbWebpPath = $fullDir . '/' . $thumbWebpFilename;
            imagewebp($thumbImage, $thumbWebpPath, 85);
            $result['thumbnail']['webp'] = [
                'url' => '/' . $relativePath . '/' . $thumbWebpFilename,
                'path' => $relativePath . '/' . $thumbWebpFilename,
                'width' => $thumbDimensions['width'],
                'height' => $thumbDimensions['height']
            ];

            // Save PNG thumbnail
            $thumbPngFilename = $baseFilename . '-thumb.png';
            $thumbPngPath = $fullDir . '/' . $thumbPngFilename;
            imagepng($thumbImage, $thumbPngPath, 8);
            $result['thumbnail']['png'] = [
                'url' => '/' . $relativePath . '/' . $thumbPngFilename,
                'path' => $relativePath . '/' . $thumbPngFilename,
                'width' => $thumbDimensions['width'],
                'height' => $thumbDimensions['height']
            ];

            imagedestroy($thumbImage);
            imagedestroy($sourceImage);

            return $result;

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Calculate dimensions to fit within max width/height while maintaining aspect ratio
     */
    private function calculateDimensions(int $originalWidth, int $originalHeight, int $maxWidth, int $maxHeight): array
    {
        $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);

        // If image is smaller than max dimensions, don't upscale
        if ($ratio > 1) {
            return [
                'width' => $originalWidth,
                'height' => $originalHeight
            ];
        }

        return [
            'width' => (int)round($originalWidth * $ratio),
            'height' => (int)round($originalHeight * $ratio)
        ];
    }

    /**
     * Resize image using GD
     */
    private function resizeImage($sourceImage, int $sourceWidth, int $sourceHeight, int $targetWidth, int $targetHeight)
    {
        $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);

        // Preserve transparency for PNG
        imagealphablending($targetImage, false);
        imagesavealpha($targetImage, true);

        imagecopyresampled(
            $targetImage,
            $sourceImage,
            0, 0, 0, 0,
            $targetWidth, $targetHeight,
            $sourceWidth, $sourceHeight
        );

        return $targetImage;
    }

    /**
     * Sanitize filename to prevent directory traversal and other issues
     */
    private function sanitizeFilename(string $filename): string
    {
        // Strip null bytes and any path separators / traversal sequences
        $filename = str_replace(["\0", '\\', '/'], '', $filename);
        $filename = basename($filename);

        // Remove any special characters except alphanumeric, dash, underscore, and dot
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

        // Remove multiple consecutive underscores
        $filename = preg_replace('/_+/', '_', $filename);

        // Remove leading/trailing underscores
        $filename = trim($filename, '_');

        return $filename;
    }

    /**
     * Detect a file's MIME type from its raw bytes using finfo when available
     */
    private function detectMime(string $data): ?string
    {
        if (!function_exists('finfo_open')) {
            return null;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }
        $mime = finfo_buffer($finfo, $data);
        finfo_close($finfo);
        return is_string($mime) && $mime !== '' ? strtolower($mime) : null;
    }

    /**
     * Best-effort SVG sanitization: strip <script> blocks, on* event
     * handlers, javascript: URIs, and external entity references.
     * This is a defense-in-depth measure; SVG remains an active-content
     * format and should be disabled if not strictly needed.
     */
    private function sanitizeSvg(string $svg): string
    {
        // Remove <script>...</script> blocks
        $svg = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $svg);
        // Remove on* event handler attributes
        $svg = preg_replace('#\son[a-z]+\s*=\s*"[^"]*"#i', '', $svg);
        $svg = preg_replace("#\son[a-z]+\s*=\s*'[^']*'#i", '', $svg);
        // Remove javascript: hrefs
        $svg = preg_replace('#(href|xlink:href)\s*=\s*"\s*javascript:[^"]*"#i', '$1=""', $svg);
        $svg = preg_replace("#(href|xlink:href)\s*=\s*'\s*javascript:[^']*'#i", '$1=""', $svg);
        // Remove external entity declarations
        $svg = preg_replace('#<!ENTITY[^>]*>#i', '', $svg);
        $svg = preg_replace('#<!DOCTYPE[^>]*>#i', '', $svg);
        return $svg;
    }

    /**
     * Get unique filename if file already exists
     */
    private function getUniqueFilename(string $filename, ?string $subdir, bool $includeExtension = true): string
    {
        $dir = $this->rootDir . '/' . $this->uploadsDir;
        if ($subdir) {
            $dir .= '/' . trim($subdir, '/');
        }

        if ($includeExtension) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $basename = pathinfo($filename, PATHINFO_FILENAME);
        } else {
            $basename = $filename;
            $extension = '';
        }

        $counter = 1;
        $finalFilename = $filename;

        while (file_exists($dir . '/' . $finalFilename)) {
            if ($includeExtension && $extension) {
                $finalFilename = $basename . '-' . $counter . '.' . $extension;
            } else {
                $finalFilename = $basename . '-' . $counter;
            }
            $counter++;
        }

        return $finalFilename;
    }
}
