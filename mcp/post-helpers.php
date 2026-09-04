<?php
/**
 * Helpers shared by the blog-post MCP tools (create_post, update_post,
 * read_post, list_posts, category + revision tools). Kept out of
 * handlers.php so the dispatch table stays a thin map.
 */

/** Flatten seo.locales.default → seo for tool responses. */
function mcpPostSeoFlat(array $post): array
{
    $seo = $post['seo'] ?? [];
    if (isset($seo['locales']) && is_array($seo['locales'])) {
        $seo = $seo['locales']['default'] ?? [];
    }
    return is_array($seo) ? $seo : [];
}

/**
 * Merge caller-supplied SEO fields into the editor's storage shape
 * (seo.locales.default.{title,description,og_image,og_image_alt,canonical,json_ld}).
 * Only provided keys change; empty strings clear a key.
 */
function mcpPostSeoForWrite($incoming, $existing): array
{
    $current = [];
    if (is_array($existing)) {
        $current = isset($existing['locales']) && is_array($existing['locales'])
            ? ($existing['locales']['default'] ?? [])
            : $existing;
    }
    if (!is_array($current)) $current = [];
    if (is_array($incoming)) {
        // accept either flat or already-nested input
        if (isset($incoming['locales']['default']) && is_array($incoming['locales']['default'])) {
            $incoming = $incoming['locales']['default'];
        }
        $allowed = ['title', 'description', 'og_image', 'og_image_alt', 'canonical', 'json_ld', 'keywords'];
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $incoming)) continue;
            $v = $incoming[$k];
            if ($k === 'json_ld') {
                if (is_string($v) && trim($v) !== '') {
                    $decoded = json_decode($v, true);
                    if (!is_array($decoded)) throw new Exception('seo.json_ld must be a JSON object/array or a JSON string');
                    $v = $decoded;
                }
                if (is_array($v) && $v !== [] && !array_is_list($v)) $v = [$v];
            }
            if ($v === '' || $v === null || $v === []) {
                unset($current[$k]);
            } else {
                $current[$k] = $v;
            }
        }
    }
    return ['locales' => ['default' => $current]];
}

/** preview_url (admin, needs login) and public_url (when published). */
function mcpPostUrls(array $config, $blogManager, string $collectionId, array $post): array
{
    $base = rtrim((string)($config['base_url'] ?? ''), '/');
    $collection = $blogManager->getCollection($collectionId) ?? ['base_path' => $collectionId];
    $slug = (string)($post['slug'] ?? '');
    $out = [
        'preview_url' => $base . '/cms/admin/blog-preview.php?collection=' . rawurlencode($collectionId) . '&slug=' . rawurlencode($slug),
    ];
    if (($post['status'] ?? '') === 'published') {
        $out['public_url'] = $base . '/' . trim((string)($collection['base_path'] ?? $collectionId), '/') . '/' . $slug . '/';
    }
    return $out;
}

/** What an LLM should do next after a write, in one sentence. */
function mcpPostNextSteps(array $post): string
{
    $status = $post['status'] ?? 'draft';
    if ($status === 'published') return 'The post is live. Use update_post to change it; changes to published posts go live immediately.';
    if ($status === 'scheduled') return 'The post is scheduled; it publishes automatically at scheduled_at. Call publish_post to publish now.';
    return 'The post is a draft (not visible on the site). Open preview_url to check it, then call publish_post to make it live.';
}

/** Post as returned to clients: canonical categories, flat seo, urls. */
function mcpPostView(array $config, $blogManager, string $collectionId, array $post): array
{
    $post['seo'] = mcpPostSeoFlat($post);
    return array_merge($post, mcpPostUrls($config, $blogManager, $collectionId, $post));
}

/**
 * Apply tool input onto a post array (create or update). Handles
 * content_format (markdown → HTML), category / categories resolution,
 * subtitle, published_at, seo shape. Returns the mutated post.
 */
