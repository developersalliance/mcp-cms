<?php
/**
 * MCP template handlers — let an AI client "format the blog" by editing the
 * collection templates (the PHP files that render the post list and post
 * detail pages).
 *
 * Tools:
 *   list_templates()                → engine defaults + site overrides, which
 *                                     file is active per collection/kind, and
 *                                     the variable cheatsheet ($post, $author…)
 *   read_template(name, scope?)     → numbered lines of one template
 *   update_template(name, content)  → writes ONLY the site override
 *                                     {root_dir}/theme/collection-templates/<name>.php
 *
 * Resolution mirrors BlogRenderer::getTemplatePath(): the site override
 * ({collection}-{kind} then default-{kind}) wins over the engine default.
 * Engine files under cms/collection-templates are never written by MCP —
 * they are part of the engine git checkout and would be clobbered on the
 * next engine update. update_template creates the override from the engine
 * default when none exists, keeps up to 10 timestamped backups in
 * {cms_dir}/backups/templates/, and refuses content that fails `php -l`.
 */

if (!function_exists('mcpTemplateName')) {
    /** Validate "<collection|default>-<detail|list>" and return [name, kind]. */
    function mcpTemplateName(string $name): array
    {
        $name = trim($name);
        $name = preg_replace('/\.php$/i', '', $name);
        if (!preg_match('/^([a-z0-9][a-z0-9_-]{0,60})-(detail|list)$/i', $name, $m)) {
            throw new Exception('Invalid template name. Use "<collection>-detail", "<collection>-list", "default-detail" or "default-list" (e.g. "blog-detail").');
        }
        return [strtolower($m[1]) . '-' . strtolower($m[2]), strtolower($m[2])];
    }

    function mcpTemplateDirs(array $config): array
    {
        return [
            'site'   => rtrim((string)$config['root_dir'], '/') . '/theme/collection-templates',
            'engine' => rtrim((string)$config['cms_dir'], '/') . '/collection-templates',
        ];
    }

    /** Variables a template can use — same list the admin cheatsheet shows. */
    function mcpTemplateCheatsheet(): array
    {
        return [
            'detail' => [
                '$post' => 'array — the current post: title, content (sanitized HTML, echo raw), excerpt, featured_image, featured_image_alt, slug, published_at, created_at, modified_at, author_id, categories ([{id, slug, name_snapshot}]), tags ([string]), seo.locales.default.{title,description,og_image,…}, _author (resolved author)',
                '$author' => 'array|null — resolved from $post[author_id]: name, bio, avatar',
                '$collection' => 'array — label, base_path, posts_per_page',
                '$siteName' => 'string — site name from config',
                '$baseUrl' => 'string — site base URL from config',
                '$readingTime' => 'int — estimated minutes to read',
            ],
            'list' => [
                '$pagedPosts' => 'array — posts for the current page (same shape as $post in detail templates, _author already resolved)',
                '$pagination' => 'Pagination — getCurrentPage(), getPreviousPage(), getNextPage(), getTotalPages()',
                '$activeFilter' => 'string|null — current ?tag= or ?category= value',
                '$collection' => 'array — label, base_path, posts_per_page',
                '$siteName' => 'string — site name from config',
                '$baseUrl' => 'string — site base URL from config',
            ],
            'notes' => [
                'Templates are plain PHP included by BlogRenderer; echo $post[\'content\'] raw, escape everything else with htmlspecialchars().',
                'Per-post <head> meta (title, description, og:*) is patched in by BlogRenderer after render — keep a normal <head> with <title> and <meta name="description">.',
                'A site override applies to every collection that resolves to it: "blog-detail" for the blog collection, "default-detail" for any collection without its own file.',
            ],
        ];
    }

    function mcpTemplateBackup(string $absPath, array $config): ?string
    {
        if (!is_file($absPath)) return null;
        $dir = rtrim((string)$config['cms_dir'], '/') . '/backups/templates';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new Exception('Could not create template backup directory');
        }
        $base = basename($absPath, '.php');
        $target = $dir . '/' . $base . '.' . date('YmdHis') . '.php';
        if (!@copy($absPath, $target)) {
            throw new Exception('Could not back up the current template');
        }
        // Keep the 10 newest backups per template
        $existing = glob($dir . '/' . $base . '.*.php') ?: [];
        rsort($existing);
        foreach (array_slice($existing, 10) as $old) {
            @unlink($old);
        }
        return 'backups/templates/' . basename($target);
    }

    /** php -l on a temp copy; returns null when clean, else the error text. */
    function mcpTemplateLint(string $content): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cmstpl');
        if ($tmp === false) return null; // cannot lint → don't block
        $tmpPhp = $tmp . '.php';
        @rename($tmp, $tmpPhp);
        file_put_contents($tmpPhp, $content);
        // Under php-fpm / mod_php PHP_BINARY is not a CLI binary; find one and
        // fail CLOSED when no usable interpreter (or exec) is available.
        if (!function_exists('exec')) { @unlink($tmpPhp); return 'Cannot verify template syntax on this server (exec disabled); refusing to write'; }
        $php = null;
        foreach ([PHP_BINDIR . '/php', '/usr/bin/php', '/usr/local/bin/php', 'php'] as $cand) {
            $probe = [];
            $pc = 1;
            @exec(escapeshellarg($cand) . ' -v 2>/dev/null', $probe, $pc);
            if ($pc === 0 && isset($probe[0]) && str_starts_with($probe[0], 'PHP ')) { $php = $cand; break; }
        }
        if ($php === null) { @unlink($tmpPhp); return 'Cannot verify template syntax on this server (no php CLI found); refusing to write'; }
        $out = [];
        $code = 1;
        @exec(escapeshellarg($php) . ' -l ' . escapeshellarg($tmpPhp) . ' 2>&1', $out, $code);
        @unlink($tmpPhp);
        if ($code === 0) return null;
        $msg = trim(implode("\n", $out));
        $msg = str_replace($tmpPhp, '<template>', $msg);
        return $msg !== '' ? $msg : 'PHP syntax check failed';
    }
}

