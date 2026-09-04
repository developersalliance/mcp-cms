<?php
/**
 * MCP file handlers — chunked AI access to arbitrary text files.
 *
 * Mirrors the page-region pattern (search_in_page / get_page_region /
 * update_page_region) but for files in the web root that the admin's
 * file editor exposes. Same path-safety + backup rules as
 * admin/file-edit.php so manual edits and AI edits share one history.
 *
 * Tools:
 *   list_files(dir?, ext?)
 *   search_in_file(path, query, regex?, case_sensitive?, max_matches?)
 *   read_file(path, start_line?, end_line?, max_chars?)
 *   update_file_region(path, start_line, end_line, old_region, new_region)
 *
 * The model picks its own (start_line, end_line) for both read and update.
 * Optimistic locking via old_region (must exactly match current bytes in
 * the range) prevents stomping concurrent edits — same contract as
 * update_page_region.
 */

require_once __DIR__ . '/../core/BackupManager.php';

if (!function_exists('mcp_file_allowed_exts')) {
    /**
     * Extension allowlist — matches the web-files subset of file-edit.php.
     * Deliberately narrower than the admin file editor (which lets a human
     * touch shell scripts, db dumps, etc.). AI gets the safer subset.
     */
    function mcp_file_allowed_exts(): array
    {
        return [
            'css', 'js', 'mjs', 'ts', 'jsx', 'tsx',
            'html', 'htm', 'php', 'phtml',
            'json', 'xml', 'yml', 'yaml', 'svg',
            'md', 'markdown', 'txt',
        ];
    }
}

if (!function_exists('mcp_file_forbidden_first_segments')) {
    function mcp_file_forbidden_first_segments(): array
    {
        // Mirrors $forbiddenDirs in admin/file-edit.php.
        return ['cms', '.git', 'node_modules', 'vendor'];
    }
}

/**
 * Resolve a user-supplied relative path to an absolute path inside
 * $rootDir, enforcing the same safety rules as admin/file-edit.php.
 *
 * Returns [absPath, relPath, ext] on success, throws on any violation.
 */
function mcpResolveFilePath(string $path, string $rootDir, bool $mustExist = true): array
{
    $path = str_replace(['..', '\\', "\0"], '', trim($path, '/'));
    if ($path === '') {
        throw new Exception('Missing path');
    }

    $segments = array_values(array_filter(explode('/', $path), 'strlen'));
    if (!$segments) {
        throw new Exception('Invalid path');
    }
    if (in_array($segments[0], mcp_file_forbidden_first_segments(), true)) {
        throw new Exception('Access denied to this directory: /' . $segments[0]);
    }
    foreach ($segments as $seg) {
        if ($seg[0] === '.') {
            throw new Exception('Access denied to dotfile: ' . $seg);
        }
    }

    $rootReal = realpath($rootDir);
    if (!$rootReal) {
        throw new Exception('root_dir not found');
    }

    $absPath = $rootDir . '/' . $path;
    $realPath = $mustExist ? realpath($absPath) : $absPath;
    if ($mustExist && (!$realPath || !is_file($realPath))) {
        throw new Exception('File not found or not a regular file');
    }
    $cmpPath = $realPath ?: $absPath;
    if (strpos($cmpPath, $rootReal . DIRECTORY_SEPARATOR) !== 0) {
        throw new Exception('Path escapes root_dir');
    }

    // Refuse the /cms tree even if symlinked in via a different name.
    $cmsReal = realpath($rootDir . '/cms');
    if ($cmsReal && strpos($cmpPath, $cmsReal . DIRECTORY_SEPARATOR) === 0) {
        throw new Exception('Access denied to /cms');
    }

    $ext = strtolower(pathinfo($cmpPath, PATHINFO_EXTENSION));
    if (!in_array($ext, mcp_file_allowed_exts(), true)) {
        throw new Exception('File type not allowed: .' . $ext);
    }

    return [$realPath ?: $absPath, $path, $ext];
}

/**
 * Make a backup of $absPath before any write. Reuses BackupManager if the
 * file IS a known CMS page (so the file edit and block edit share one
 * timeline). Otherwise drops into backups_dir/_file_edits/.
 */
