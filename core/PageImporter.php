<?php

class PageImporter
{
    private const MIN_SIZE = 1024;
    private const MAX_READ_BYTES = 2 * 1024 * 1024;
    private const LARGE_FILE_THRESHOLD = 200 * 1024;

    private string $rootDir;
    private array $skipDirs;

    public function __construct(string $rootDir, array $extraSkipDirs = [])
    {
        $this->rootDir = rtrim($rootDir, '/');
        $this->skipDirs = array_unique(array_merge(
            ['cms', 'assets', 'uploads', 'content', 'backups', 'drafts', 'blog', 'node_modules', 'vendor'],
            $extraSkipDirs
        ));
    }

    public function scan(): array
    {
        $results = [];
        $this->scanDir($this->rootDir, $results);
        usort($results, fn($a, $b) => strcmp($a['relative_path'], $b['relative_path']));
        return $results;
    }

    public function analyzeFile(string $path): array
    {
        $rel = ltrim(str_replace($this->rootDir, '', $path), '/');
        $size = @filesize($path) ?: 0;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $content = '';
        if ($size > 0 && $size <= self::MAX_READ_BYTES) {
            $content = (string)@file_get_contents($path);
        }

        $blocks = 0;
        if ($content !== '') {
            $blocks = preg_match_all('/<!--\s*CMS:BLOCK\s+(.+?)\s+start\s*-->|<\?php\s+\/\*\s*CMS:BLOCK\s+(.+?)\s+start\s*\*\/\s*\?>/i', $content);
        }

        $title = '';
        if ($content !== '') {
            if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $content, $m)) {
                $title = trim(html_entity_decode($m[1], ENT_QUOTES));
            } elseif (preg_match('/<h1[^>]*>(.+?)<\/h1>/is', $content, $m)) {
                $title = trim(strip_tags($m[1]));
            }
            if (mb_strlen($title) > 80) {
                $title = mb_substr($title, 0, 77) . '…';
            }
        }

        $lines = $content !== '' ? substr_count($content, "\n") + 1 : 0;

        $status = 'ready';
        $statusDetail = '';
        if ($size < self::MIN_SIZE) {
            $status = 'skipped';
            $statusDetail = 'Below minimum size (' . self::MIN_SIZE . ' bytes)';
        } elseif ($size > self::MAX_READ_BYTES) {
            $status = 'skipped';
            $statusDetail = 'File too large to analyze';
        } elseif ($blocks > 0) {
            $status = 'managed';
            $statusDetail = $blocks . ' ' . ($blocks === 1 ? 'block' : 'blocks');
        } elseif ($size > self::LARGE_FILE_THRESHOLD) {
            $status = 'warn';
            $statusDetail = 'Large file — AI import will use chunked mode';
        }

        return [
            'path' => $path,
            'relative_path' => $rel,
            'size' => $size,
            'size_human' => $this->humanSize($size),
            'lines' => $lines,
            'title' => $title,
            'block_count' => $blocks,
            'managed' => $blocks > 0,
            'extension' => $ext,
            'status' => $status,
            'status_detail' => $statusDetail,
        ];
    }

    public function getFileByRelativePath(string $relativePath): ?array
    {
        $relativePath = ltrim($relativePath, '/');
        if (strpos($relativePath, '..') !== false) {
            return null;
        }
        $path = $this->rootDir . '/' . $relativePath;
        $real = realpath($path);
        if ($real === false || strpos($real, $this->rootDir) !== 0) {
            return null;
        }
        return $this->analyzeFile($real);
    }

    private function scanDir(string $dir, array &$results): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (str_starts_with($entry, '.')) continue;
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $rel = ltrim(str_replace($this->rootDir, '', $path), '/');
                $firstPart = explode('/', $rel)[0];
                if (in_array($firstPart, $this->skipDirs, true)) continue;
                $this->scanDir($path, $results);
            } elseif (is_file($path)) {
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (!in_array($ext, ['html', 'htm', 'php'], true)) continue;
                $results[] = $this->analyzeFile($path);
            }
        }
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1024 * 1024) return round($bytes / 1024) . ' KB';
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}
