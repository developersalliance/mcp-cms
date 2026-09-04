<?php
/**
 * MediaIndex — flat-file catalogue of uploaded images.
 *
 * File: {cms_dir}/content/media.json — a JSON list of entries:
 *   { id, url, thumb_url, width, height, format, name, alt, caption, bytes,
 *     uploaded_at, uploaded_by, source }        source: upload | url | generated
 *
 * Uploads are renamed to random hashes on disk, so this index is the only
 * place a human-readable name / alt text / caption lives. The media picker,
 * the admin library and the MCP list_media / update_media tools all read it.
 * reconcile() lazily adds files that exist on disk but not in the index so
 * installs that predate the index still list everything.
 *
 * Writes are serialised with flock() on a lock file and committed with an
 * atomic rename so concurrent uploads never clobber each other.
 */
class MediaIndex
{
    private string $file;
    private string $lockFile;

    public function __construct(string $cmsDir)
    {
        $dir = rtrim($cmsDir, '/') . '/content';
        $this->file = $dir . '/media.json';
        $this->lockFile = $dir . '/.media.lock';
    }

    public function path(): string
    {
        return $this->file;
    }

    /** @return array<int, array<string, mixed>> newest first */
    public function all(): array
    {
        $items = $this->read();
        usort($items, fn($a, $b) => strcmp((string)($b['uploaded_at'] ?? ''), (string)($a['uploaded_at'] ?? '')));
        return $items;
    }

    public function get(string $idOrUrl): ?array
    {
        foreach ($this->read() as $it) {
            if (($it['id'] ?? null) === $idOrUrl || ($it['url'] ?? null) === $idOrUrl) {
                return $it;
            }
        }
        return null;
    }

    /** Substring search across name, alt, caption and url (case-insensitive). */
    public function find(string $query, int $limit = 50, int $offset = 0): array
    {
        $q = mb_strtolower(trim($query));
        $out = [];
        foreach ($this->all() as $it) {
            if ($q !== '') {
                $hay = mb_strtolower(implode(' ', [
                    (string)($it['name'] ?? ''), (string)($it['alt'] ?? ''),
                    (string)($it['caption'] ?? ''), (string)($it['url'] ?? ''),
                ]));
                if (mb_strpos($hay, $q) === false) continue;
            }
            $out[] = $it;
        }
        return ['total' => count($out), 'items' => array_slice($out, max(0, $offset), max(1, $limit))];
    }

    /**
     * Add an entry. Missing fields are filled with defaults; an entry with the
     * same url is replaced (re-uploads of the same hash cannot happen, but a
     * reconcile() followed by a real add() can).
     */
    public function add(array $entry): array
    {
        $entry = $this->normalize($entry);
        $this->mutate(function (array &$items) use ($entry) {
            foreach ($items as $i => $it) {
                if (($it['url'] ?? null) === $entry['url']) {
                    $items[$i] = array_merge($it, array_filter($entry, fn($v) => $v !== null && $v !== ''));
                    return;
                }
            }
            $items[] = $entry;
        });
        return $entry;
    }

    /** Update editable fields (name, alt, caption) by id or url. */
    public function update(string $idOrUrl, array $fields): ?array
    {
        $allowed = ['name', 'alt', 'caption'];
        $updated = null;
        $this->mutate(function (array &$items) use ($idOrUrl, $fields, $allowed, &$updated) {
            foreach ($items as $i => $it) {
                if (($it['id'] ?? null) === $idOrUrl || ($it['url'] ?? null) === $idOrUrl) {
                    foreach ($allowed as $k) {
                        if (array_key_exists($k, $fields) && $fields[$k] !== null) {
                            $items[$i][$k] = mb_substr(trim((string)$fields[$k]), 0, 500);
                        }
                    }
                    $updated = $items[$i];
                    return;
                }
            }
        });
        return $updated;
    }

    public function remove(string $idOrUrl): bool
    {
        $removed = false;
        $this->mutate(function (array &$items) use ($idOrUrl, &$removed) {
            $items = array_values(array_filter($items, function ($it) use ($idOrUrl, &$removed) {
                $hit = ($it['id'] ?? null) === $idOrUrl || ($it['url'] ?? null) === $idOrUrl;
                if ($hit) $removed = true;
                return !$hit;
            }));
        });
        return $removed;
    }