function mcpBackupFileBeforeWrite(string $absPath, $pageManager, array $config): string
{
    if (!file_exists($absPath)) {
        // First-time create — nothing to back up.
        return '';
    }

    $matchedPageId = method_exists($pageManager, 'resolvePageIdByPath')
        ? $pageManager->resolvePageIdByPath($absPath)
        : null;

    if ($matchedPageId !== null) {
        $bm = new BackupManager(
            $config['backups_dir'],
            $config['max_backups_per_page'] ?? 10
        );
        $bm->createBackup($matchedPageId, $absPath);
        return 'page-backup:' . ($matchedPageId === '' ? '/' : $matchedPageId);
    }

    $rootReal = realpath($config['root_dir']);
    $relDir = ltrim(str_replace($rootReal, '', dirname($absPath)), '/\\');
    $bakDir = rtrim($config['backups_dir'], '/') . '/_file_edits' . ($relDir ? '/' . $relDir : '');
    if (!is_dir($bakDir) && !mkdir($bakDir, 0755, true) && !is_dir($bakDir)) {
        throw new Exception('Could not create backup dir: ' . $bakDir);
    }
    $bakFile = $bakDir . '/' . basename($absPath) . '.' . date('YmdHis') . '.bak';
    if (!copy($absPath, $bakFile)) {
        throw new Exception('Backup copy failed: ' . $bakFile);
    }
    return $bakFile;
}

/* -------------------------------------------------------------------------- */
/* Handlers                                                                   */
/* -------------------------------------------------------------------------- */

function handleListFiles(array $input, array $config): array
{
    $rootDir = rtrim($config['root_dir'], '/');
    $dir = trim((string)($input['dir'] ?? ''), '/');
    $extFilter = strtolower(trim((string)($input['ext'] ?? '')));
    $maxResults = max(1, min((int)($input['max'] ?? 200), 1000));

    if ($dir !== '') {
        // Validate dir against the same first-segment forbidden list.
        $segs = explode('/', $dir);
        if (in_array($segs[0], mcp_file_forbidden_first_segments(), true)) {
            return ['success' => false, 'error' => 'Forbidden directory: /' . $segs[0]];
        }
        foreach ($segs as $s) {
            if ($s !== '' && $s[0] === '.') {
                return ['success' => false, 'error' => 'Dotfiles excluded'];
            }
        }
    }

    $baseAbs = $rootDir . ($dir !== '' ? '/' . $dir : '');
    $baseReal = realpath($baseAbs);
    $rootReal = realpath($rootDir);
    if (!$baseReal || !$rootReal || strpos($baseReal, $rootReal) !== 0 || !is_dir($baseReal)) {
        return ['success' => false, 'error' => 'Directory not found or outside root_dir'];
    }

    $allowed = mcp_file_allowed_exts();
    $results = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseReal, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        if (count($results) >= $maxResults) break;
        $abs = $f->getPathname();
        // Skip into forbidden top-level dirs encountered via recursion.
        $rel = ltrim(str_replace($rootReal, '', $abs), '/\\');
        $top = explode('/', $rel, 2)[0];
        if (in_array($top, mcp_file_forbidden_first_segments(), true)) continue;
        // Skip dotpath segments
        foreach (explode('/', $rel) as $seg) {
            if ($seg !== '' && $seg[0] === '.') continue 2;
        }
        if ($f->isFile()) {
            $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) continue;
            if ($extFilter !== '' && $ext !== $extFilter) continue;
            $results[] = [
                'path' => $rel,
                'size' => $f->getSize(),
                'lines' => null,  // cheap: leave to read_file
                'mtime' => date('c', $f->getMTime()),
                'ext' => $ext,
            ];
        }
    }
    usort($results, fn($a, $b) => strcmp($a['path'], $b['path']));
    return ['success' => true, 'count' => count($results), 'files' => $results];
}

function handleReadFile(array $input, array $config): array
{
    try {
        [$abs, $rel, $ext] = mcpResolveFilePath((string)($input['path'] ?? ''), rtrim($config['root_dir'], '/'));
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }

    $maxChars = max(500, min((int)($input['max_chars'] ?? 4000), 20000));
    $startLine = isset($input['start_line']) ? (int)$input['start_line'] : 1;
    $endLine   = isset($input['end_line'])   ? (int)$input['end_line']   : 0;

    $lines = @file($abs, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return ['success' => false, 'error' => 'Could not read file'];
    }
    $total = count($lines);
    if ($startLine < 1) $startLine = 1;
    if ($endLine < 1 || $endLine > $total) $endLine = $total;
    if ($endLine < $startLine) {
        return ['success' => false, 'error' => 'end_line < start_line'];
    }

    $slice = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);
    $content = implode("\n", $slice);
    $truncated = false;
    if (strlen($content) > $maxChars) {
        $content = substr($content, 0, $maxChars);
        $truncated = true;
    }

    return [
        'success'       => true,
        'path'          => $rel,
        'start_line'    => $startLine,
        'end_line'      => $endLine,
        'total_lines'   => $total,
        'truncated'     => $truncated,
        'content'       => $content,
    ];
}

