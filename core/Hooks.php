<?php
/**
 * Hooks - WordPress-style actions and filters for per-site customization.
 *
 * Two verbs, one registry, zero magic:
 *   Hooks::on('post.published', fn($collectionId, $slug, $post) => ...);
 *   Hooks::filter('render.post_content', fn($html, $post) => $html . '...');
 *
 * Actions are fired with Hooks::do(event, ...args) - every callback runs,
 * return values are ignored. Filters run through Hooks::apply(event, value,
 * ...args) - each callback receives the current value plus the extra args
 * and returns the (possibly modified) value for the next one.
 *
 * A callback that throws never breaks the engine: the exception is logged
 * via error_log() and the pipeline continues (for filters, the value passes
 * through unchanged past the failing callback).
 *
 * Sites register their callbacks in {root_dir}/theme/hooks.php, loaded once
 * by Hooks::boot() from every engine entry point (public renderer, MCP
 * endpoint, subscribe/redirect endpoints, admin). The engine stays a clean
 * git checkout; the site repo carries the hooks.
 *
 * The full event list with signatures and examples lives in docs/hooks.md.
 */
class Hooks
{
    /** @var array<string, array<int, array{priority:int, seq:int, cb:callable}>> */
    private static array $callbacks = [];
    private static int $seq = 0;
    private static bool $booted = false;

    /**
     * Register a callback for an event. Lower priority runs first;
     * callbacks sharing a priority run in registration order.
     */
    public static function on(string $event, callable $cb, int $priority = 10): void
    {
        self::$callbacks[$event][] = ['priority' => $priority, 'seq' => self::$seq++, 'cb' => $cb];
    }

    /** Alias of on() - reads better when registering a filter. */
    public static function filter(string $event, callable $cb, int $priority = 10): void
    {
        self::on($event, $cb, $priority);
    }

    /** Fire an action: run every callback with $args, ignore return values. */
    public static function do(string $event, ...$args): void
    {
        foreach (self::sorted($event) as $entry) {
            try {
                ($entry['cb'])(...$args);
            } catch (Throwable $e) {
                error_log('Hooks: callback for "' . $event . '" failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Run a filter chain: each callback receives ($value, ...$args) and
     * returns the value handed to the next callback. A throwing callback
     * is skipped (logged) and the value passes through unchanged.
     */
    public static function apply(string $event, $value, ...$args)
    {
        foreach (self::sorted($event) as $entry) {
            try {
                $value = ($entry['cb'])($value, ...$args);
            } catch (Throwable $e) {
                error_log('Hooks: filter for "' . $event . '" failed: ' . $e->getMessage());
            }
        }
        return $value;
    }

    /**
     * One-time bootstrap: initialise the Theme resolver (when the Theme
     * class ships) and load the site's {root_dir}/theme/hooks.php if it
     * exists. Idempotent and never fatal - a broken site hooks file is
     * logged and the engine keeps running without it.
     */
    public static function boot(string $rootDir, string $cmsDir = ''): void
    {
        if (self::$booted) return;
        self::$booted = true;

        $themeFile = __DIR__ . '/Theme.php';
        if (is_file($themeFile)) {
            try {
                require_once $themeFile;
                if (class_exists('Theme') && method_exists('Theme', 'init')) {
                    Theme::init($rootDir, $cmsDir);
                }
            } catch (Throwable $e) {
                error_log('Hooks: Theme::init failed: ' . $e->getMessage());
            }
        }

        $rootDir = rtrim($rootDir, '/');
        if ($rootDir === '') return;
        $siteHooks = $rootDir . '/theme/hooks.php';
        if (is_file($siteHooks)) {
            try {
                require $siteHooks;
            } catch (Throwable $e) {
                error_log('Hooks: site theme/hooks.php failed: ' . $e->getMessage());
            }
        }
    }

    /** Callbacks for an event, priority ascending, stable within a priority. */
    private static function sorted(string $event): array
    {
        $list = self::$callbacks[$event] ?? [];
        if ($list === []) return [];
        usort($list, function ($a, $b) {
            return ($a['priority'] <=> $b['priority']) ?: ($a['seq'] <=> $b['seq']);
        });
        return $list;
    }
}