    /**
     * Add index entries for image files on disk that the index does not know
     * about (pre-index uploads). Thumbnails (-thumb.*) are folded into their
     * full-size sibling. Returns the number of entries added.
     */
    public function reconcile(string $uploadsDir, string $uploadsWebPath): int
    {
        if (!is_dir($uploadsDir)) return 0;
        $known = [];
        foreach ($this->read() as $it) { $known[(string)($it['url'] ?? '')] = true; }

        $added = 0;
        $uploadsDir = rtrim($uploadsDir, '/');
        $uploadsWebPath = '/' . trim($uploadsWebPath, '/');
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $new = [];
        foreach ($iter as $f) {
            if (!$f->isFile()) continue;
            $ext = strtolower($f->getExtension());
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) continue;
            $name = $f->getFilename();
            if (strpos($name, '-thumb.') !== false) continue;
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($uploadsDir) + 1));
            $url = $uploadsWebPath . '/' . $rel;
            if (isset($known[$url])) continue;
            $base = substr($rel, 0, -(strlen($ext) + 1));
            $thumbUrl = null;
            foreach ([$ext, 'webp', 'png', 'jpg'] as $tExt) {
                if (is_file($uploadsDir . '/' . $base . '-thumb.' . $tExt)) {
                    $thumbUrl = $uploadsWebPath . '/' . $base . '-thumb.' . $tExt;
                    break;
                }
            }
            $size = @getimagesize($f->getPathname());
            $new[] = $this->normalize([
                'url' => $url,
                'thumb_url' => $thumbUrl,
                'width' => $size[0] ?? null,
                'height' => $size[1] ?? null,
                'format' => $ext === 'jpeg' ? 'jpg' : $ext,
                'name' => pathinfo($name, PATHINFO_FILENAME),
                'bytes' => $f->getSize(),
                'uploaded_at' => date('c', $f->getMTime()),
                'source' => 'upload',
            ]);
            $known[$url] = true;
            $added++;
        }
        if ($new) {
            $this->mutate(function (array &$items) use ($new) {
                $have = [];
                foreach ($items as $it) { $have[(string)($it['url'] ?? '')] = true; }
                foreach ($new as $n) { if (!isset($have[$n['url']])) $items[] = $n; }
            });
        }
        return $added;
    }

    // --- internals -------------------------------------------------------

    private function normalize(array $e): array
    {
        return [
            'id' => (string)($e['id'] ?? $this->idFromUrl((string)($e['url'] ?? ''))),
            'url' => (string)($e['url'] ?? ''),
            'thumb_url' => isset($e['thumb_url']) ? (string)$e['thumb_url'] : null,
            'width' => isset($e['width']) ? (int)$e['width'] : null,
            'height' => isset($e['height']) ? (int)$e['height'] : null,
            'format' => (string)($e['format'] ?? strtolower(pathinfo((string)($e['url'] ?? ''), PATHINFO_EXTENSION))),
            'name' => mb_substr(trim((string)($e['name'] ?? '')), 0, 200),
            'alt' => mb_substr(trim((string)($e['alt'] ?? '')), 0, 500),
            'caption' => mb_substr(trim((string)($e['caption'] ?? '')), 0, 500),
            'bytes' => isset($e['bytes']) ? (int)$e['bytes'] : null,
            'uploaded_at' => (string)($e['uploaded_at'] ?? date('c')),
            'uploaded_by' => (string)($e['uploaded_by'] ?? ''),
            'source' => in_array($e['source'] ?? '', ['upload', 'url', 'generated'], true) ? $e['source'] : 'upload',
        ];
    }

    private function idFromUrl(string $url): string
    {
        $base = pathinfo($url, PATHINFO_FILENAME);
        return $base !== '' ? $base : bin2hex(random_bytes(8));
    }

    private function read(): array
    {
        if (!is_file($this->file)) return [];
        $raw = @file_get_contents($this->file);
        $data = $raw !== false ? json_decode($raw, true) : null;
        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    private function mutate(callable $fn): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        if (!is_file($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "Require all denied\n"); }
        $fp = fopen($this->lockFile, 'c');
        if ($fp === false) throw new Exception('Cannot open media index lock');
        try {
            if (!flock($fp, LOCK_EX)) throw new Exception('Cannot lock media index');
            $items = $this->read();
            $fn($items);
            $tmp = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            $json = json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false || file_put_contents($tmp, $json) === false) {
                @unlink($tmp);
                throw new Exception('Cannot write media index');
            }
            if (!rename($tmp, $this->file)) {
                @unlink($tmp);
                throw new Exception('Cannot replace media index');
            }
            @chmod($this->file, 0664);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
