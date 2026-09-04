#!/usr/bin/env php
<?php
/**
 * Migrate legacy blog posts to the canonical category model.
 *
 * Legacy shapes handled:
 *   - "category": "AI"                      (developers-alliance.com style)
 *   - "categories": ["News", "Guides"]     (bare strings from old MCP clients)
 *
 * Result: categories = [{id, slug, name_snapshot}] with the category created
 * in content/<collection>/_categories.json when missing; the derived
 * `category` string is kept so old themes keep rendering. Idempotent.
 *
 * Usage:
 *   php tests/migrate-legacy-categories.php <cms_dir> [--collection=blog] [--dry-run]
 *   e.g. php tests/migrate-legacy-categories.php /var/www/developers-alliance/cms-src --dry-run
 *
 * Run as the web user (www-data) so the rewritten JSON stays writable by the CMS.
 */

$args = array_slice($argv, 1);
$cmsDir = null; $collection = null; $dryRun = false;
foreach ($args as $a) {
    if ($a === '--dry-run') $dryRun = true;
    elseif (str_starts_with($a, '--collection=')) $collection = substr($a, 13);
    elseif ($cmsDir === null) $cmsDir = rtrim($a, '/');
}
if ($cmsDir === null || !is_dir($cmsDir . '/content')) {
    fwrite(STDERR, "Usage: php tests/migrate-legacy-categories.php <cms_dir> [--collection=blog] [--dry-run]\n");
    exit(2);
}

$configFile = $cmsDir . '/config/config.php';
$config = is_file($configFile) ? require $configFile : [];
$rootDir = $config['root_dir'] ?? dirname($cmsDir);

require_once $cmsDir . '/core/BlogManager.php';
require_once $cmsDir . '/core/CategoryManager.php';
$blog = new BlogManager($rootDir, $cmsDir);
$cm = new CategoryManager($cmsDir);

$collections = $collection ? [['id' => $collection]] : $blog->getCollections();
$touched = 0; $skipped = 0; $created = [];

foreach ($collections as $col) {
    $cid = $col['id'];
    $dir = $cmsDir . '/content/' . $cid;
    if (!is_dir($dir)) continue;
    $before = array_column($cm->list($cid), 'slug');
    foreach (glob($dir . '/*.json') as $file) {
        if (str_starts_with(basename($file), '_')) continue;
        $raw = json_decode((string)file_get_contents($file), true);
        if (!is_array($raw)) continue;
        $cats = $raw['categories'] ?? [];
        $legacy = isset($raw['category']) && is_string($raw['category']) ? trim($raw['category']) : '';
        $needs = false;
        if (!is_array($cats)) $needs = true;
        else foreach ($cats as $c) { if (!is_array($c) || !isset($c['id'], $c['slug'], $c['name_snapshot']) || $c['id'] === null) { $needs = true; break; } }
        if (!$needs && $cats === [] && $legacy !== '') $needs = true;
        if (!$needs && ($raw['category'] ?? null) === ($cats[0]['name_snapshot'] ?? '')) { $skipped++; continue; }
        if (!$needs) { // only derived `category` missing
            $raw['category'] = $cats[0]['name_snapshot'] ?? '';
        } else {
            $refs = is_array($cats) ? $cats : [];
            if ($refs === [] && $legacy !== '') $refs = [$legacy];
            $resolved = $dryRun
                ? $blog->resolveCategories($cid, $refs, false)
                : $blog->resolveCategories($cid, $refs, true);
            $raw['categories'] = $resolved;
            $raw['category'] = $resolved[0]['name_snapshot'] ?? $legacy;
        }
        $touched++;
        $label = basename($file, '.json');
        $names = implode(', ', array_map(fn($c) => $c['name_snapshot'] . ($c['id'] === null ? ' (would create)' : ''), $raw['categories'] ?? []));
        echo ($dryRun ? '[dry-run] ' : '') . "{$cid}/{$label}: categories → [{$names}] category → \"{$raw['category']}\"\n";
        if (!$dryRun) {
            $json = json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (file_put_contents($file, $json) === false) {
                fwrite(STDERR, "FAILED to write {$file}\n");
                exit(1);
            }
        }
    }
    $after = array_column($cm->list($cid), 'slug');
    $created = array_merge($created, array_diff($after, $before));
}

echo "\nPosts rewritten: {$touched}, already canonical: {$skipped}, categories created: " . (count($created) ? implode(', ', $created) : '0') . ($dryRun ? " (dry run — nothing written)" : '') . "\n";
exit(0);
