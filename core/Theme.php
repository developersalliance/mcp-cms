<?php
/**
 * Theme — site-override resolver for every file the engine renders.
 *
 * Chain (first hit wins):
 *   1. {site}/theme/{relPath}   — the install's own override
 *   2. {engine}/{relPath}       — the shipped default
 *
 * init() is called once by the engine bootstraps with the site root and the
 * engine directory. Every method degrades gracefully when init() was never
 * called (e.g. a template rendered from a bare test harness): the engine
 * directory falls back to the parent of core/ and no site overrides apply.
 *
 * partial() is the surgical override unit: default collection templates
 * include their building blocks via Theme::partial('post-row', [...]), so a
 * site can drop a single replacement file at
 * {site}/theme/collection-templates/partials/post-row.php and keep receiving
 * engine improvements to everything else. See docs/theming.md.
 */

class Theme
{
    private static ?string $rootDir = null;
    private static ?string $cmsDir = null;

    /**
     * @param string $rootDir Site root (holds theme/)
     * @param string $cmsDir  Engine directory (holds collection-templates/, core/, ...)
     */
    public static function init(string $rootDir, string $cmsDir): void
    {
        self::$rootDir = rtrim($rootDir, '/');
        self::$cmsDir = rtrim($cmsDir, '/');
    }

    /**
     * Resolve a relative path through the theme chain.
     *
     * @return string|null Absolute path of the winning file, or null when
     *                     neither the site override nor the engine default
     *                     exists (or $relPath attempts traversal).
     */
    public static function resolve(string $relPath): ?string
    {
        $relPath = ltrim($relPath, '/');
        if ($relPath === '' || strpos($relPath, '..') !== false || strpos($relPath, "\0") !== false) {
            return null;
        }
        if (self::$rootDir !== null) {
            $candidate = self::$rootDir . '/theme/' . $relPath;
            if (is_file($candidate)) return $candidate;
        }
        $cmsDir = self::$cmsDir ?? dirname(__DIR__);
        $candidate = $cmsDir . '/' . $relPath;
        return is_file($candidate) ? $candidate : null;
    }

    /**
     * Render a collection-template partial through the resolver.
     * Resolves collection-templates/partials/{name}.php, extracts $vars into
     * the include scope, and echoes the result in place. Silently a no-op
     * when the partial resolves nowhere — a site can therefore also *remove*
     * a default partial only by overriding it with an empty file, never by
     * deleting the engine copy.
     *
     * @param string $name Partial name without extension, e.g. 'post-row'
     * @param array  $vars Variables the partial may read; partials must not
     *                     rely on anything outside $vars.
     */
    public static function partial(string $name, array $vars = []): void
    {
        $path = self::resolve('collection-templates/partials/' . $name . '.php');
        if ($path === null) return;
        self::renderFile($path, $vars);
    }

    /**
     * Isolated include scope so partials cannot clobber Theme internals and
     * see only their own $vars.
     */
    private static function renderFile(string $__themeFile, array $__themeVars): void
    {
        extract($__themeVars, EXTR_SKIP);
        unset($__themeVars);
        include $__themeFile;
    }
}