function handleListTemplates(array $input, $blogManager, array $config): array
{
    $dirs = mcpTemplateDirs($config);
    $templates = [];
    foreach (['engine', 'site'] as $scope) {
        $dir = $dirs[$scope];
        if (!is_dir($dir)) continue;
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            if (!preg_match('/^[a-z0-9_-]+-(detail|list)$/i', $name)) continue;
            $templates[] = [
                'name' => $name,
                'scope' => $scope,
                'writable_via_mcp' => $scope === 'site',
                'size' => filesize($file),
                'modified' => date('c', filemtime($file)),
            ];
        }
    }

    // Which file is active per collection and kind (mirrors BlogRenderer)
    $active = [];
    $collections = [];
    try {
        $collections = $blogManager ? $blogManager->getCollections() : [];
    } catch (Throwable $e) {
        $collections = [];
    }
    if (!$collections) $collections = [['id' => 'blog']];
    foreach ($collections as $c) {
        $cid = (string)($c['id'] ?? 'blog');
        foreach (['detail', 'list'] as $kind) {
            $candidates = [
                ['site', $dirs['site'] . '/' . $cid . '-' . $kind . '.php'],
                ['site', $dirs['site'] . '/default-' . $kind . '.php'],
                ['engine', $dirs['engine'] . '/' . $cid . '-' . $kind . '.php'],
                ['engine', $dirs['engine'] . '/default-' . $kind . '.php'],
            ];
            $found = null;
            foreach ($candidates as [$scope, $path]) {
                if (is_file($path)) { $found = ['scope' => $scope, 'name' => basename($path, '.php')]; break; }
            }
            $active[] = ['collection_id' => $cid, 'kind' => $kind, 'template' => $found];
        }
    }

    return [
        'success' => true,
        'templates' => $templates,
        'active' => $active,
        'site_override_dir' => 'theme/collection-templates/',
        'how_to_edit' => 'read_template to see the current markup, then update_template with the full new content. update_template always writes the site override (theme/collection-templates/<name>.php), creating it from the engine default if needed; engine files are never modified.',
        'variables' => mcpTemplateCheatsheet(),
    ];
}

