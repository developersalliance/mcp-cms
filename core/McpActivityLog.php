<?php
/**
 * McpActivityLog — append-only JSON-lines log of MCP tool calls.
 *
 * One line per call: {ts, ip, principal, client, tool, target, ok, error, ms}
 * File: {cms_dir}/logs/mcp-activity.jsonl (rotated to .1 at 2 MB).
 * Read back with McpActivityLog::tail($config, 50) for the admin viewer.
 */

class McpActivityLog
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    public static function path(array $config): string
    {
        return rtrim((string)($config['cms_dir'] ?? dirname(__DIR__)), '/') . '/logs/mcp-activity.jsonl';
    }

    /**
     * @param array $entry keys: principal, client, tool, target, ok, error, ms, args_summary
     */
    public static function record(array $config, array $entry): void
    {
        $path = self::path($config);
        $dir = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        if (!is_file($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "Require all denied\n"); }
        $line = json_encode(array_merge([
            'ts' => date('c'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ], $entry), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($line === false || $line === '') {
            $line = json_encode(['ts' => date('c'), 'tool' => (string)($entry['tool'] ?? '?'), 'ok' => $entry['ok'] ?? null, 'error' => 'log entry could not be encoded']);
        }
        if (is_file($path) && filesize($path) > self::MAX_BYTES) {
            @rename($path, $path . '.1');
        }
        @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /** Last $n entries, newest first. */
    public static function tail(array $config, int $n = 50): array
    {
        $path = self::path($config);
        if (!is_file($path)) return [];
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $out = [];
        foreach (array_reverse(array_slice($lines, -$n)) as $l) {
            $d = json_decode($l, true);
            if (is_array($d)) $out[] = $d;
        }
        return $out;
    }

    /** Short, safe description of the call target from its arguments. */
    public static function summarizeArgs(array $args): string
    {
        $keys = ['page_id', 'slug', 'title', 'name', 'path', 'filename', 'url', 'collection_id', 'block_name', 'author_id', 'id_or_slug', 'timestamp'];
        $parts = [];
        foreach ($keys as $k) {
            if (isset($args[$k]) && is_scalar($args[$k])) {
                $parts[] = $k . '=' . mb_substr((string)$args[$k], 0, 80);
            }
        }
        return implode(' ', $parts);
    }
}
