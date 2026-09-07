<?php
/**
 * RedirectManager — flat-file URL redirects.
 *
 * File: {cms_dir}/settings/redirects.json — a JSON list of entries:
 *   { from, to, code (301|302), created_at }
 *
 * `from` is always a site-relative path starting with "/". `to` is either a
 * site-relative path or an absolute http(s) URL. Loops are rejected on save:
 * a redirect may not point at itself, and following a chain of managed
 * redirects from `from` may never arrive back at `from`.
 *
 * On every change the manager also rewrites a managed block in the SITE's
 * pub .htaccess (root_dir/.htaccess) between the markers
 *   # CMS-REDIRECTS-BEGIN / # CMS-REDIRECTS-END
 * so Apache installs get native redirects. The block is only written when the
 * file already exists and is writable — a failure is reported, never fatal.
 * nginx installs ignore .htaccess and use the redirect.php handler instead
 * (see docs/redirects.md).
 *
 * Writes are committed with an atomic rename so concurrent saves never leave
 * a half-written file.
 */
class RedirectManager
{
    public const HTACCESS_BEGIN = '# CMS-REDIRECTS-BEGIN';
    public const HTACCESS_END = '# CMS-REDIRECTS-END';

    private const MAX_CHAIN_HOPS = 10;

    private string $file;
    private ?string $rootDir;

    public function __construct(string $settingsDir, ?string $rootDir = null)
    {
        $this->file = rtrim($settingsDir, '/') . '/redirects.json';
        $this->rootDir = $rootDir !== null ? rtrim($rootDir, '/') : null;
    }

    public function path(): string
    {
        return $this->file;
    }

    /** @return array<int, array{from:string,to:string,code:int,created_at:string}> */
    public function listRedirects(): array
    {
        return $this->read();
    }

    public function getRedirect(string $from): ?array
    {
        $from = self::normalizeFrom($from);
        foreach ($this->read() as $r) {
            if ($r['from'] === $from) return $r;
        }
        return null;
    }