function handleReadTemplate(array $input, array $config): array
{
    try {
        [$name, $kind] = mcpTemplateName((string)($input['name'] ?? ''));
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
    $scope = strtolower((string)($input['scope'] ?? ''));
    $dirs = mcpTemplateDirs($config);
    $path = null;
    $usedScope = null;
    $usedName = $name;
    // Same resolution as BlogRenderer: exact name, then default-<kind>,
    // site override before engine default.
    $names = [$name];
    $defaultName = 'default-' . $kind;
    if ($defaultName !== $name) $names[] = $defaultName;
    $scopes = ($scope === 'site' || $scope === 'engine') ? [$scope] : ['site', 'engine'];
    foreach ($names as $n) {
        foreach ($scopes as $s) {
            $candidate = $dirs[$s] . '/' . $n . '.php';
            if (is_file($candidate)) { $path = $candidate; $usedScope = $s; $usedName = $n; break 2; }
        }
    }
    if ($path === null) {
        return ['success' => false, 'error' => "Template '{$name}' not found" . ($scope ? " in scope '{$scope}'" : '') . '. Call list_templates to see available names.'];
    }
    $content = (string)@file_get_contents($path);
    $maxChars = 60000;
    $truncated = false;
    if (strlen($content) > $maxChars) {
        $content = substr($content, 0, $maxChars);
        $truncated = true;
    }
    $lines = explode("\n", str_replace("\r\n", "\n", $content));
    $numbered = [];
    foreach ($lines as $i => $line) {
        $numbered[] = ($i + 1) . ': ' . $line;
    }
    return [
        'success' => true,
        'name' => $name,
        'resolved_file' => $usedName . '.php',
        'resolved_from_default' => $usedName !== $name,
        'scope' => $usedScope,
        'writable_via_mcp' => $usedScope === 'site',
        'note' => $usedName !== $name
            ? "No '{$name}.php' exists; this is the '{$usedName}.php' file that currently renders it. update_template('{$name}', …) will create a dedicated '{$name}.php' site override."
            : null,
        'line_count' => count($lines),
        'truncated' => $truncated,
        'content' => $content,
        'numbered' => implode("\n", $numbered),
    ];
}

function handleUpdateTemplate(array $input, array $config): array
{
    // Templates are PHP executed on every page view: writing one is code
    // execution. Same owner-controlled gate as update_file_region on .php.
    if (($config['mcp_allow_php_edits'] ?? false) !== true) {
        return [
            'success' => false,
            'error' => 'Editing templates through MCP is disabled because templates are PHP. An owner can enable "Allow MCP to edit PHP files" in Settings (mcp_allow_php_edits). read_template and list_templates still work.',
        ];
    }
    try {
        [$name] = mcpTemplateName((string)($input['name'] ?? ''));
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
    if (!array_key_exists('content', $input) || !is_string($input['content'])) {
        return ['success' => false, 'error' => 'Missing required parameter: content (the full template source)'];
    }
    $content = str_replace("\r\n", "\n", $input['content']);
    if (trim($content) === '') {
        return ['success' => false, 'error' => 'Refusing to write an empty template'];
    }
    if (strlen($content) > 500000) {
        return ['success' => false, 'error' => 'Template exceeds 500 KB'];
    }

    $dirs = mcpTemplateDirs($config);
    $siteDir = $dirs['site'];
    $target = $siteDir . '/' . $name . '.php';
    $enginePath = $dirs['engine'] . '/' . $name . '.php';
    $createdOverride = !is_file($target);

    if ($lintError = mcpTemplateLint($content)) {
        return ['success' => false, 'error' => 'Template not saved — PHP syntax error: ' . $lintError];
    }

    if (!is_dir($siteDir) && !@mkdir($siteDir, 0755, true)) {
        return ['success' => false, 'error' => 'Could not create theme/collection-templates/ under the site root'];
    }

    try {
        // Back up what the site was rendering with (override if present,
        // otherwise the engine default it is about to replace).
        $backup = mcpTemplateBackup(is_file($target) ? $target : $enginePath, $config);
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Backup failed (refused write): ' . $e->getMessage()];
    }

    if (file_put_contents($target, $content, LOCK_EX) === false) {
        return ['success' => false, 'error' => 'Failed to write template'];
    }

    return [
        'success' => true,
        'name' => $name,
        'path' => 'theme/collection-templates/' . $name . '.php',
        'created_override' => $createdOverride,
        'backup' => $backup,
        'message' => ($createdOverride ? 'Site override created' : 'Site override updated') . '. It is live immediately for every collection that resolves to it; open a post page to check the result.',
    ];
}