function mcpApplyPostInput(array $post, array $input, $blogManager, string $collectionId): array
{
    foreach (['title', 'excerpt', 'subtitle', 'author_id', 'featured_image', 'featured_image_alt', 'published_at'] as $field) {
        if (array_key_exists($field, $input) && $input[$field] !== null) {
            $post[$field] = is_scalar($input[$field]) ? (string)$input[$field] : $input[$field];
        }
    }
    if (isset($input['published_at']) && !preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$input['published_at'])) {
        throw new Exception('published_at must be YYYY-MM-DD');
    }
    if (array_key_exists('content', $input) && $input['content'] !== null) {
        $content = (string)$input['content'];
        $format = strtolower((string)($input['content_format'] ?? 'html'));
        if ($format === 'markdown' || $format === 'md') {
            require_once __DIR__ . '/../core/Markdown.php';
            $content = Markdown::toHtml($content);
        } elseif ($format !== 'html') {
            throw new Exception('content_format must be "html" or "markdown"');
        }
        $post['content'] = $content;
    }
    if (isset($input['tags'])) {
        $tags = is_array($input['tags']) ? $input['tags'] : explode(',', (string)$input['tags']);
        $post['tags'] = array_values(array_filter(array_map(fn($t) => trim((string)$t), $tags), fn($t) => $t !== ''));
    }
    if (isset($input['featured'])) $post['featured'] = (bool)$input['featured'];

    // categories: `categories` (list) and/or `category` (single convenience)
    $refs = null;
    if (array_key_exists('categories', $input) && $input['categories'] !== null) {
        $refs = is_array($input['categories']) ? $input['categories'] : [(string)$input['categories']];
    }
    if (isset($input['category']) && trim((string)$input['category']) !== '') {
        $refs = array_merge([(string)$input['category']], $refs ?? []);
    }
    if ($refs !== null) {
        $createMissing = array_key_exists('create_missing', $input) ? (bool)$input['create_missing'] : true;
        $post['categories'] = $blogManager->resolveCategories($collectionId, $refs, $createMissing);
        $post['category'] = $post['categories'][0]['name_snapshot'] ?? '';
    }

    if (array_key_exists('seo', $input) && $input['seo'] !== null) {
        $post['seo'] = mcpPostSeoForWrite($input['seo'], $post['seo'] ?? null);
    }
    return $post;
}

/** Build the common write response. */
function mcpPostWriteResponse(array $config, $blogManager, string $collectionId, array $post, string $message): array
{
    $stripped = $blogManager->getLastStripped();
    $out = [
        'success' => true,
        'collection_id' => $collectionId,
        'slug' => $post['slug'],
        'status' => $post['status'] ?? 'draft',
        'title' => $post['title'] ?? '',
        'categories' => $post['categories'] ?? [],
        'category' => $post['category'] ?? '',
        'message' => $message,
    ];
    $out = array_merge($out, mcpPostUrls($config, $blogManager, $collectionId, $post));
    if ($stripped !== []) {
        $out['stripped'] = $stripped;
        $out['stripped_hint'] = 'The sanitizer removed these tags/attributes. ' . BlogManager::allowlistSummary();
    }
    $out['next_steps'] = mcpPostNextSteps($post);
    return $out;
}

/** Resolve an id-or-slug-or-name to a category record. */
function mcpFindCategory($categoryManager, string $collectionId, string $ref): ?array
{
    $ref = trim($ref);
    if ($ref === '') return null;
    $needle = strtolower($ref);
    foreach ($categoryManager->list($collectionId) as $c) {
        if (strtolower($c['id']) === $needle || strtolower($c['slug']) === $needle || strtolower($categoryManager->displayName($c)) === $needle) {
            return $c;
        }
    }
    return null;
}

/** CategoryManager mutators return {result, list, etag}; unwrap. */
function mcpCategoryRecord($mutateResult): array
{
    return is_array($mutateResult) && isset($mutateResult['result']) && is_array($mutateResult['result']) ? $mutateResult['result'] : (array)$mutateResult;
}

/** Public category record shape for tool responses. */
function mcpCategoryView($categoryManager, array $cat, array $counts): array
{
    $cat = mcpCategoryRecord($cat);
    return [
        'id' => $cat['id'],
        'slug' => $cat['slug'],
        'name' => $categoryManager->displayName($cat),
        'description' => (string)($cat['description'] ?? ''),
        'parent_id' => $cat['parent_id'] ?? null,
        'sort_order' => (int)($cat['sort_order'] ?? 0),
        'post_count' => (int)($counts[$cat['id']] ?? 0),
    ];
}