function handleSearchInFile(array $input, array $config): array
{
    try {
        [$abs, $rel] = mcpResolveFilePath((string)($input['path'] ?? ''), rtrim($config['root_dir'], '/'));
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }

    $query = (string)($input['query'] ?? '');
    if ($query === '') {
        return ['success' => false, 'error' => 'Missing query'];
    }
    $isRegex     = !empty($input['regex']);
    $caseSens    = !empty($input['case_sensitive']);
    $maxMatches  = max(1, min((int)($input['max_matches'] ?? 50), 200));

    $lines = @file($abs, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return ['success' => false, 'error' => 'Could not read file'];
    }

    if ($isRegex) {
        // Compile + validate the pattern. We never accept caller-supplied
        // delimiters/flags; the wrapper enforces our own.
        $pattern = '/' . str_replace('/', '\/', $query) . '/' . ($caseSens ? '' : 'i');
        if (@preg_match($pattern, '') === false) {
            return ['success' => false, 'error' => 'Invalid regex'];
        }
    }

    $matches = [];
    foreach ($lines as $i => $line) {
        if (count($matches) >= $maxMatches) break;
        $hit = false;
        if ($isRegex) {
            $hit = (bool)preg_match($pattern, $line);
        } else {
            $hit = $caseSens
                ? (strpos($line, $query) !== false)
                : (stripos($line, $query) !== false);
        }
        if ($hit) {
            $matches[] = [
                'line' => $i + 1,
                'text' => mb_strlen($line) > 240 ? mb_substr($line, 0, 240) . '…' : $line,
            ];
        }
    }

    return [
        'success'  => true,
        'path'     => $rel,
        'query'    => $query,
        'matches'  => $matches,
        'count'    => count($matches),
        'capped'   => count($matches) >= $maxMatches,
    ];
}

function handleUpdateFileRegion(array $input, $pageManager, array $config): array
{
    try {
        [$abs, $rel] = mcpResolveFilePath((string)($input['path'] ?? ''), rtrim($config['root_dir'], '/'));
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }

    $startLine = (int)($input['start_line'] ?? 0);
    $endLine   = (int)($input['end_line']   ?? 0);
    $oldRegion = (string)($input['old_region'] ?? '');
    $newRegion = (string)($input['new_region'] ?? '');

    if ($startLine < 1 || $endLine < $startLine) {
        return ['success' => false, 'error' => 'Invalid line range'];
    }

    $current = @file_get_contents($abs);
    if ($current === false) {
        return ['success' => false, 'error' => 'Could not read file for patch'];
    }
    // Normalize newlines we expect on disk to LF for comparison so a CRLF
    // file doesn't fail a clean LF-encoded patch.
    $currentLf = str_replace("\r\n", "\n", $current);
    $lines = explode("\n", $currentLf);
    $total = count($lines);
    if ($endLine > $total) {
        return ['success' => false, 'error' => "end_line {$endLine} exceeds file length {$total}"];
    }

    $sliceLines = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);
    $currentRegion = implode("\n", $sliceLines);

    // Optimistic lock — if the disk content in the range no longer matches
    // what the model read, refuse the patch and let the model re-read.
    if (rtrim($oldRegion, "\n") !== rtrim($currentRegion, "\n")) {
        return [
            'success' => false,
            'error'   => 'Stale region — file has changed since read. Re-read with read_file and try again.',
            'current_region' => $currentRegion,
        ];
    }

    try {
        $backupRef = mcpBackupFileBeforeWrite($abs, $pageManager, $config);
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Backup failed (refused write): ' . $e->getMessage()];
    }

    // Splice the new region in. Preserve trailing newline of the original
    // file so we don't accidentally chomp a final \n.
    $before = array_slice($lines, 0, $startLine - 1);
    $after  = array_slice($lines, $endLine);
    $newLines = explode("\n", str_replace("\r\n", "\n", $newRegion));
    $rebuilt = implode("\n", array_merge($before, $newLines, $after));
    if (substr($current, -1) === "\n" && substr($rebuilt, -1) !== "\n") {
        $rebuilt .= "\n";
    }

    if (file_put_contents($abs, $rebuilt) === false) {
        return ['success' => false, 'error' => 'Failed to write file'];
    }

    return [
        'success'        => true,
        'path'           => $rel,
        'lines_replaced' => $endLine - $startLine + 1,
        'new_lines'      => count($newLines),
        'backup'         => $backupRef,
        'message'        => 'File region updated (backup created before write).',
    ];
}