    /**
     * Add (or replace) a redirect. Throws on invalid input or a loop.
     * Returns the stored entry.
     */
    public function addRedirect(string $from, string $to, int $code = 301): array
    {
        $from = self::normalizeFrom($from);
        $to = self::normalizeTo($to);
        if (!in_array($code, [301, 302], true)) {
            throw new Exception('Redirect code must be 301 or 302');
        }
        if ($from === $to) {
            throw new Exception('A redirect cannot point to itself');
        }

        $items = $this->read();
        // Build the prospective map and refuse chains that loop back
        $map = [];
        foreach ($items as $r) {
            if ($r['from'] !== $from) $map[$r['from']] = $r['to'];
        }
        $map[$from] = $to;
        $this->assertNoLoop($from, $map);

        $entry = [
            'from' => $from,
            'to' => $to,
            'code' => $code,
            'created_at' => date('c'),
        ];
        $replaced = false;
        foreach ($items as $i => $r) {
            if ($r['from'] === $from) {
                $entry['created_at'] = $r['created_at'] ?? $entry['created_at'];
                $items[$i] = $entry;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) $items[] = $entry;

        $this->write($items);
        return $entry;
    }

    public function deleteRedirect(string $from): bool
    {
        $from = self::normalizeFrom($from);
        $items = $this->read();
        $kept = array_values(array_filter($items, fn($r) => $r['from'] !== $from));
        if (count($kept) === count($items)) return false;
        $this->write($kept);
        return true;
    }

    /**
     * Look up a redirect for a request path. Matches the exact path first,
     * then the same path with the trailing slash toggled.
     */
    public function match(string $path): ?array
    {
        $path = self::normalizeFrom($path);
        $byFrom = [];
        foreach ($this->read() as $r) { $byFrom[$r['from']] = $r; }
        if (isset($byFrom[$path])) return $byFrom[$path];
        $alt = substr($path, -1) === '/' && $path !== '/' ? rtrim($path, '/') : $path . '/';
        return $byFrom[$alt] ?? null;
    }

    /**
     * Rewrite the managed redirect block in the site's pub .htaccess.
     * Never throws: returns ['synced' => bool, 'message' => string].
     */
    public function syncHtaccess(): array
    {
        if ($this->rootDir === null) {
            return ['synced' => false, 'message' => 'No site root configured; .htaccess not updated.'];
        }
        $htaccess = $this->rootDir . '/.htaccess';
        if (!is_file($htaccess)) {
            return ['synced' => false, 'message' => 'No .htaccess at ' . $htaccess . ' — Apache installs need one; nginx installs use redirect.php instead (see docs/redirects.md).'];
        }
        if (!is_writable($htaccess)) {
            return ['synced' => false, 'message' => '.htaccess at ' . $htaccess . ' is not writable by the web server; redirects still work via redirect.php.'];
        }

        $lines = [self::HTACCESS_BEGIN, '# Managed by the CMS redirect manager — do not edit inside this block.'];
        foreach ($this->read() as $r) {
            // Values were validated on save (no whitespace/control chars), so
            // they are safe to place on a directive line.
            $lines[] = 'Redirect ' . (int)$r['code'] . ' ' . $r['from'] . ' ' . $r['to'];
        }
        $lines[] = self::HTACCESS_END;
        $block = implode("\n", $lines);

        $current = @file_get_contents($htaccess);
        if ($current === false) {
            return ['synced' => false, 'message' => 'Could not read ' . $htaccess];
        }
        $pattern = '/' . preg_quote(self::HTACCESS_BEGIN, '/') . '.*?' . preg_quote(self::HTACCESS_END, '/') . '/s';
        if (preg_match($pattern, $current)) {
            $updated = preg_replace($pattern, $block, $current, 1);
        } else {
            $updated = rtrim($current, "\n") . ($current === '' ? '' : "\n\n") . $block . "\n";
        }
        if ($updated === null || @file_put_contents($htaccess, $updated) === false) {
            return ['synced' => false, 'message' => 'Could not write ' . $htaccess . '; redirects still work via redirect.php.'];
        }
        return ['synced' => true, 'message' => 'Apache .htaccess updated (' . count($this->read()) . ' redirect(s)).'];
    }

    /**
     * Normalize a source path: site-relative, starts with "/", no query or
     * fragment, no whitespace/control characters. Throws on invalid input.
     */
    public static function normalizeFrom(string $from): string
    {
        $from = trim($from);
        if ($from === '') {
            throw new Exception('Source path is required');
        }
        // Accept a full URL and keep only its path
        if (preg_match('#^https?://#i', $from)) {
            $from = (string)(parse_url($from, PHP_URL_PATH) ?? '');
        }
        if ($from === '' || $from[0] !== '/') {
            $from = '/' . $from;
        }
        self::assertCleanValue($from, 'Source path');
        if (strpos($from, '//') === 0) {
            throw new Exception('Source path must be a site-relative path');
        }
        if (strpos($from, '..') !== false) {
            throw new Exception('Source path must not contain ".."');
        }
        // Drop query/fragment — matching is path-based
        $from = preg_replace('/[?#].*$/', '', $from);
        return $from === '' ? '/' : $from;
    }

    /**
     * Normalize a target: a site-relative path starting with "/" or an
     * absolute http(s) URL. Rejects protocol-relative ("//host") targets and
     * anything that could inject headers. Throws on invalid input.
     */
    public static function normalizeTo(string $to): string
    {
        $to = trim($to);
        if ($to === '') {
            throw new Exception('Target is required');
        }
        self::assertCleanValue($to, 'Target');
        if (preg_match('#^https?://#i', $to)) {
            if (filter_var($to, FILTER_VALIDATE_URL) === false) {
                throw new Exception('Target URL is not a valid http(s) URL');
            }
            return $to;
        }
        if ($to[0] !== '/') {
            $to = '/' . $to;
        }
        if (strpos($to, '//') === 0) {
            throw new Exception('Protocol-relative targets are not allowed');
        }
        return $to;
    }

    // --- internals -------------------------------------------------------

    private static function assertCleanValue(string $value, string $label): void
    {
        if (preg_match('/[\s\x00-\x1f\x7f]/', $value)) {
            throw new Exception($label . ' must not contain spaces or control characters');
        }
    }

    /** Follow $start through $map; throw if the chain revisits any node. */
    private function assertNoLoop(string $start, array $map): void
    {
        $seen = [$start => true];
        $current = $map[$start];
        for ($i = 0; $i < self::MAX_CHAIN_HOPS; $i++) {
            if (!isset($map[$current])) return; // chain leaves the managed set
            if (isset($seen[$current])) {
                throw new Exception('This redirect would create a loop (' . $current . ' chains back onto itself)');
            }
            $seen[$current] = true;
            $current = $map[$current];
        }
        throw new Exception('Redirect chain is too long (max ' . self::MAX_CHAIN_HOPS . ' hops)');
    }

    private function read(): array
    {
        if (!is_file($this->file)) return [];
        $raw = @file_get_contents($this->file);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($data)) return [];
        $out = [];
        foreach ($data as $r) {
            if (!is_array($r) || empty($r['from']) || empty($r['to'])) continue;
            $out[] = [
                'from' => (string)$r['from'],
                'to' => (string)$r['to'],
                'code' => in_array((int)($r['code'] ?? 301), [301, 302], true) ? (int)$r['code'] : 301,
                'created_at' => (string)($r['created_at'] ?? ''),
            ];
        }
        return $out;
    }

    private function write(array $items): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new Exception('Settings directory is not writable: ' . $dir);
        }
        if (!is_file($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "Require all denied\n"); }
        $tmp = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || @file_put_contents($tmp, $json) === false) {
            @unlink($tmp);
            throw new Exception('Cannot write redirects file — check that ' . $dir . ' is writable');
        }
        if (!@rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new Exception('Cannot replace redirects file');
        }
        @chmod($this->file, 0664);
    }
}
