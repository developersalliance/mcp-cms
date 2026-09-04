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

    /** Optional catalogue of uploaded images (see core/MediaIndex.php). */
    private $mediaIndex = null;

    /** When true, uploadImageFromUrl() may fetch private/loopback hosts (tests only). */
    private bool $allowPrivateUrls = false;

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

    /** Attach the media index so image uploads are catalogued (name/alt/caption). */
    public function setMediaIndex($mediaIndex): void
    {
        $this->mediaIndex = $mediaIndex;
    }

    public function getMediaIndex()
    {
        return $this->mediaIndex;
    }

    public function setAllowPrivateUrls(bool $allow): void
    {
        $this->allowPrivateUrls = $allow;
    }

    /** Web path of the uploads directory, e.g. /assets/content */
    public function uploadsWebPath(): string
    {
        return '/' . $this->uploadsDir;
    }

    /** Filesystem path of the uploads directory. */
    public function uploadsFsPath(): string
    {
        return $this->rootDir . '/' . $this->uploadsDir;
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
    public function uploadImage(string $base64Data, string $filename, ?string $subdir = null, bool $includeWebp = false, array $meta = []): array
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

            // Refuse decompression bombs before GD allocates the bitmap
            $dims = @getimagesizefromstring($imageData);
            if (!$dims || $dims[0] < 1 || $dims[1] < 1) {
                throw new Exception('Invalid image data');
            }
            if ($dims[0] * $dims[1] > 40_000_000) {
                throw new Exception('Image dimensions too large (max 40 megapixels)');
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

            // Output format follows the source: photos (JPEG) stay JPEG so a
            // resized 1280px photo is ~150 KB instead of a multi-MB PNG;
            // PNG/GIF/WebP sources become PNG (lossless, keeps transparency).
            $isJpeg = ($detectedMime === 'image/jpeg');
            $outExt = $isJpeg ? 'jpg' : 'png';
            $writeImage = function ($img, string $path) use ($isJpeg): void {
                if ($isJpeg) {
                    imageinterlace($img, true); // progressive JPEG
                    imagejpeg($img, $path, 85);
                } else {
                    imagepng($img, $path, 9);
                }
            };

            // Save full-size image (default format), then fail loudly if the
            // write silently produced nothing (unwritable uploads dir).
            $fullPngFilename = $baseFilename . '.' . $outExt;
            $fullPngPath = $fullDir . '/' . $fullPngFilename;
            $writeImage($fullImage, $fullPngPath);
            if (!file_exists($fullPngPath) || filesize($fullPngPath) === 0) {
                throw new Exception('Failed to write image — check that the uploads directory is writable: ' . $fullDir);
            }
            $result['full'][$outExt] = [
                'url' => '/' . $relativePath . '/' . $fullPngFilename,
                'path' => $relativePath . '/' . $fullPngFilename,
                'width' => $fullDimensions['width'],
                'height' => $fullDimensions['height']
            ];
            // Flat convenience fields (what editors and pickers actually need)
            $result['url'] = $result['full'][$outExt]['url'];
            $result['path'] = $result['full'][$outExt]['path'];
            $result['filename'] = $fullPngFilename;
            $result['format'] = $outExt;
            $result['width'] = $fullDimensions['width'];
            $result['height'] = $fullDimensions['height'];

            // WebP only on request (PNG is the default)
            if ($includeWebp) {
                $fullWebpFilename = $baseFilename . '.webp';
                $fullWebpPath = $fullDir . '/' . $fullWebpFilename;
                imagewebp($fullImage, $fullWebpPath, 85);
                $result['full']['webp'] = [
                    'url' => '/' . $relativePath . '/' . $fullWebpFilename,
                    'path' => $relativePath . '/' . $fullWebpFilename,
                    'width' => $fullDimensions['width'],
                    'height' => $fullDimensions['height']
                ];
            }

            imagedestroy($fullImage);

            // Generate thumbnail images
            $thumbDimensions = $this->calculateDimensions(
                $originalWidth,
                $originalHeight,
                $this->imageThumbnailWidth,
                $this->imageThumbnailHeight
            );

            $thumbImage = $this->resizeImage($sourceImage, $originalWidth, $originalHeight, $thumbDimensions['width'], $thumbDimensions['height']);

            // Save thumbnail in the same format as the full-size image
            $thumbPngFilename = $baseFilename . '-thumb.' . $outExt;
            $thumbPngPath = $fullDir . '/' . $thumbPngFilename;
            $writeImage($thumbImage, $thumbPngPath);
            if (!file_exists($thumbPngPath) || filesize($thumbPngPath) === 0) {
                throw new Exception('Failed to write thumbnail — check that the uploads directory is writable: ' . $fullDir);
            }

            // WebP thumbnail only on request
            if ($includeWebp) {
                $thumbWebpFilename = $baseFilename . '-thumb.webp';
                $thumbWebpPath = $fullDir . '/' . $thumbWebpFilename;
                imagewebp($thumbImage, $thumbWebpPath, 85);
                $result['thumbnail']['webp'] = [
                    'url' => '/' . $relativePath . '/' . $thumbWebpFilename,
                    'path' => $relativePath . '/' . $thumbWebpFilename,
                    'width' => $thumbDimensions['width'],
                    'height' => $thumbDimensions['height']
                ];
            }

            $result['thumbnail'][$outExt] = [
                'url' => '/' . $relativePath . '/' . $thumbPngFilename,
                'path' => $relativePath . '/' . $thumbPngFilename,
                'width' => $thumbDimensions['width'],
                'height' => $thumbDimensions['height']
            ];
            $result['thumb_url'] = $result['thumbnail'][$outExt]['url'];

            imagedestroy($thumbImage);
            imagedestroy($sourceImage);

            // Suggested markup an editor or LLM can paste as-is
            $altText = trim((string)($meta['alt'] ?? ''));
            $result['alt'] = $altText;
            $result['html'] = '<img src="' . htmlspecialchars($result['url'], ENT_QUOTES) . '" alt="' . htmlspecialchars($altText, ENT_QUOTES) . '"'
                . ' width="' . (int)$result['width'] . '" height="' . (int)$result['height'] . '" loading="lazy">';

            // Catalogue the upload (human name / alt / caption live only here;
            // the file on disk is a random hash).
            if ($this->mediaIndex) {
                $niceName = trim((string)($meta['name'] ?? ''));
                if ($niceName === '') {
                    $niceName = pathinfo($cleanName !== '' ? $cleanName : $fullPngFilename, PATHINFO_FILENAME);
                }
                try {
                    $entry = $this->mediaIndex->add([
                        'url' => $result['url'],
                        'thumb_url' => $result['thumb_url'],
                        'width' => $result['width'],
                        'height' => $result['height'],
                        'format' => $outExt,
                        'name' => $niceName,
                        'alt' => $altText,
                        'caption' => (string)($meta['caption'] ?? ''),
                        'bytes' => filesize($fullPngPath) ?: null,
                        'uploaded_by' => (string)($meta['uploaded_by'] ?? ''),
                        'source' => (string)($meta['source'] ?? 'upload'),
                    ]);
                    $result['id'] = $entry['id'];
                    $result['name'] = $entry['name'];
                    $result['caption'] = $entry['caption'];
                } catch (Exception $e) {
                    error_log('MediaIndex add failed: ' . $e->getMessage());
                }
            }

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
    /**
     * Fetch an image from a public http(s) URL and run it through the normal
     * upload pipeline. Guards against SSRF: only http/https, host must not
     * resolve to a private / loopback / link-local / metadata address (checked
     * on the initial URL and again on every redirect), 10 s timeout, 10 MB cap,
     * response must be an image (Content-Type or sniffed bytes).
     */
    public function uploadImageFromUrl(string $url, ?string $subdir = null, array $meta = []): array
    {
        try {
            if (!function_exists('curl_init')) {
                throw new Exception('The curl extension is required for URL uploads');
            }
            $url = trim($url);
            $pinIp = $this->assertPublicUrl($url);

            $maxBytes = self::MAX_FILE_BYTES;
            $body = '';
            $tooBig = false;
            $currentUrl = $url;
            $redirects = 0;
            $finalType = '';

            while (true) {
                $ch = curl_init($currentUrl);
                $body = '';
                $tooBig = false;
                if ($pinIp !== null) {
                    // Pin the vetted address: DNS cannot be re-resolved to a private host between check and fetch.
                    $cp = parse_url($currentUrl);
                    $port = (int)($cp['port'] ?? ((($cp['scheme'] ?? 'http') === 'https') ? 443 : 80));
                    curl_setopt($ch, CURLOPT_RESOLVE, [strtolower((string)($cp['host'] ?? '')) . ':' . $port . ':' . $pinIp]);
                }
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => false,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_USERAGENT => 'mcp-cms/1.1 (+image import)',
                    CURLOPT_HTTPHEADER => ['Accept: image/*'],
                    CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, &$tooBig, $maxBytes) {
                        $body .= $chunk;
                        if (strlen($body) > $maxBytes) { $tooBig = true; return 0; }
                        return strlen($chunk);
                    },
                ]);
                curl_exec($ch);
                $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $redirectUrl = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
                $finalType = strtolower(trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE)));
                $err = curl_error($ch);
                curl_close($ch);

                if ($tooBig) {
                    throw new Exception('Remote image exceeds the 10 MB limit');
                }
                if ($status >= 300 && $status < 400 && $redirectUrl !== '') {
                    if (++$redirects > 3) {
                        throw new Exception('Too many redirects');
                    }
                    $pinIp = $this->assertPublicUrl($redirectUrl);
                    $currentUrl = $redirectUrl;
                    continue;
                }
                if ($err !== '' && $body === '') {
                    throw new Exception('Could not fetch the URL: ' . $err);
                }
                if ($status !== 200) {
                    throw new Exception('Remote server answered HTTP ' . $status);
                }
                break;
            }

            $detected = $this->detectMime($body);
            $ctIsImage = str_starts_with($finalType, 'image/');
            if (!$ctIsImage && ($detected === null || !str_starts_with($detected, 'image/'))) {
                throw new Exception('The URL did not return an image (Content-Type: ' . ($finalType ?: 'unknown') . ')');
            }

            // Derive a filename: explicit > URL basename > detected type
            $filename = trim((string)($meta['filename'] ?? ''));
            if ($filename === '') {
                $pathPart = (string)(parse_url($currentUrl, PHP_URL_PATH) ?? '');
                $filename = basename($pathPart);
            }
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!in_array($ext, self::ALLOWED_IMAGE_EXTENSIONS, true)) {
                $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                $ext = $map[$detected ?? ''] ?? $map[$finalType] ?? '';
                if ($ext === '') {
                    throw new Exception('Unsupported image type: ' . ($detected ?: $finalType));
                }
                $stem = pathinfo($filename, PATHINFO_FILENAME);
                $filename = ($stem !== '' ? $stem : 'image') . '.' . $ext;
            }
            if (empty($meta['name'])) {
                $meta['name'] = pathinfo($filename, PATHINFO_FILENAME);
            }
            $meta['source'] = $meta['source'] ?? 'url';

            $result = $this->uploadImage(base64_encode($body), $filename, $subdir, false, $meta);
            if ($result['success'] ?? false) {
                $result['source_url'] = $url;
            }
            return $result;
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Remove every file that belongs to an uploaded image (all formats + thumb)
     * and drop it from the media index. $url is the web path (/assets/content/x.jpg).
     */
    public function deleteMedia(string $url): array
    {
        try {
            $url = '/' . ltrim(trim($url), '/');
            $uploadsWeb = $this->uploadsWebPath();
            if (strpos($url, $uploadsWeb . '/') !== 0 || strpos($url, '..') !== false) {
                throw new Exception('Path must be inside the uploads directory');
            }
            $fullPath = $this->rootDir . $url;
            $realUploads = realpath($this->uploadsFsPath());
            $real = realpath($fullPath);
            if ($real === false || $realUploads === false || strpos($real, $realUploads) !== 0) {
                throw new Exception('File not found');
            }
            $info = pathinfo($real);
            $base = $info['filename'];
            if (substr($base, -6) === '-thumb') {
                $base = substr($base, 0, -6);
            }
            $candidates = array_merge(
                glob($info['dirname'] . '/' . $base . '.*') ?: [],
                glob($info['dirname'] . '/' . $base . '-thumb.*') ?: []
            );
            $deleted = 0;
            foreach ($candidates as $cand) {
                $candReal = realpath($cand);
                if ($candReal && strpos($candReal, $realUploads) === 0 && is_file($candReal) && @unlink($candReal)) {
                    $deleted++;
                }
            }
            if ($this->mediaIndex) {
                // Index stores the full-size url; normalise a thumb url back to it
                $canonical = preg_replace('/-thumb(\.[a-z0-9]+)$/i', '$1', $url);
                $this->mediaIndex->remove($canonical);
                $this->mediaIndex->remove($url);
            }
            return ['success' => true, 'deleted' => $deleted, 'message' => 'Deleted ' . $deleted . ' file(s)'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * SSRF guard: scheme must be http/https and every address the host
     * resolves to must be public. Throws on violation.
     */
    private function assertPublicUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            throw new Exception('Invalid URL');
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new Exception('Only http and https URLs are allowed');
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            throw new Exception('URLs with credentials are not allowed');
        }
        if ($this->allowPrivateUrls) {
            return null;
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new Exception('URL host is not allowed');
        }
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $v4 = @gethostbynamel($host) ?: [];
            $v6 = [];
            $records = @dns_get_record($host, DNS_AAAA) ?: [];
            foreach ($records as $r) { if (!empty($r['ipv6'])) $v6[] = $r['ipv6']; }
            $ips = array_merge($v4, $v6);
            if ($ips === []) {
                throw new Exception('URL host could not be resolved');
            }
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new Exception('URL host resolves to a private or reserved address');
            }
        }
        return $ips[0];
    }

    public static function isPublicIp(string $ip): bool
    {
        if ($ip === '0.0.0.0' || $ip === '::') return false;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // Explicit ranges in case the platform filter misses one
        $v4 = ip2long($ip);
        if ($v4 !== false) {
            $blocked = [
                ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16],
                ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['224.0.0.0', 3],
            ];
            foreach ($blocked as [$net, $bits]) {
                $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
                if (($v4 & $mask) === (ip2long($net) & $mask)) return false;
            }
            return true;
        }
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) return false;
        $b0 = ord($bin[0]);
        if ($ip === '::1') return false;
        if (($b0 & 0xfe) === 0xfc) return false;                 // fc00::/7
        if ($b0 === 0xfe && (ord($bin[1]) & 0xc0) === 0x80) return false; // fe80::/10
        if ($b0 === 0xff) return false;                          // multicast
        if (substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {  // v4-mapped
            return self::isPublicIp(inet_ntop(substr($bin, 12)) ?: '');
        }
        return true;
    }

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
