#!/usr/bin/env php
<?php
/**
 * Drift doctor for site theme overrides. Run from the engine dir:
 *   php tests/theme-diff.php [siteRoot]
 * siteRoot defaults to the parent of the engine dir (the usual install layout,
 * where the engine is a checkout or symlink inside the site root).
 *
 * For every file under {siteRoot}/theme/ it prints the override path, the
 * engine file it shadows, both mtimes and md5s, and flags:
 *   DRIFT                     the engine default is newer than the override
 *   DEFAULT CHANGED since copy  the override carries an '@default-md5:<hash>'
 *                             marker (first 3 lines) that no longer matches
 *                             the engine file's md5
 * Plain text output; always exits 0 (informational, never a build gate).
 */

$engineDir = dirname(__DIR__);
$siteRoot  = rtrim($argv[1] ?? dirname($engineDir), '/');
$themeDir  = $siteRoot . '/theme';

echo "Engine:    $engineDir\n";
echo "Site root: $siteRoot\n";

if (!is_dir($themeDir)) {
    echo "No theme/ directory at $themeDir — nothing overridden.\n";
    exit(0);
}

$overrides = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($themeDir, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $file) {
    if ($file->isFile()) $overrides[] = $file->getPathname();
}
sort($overrides);

if (!$overrides) {
    echo "theme/ directory is empty — nothing overridden.\n";
    exit(0);
}

$shadowed = 0;
$siteOnly = 0;
$drift    = 0;

foreach ($overrides as $overridePath) {
    $relPath    = substr($overridePath, strlen($themeDir) + 1);
    $enginePath = $engineDir . '/' . $relPath;

    echo "\n$relPath\n";
    echo "  override: $overridePath\n";
    echo "            mtime " . date('Y-m-d H:i:s', filemtime($overridePath))
        . "  md5 " . md5_file($overridePath) . "\n";

    if (!is_file($enginePath)) {
        $siteOnly++;
        echo "  engine:   (no engine counterpart — site-only file)\n";
        continue;
    }

    $shadowed++;
    $engineMd5 = md5_file($enginePath);
    echo "  engine:   $enginePath\n";
    echo "            mtime " . date('Y-m-d H:i:s', filemtime($enginePath))
        . "  md5 " . $engineMd5 . "\n";

    $flags = [];
    if (filemtime($enginePath) > filemtime($overridePath)) {
        $flags[] = 'DRIFT (engine default is newer than the override)';
    }

    // '@default-md5:<hash>' marker: the engine default's md5 at copy time,
    // stamped into one of the override's first 3 lines.
    $head = '';
    $fh = fopen($overridePath, 'r');
    if ($fh) {
        for ($i = 0; $i < 3 && ($line = fgets($fh)) !== false; $i++) $head .= $line;
        fclose($fh);
    }
    if (preg_match('/@default-md5:\s*([0-9a-f]{32})/i', $head, $m)) {
        if (strtolower($m[1]) !== $engineMd5) {
            $flags[] = 'DEFAULT CHANGED since copy (marker ' . $m[1] . ' vs engine ' . $engineMd5 . ')';
        } else {
            $flags[] = 'marker matches engine default';
        }
    }

    if ($flags) {
        foreach ($flags as $f) {
            if (strpos($f, 'DRIFT') === 0 || strpos($f, 'DEFAULT CHANGED') === 0) $drift++;
            echo "  ** $f\n";
        }
    } else {
        echo "  ok\n";
    }
}

echo "\n" . count($overrides) . " override file(s): $shadowed shadow an engine file, "
    . "$siteOnly site-only, $drift flag(s) raised.\n";
exit(0);
