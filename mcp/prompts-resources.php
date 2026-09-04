<?php
/**
 * MCP prompts and resources for the CMS endpoint.
 *
 * Loaded by mcp/index.php when present. Prompts give chat clients (Claude,
 * ChatGPT, Gemini) one-click starting points for the four jobs the CMS is
 * built for; resources expose read-only site inventories the model can
 * pull into context without spending tool calls.
 *
 * Contract (see mcp/index.php dispatch):
 *   mcpPromptsList($context)                       → ['prompts' => [...]]
 *   mcpPromptsGet($context, $name, $arguments)     → ['description' => ..., 'messages' => [...]]
 *   mcpResourcesList($context)                     → ['resources' => [...]]
 *   mcpResourceTemplatesList($context)             → ['resourceTemplates' => [...]]
 *   mcpResourcesRead($context, $uri)               → ['contents' => [...]]
 *
 * $context: config, principal, managers {pageManager, blogManager,
 * authorManager, uploadManager, backupManager}, allowedTools.
 */

// ---------------------------------------------------------------------------
// Prompts
// ---------------------------------------------------------------------------

function mcpPromptDefinitions(): array
{
    return [
        'new_blog_post' => [
            'title' => 'Write a new blog post',
            'description' => 'Draft an article on a topic, pick a picture, save it as a draft post and hand back a preview link. Publishes only after the user confirms.',
            'arguments' => [
                ['name' => 'topic', 'description' => 'What the article is about (one sentence or a working title)', 'required' => true],
                ['name' => 'audience', 'description' => 'Who it is for, e.g. "store owners evaluating Magento"', 'required' => false],
                ['name' => 'tone', 'description' => 'Voice, e.g. "practical, first person, no hype"', 'required' => false],
                ['name' => 'length', 'description' => 'Target length, e.g. "800 words" or "short"', 'required' => false],
            ],
        ],
        'add_picture_to_post' => [
            'title' => 'Add a picture to a post',
            'description' => 'Put an image into an existing post: from a URL the user gives, or from the media library. Sets it as the featured image or inserts it into the body.',
            'arguments' => [
                ['name' => 'slug', 'description' => 'Post slug (see cms://posts or list_posts)', 'required' => true],
                ['name' => 'image_source', 'description' => 'An image URL, or the word "library" to pick from already uploaded media', 'required' => true],
                ['name' => 'placement', 'description' => '"featured" (default), "top", "after heading: …" or "end"', 'required' => false],
            ],
        ],
        'edit_site_copy' => [
            'title' => 'Edit site copy',
            'description' => 'Change text on a page: find the block, edit it as a draft, show a preview, publish on confirmation.',
            'arguments' => [
                ['name' => 'page_hint', 'description' => 'Which page, in plain words ("about page", "homepage hero") or a page_id', 'required' => true],
                ['name' => 'change', 'description' => 'What should change, e.g. "replace the opening hours with Mon–Fri 9–18"', 'required' => true],
            ],
        ],
        'page_seo_review' => [
            'title' => 'Review page SEO',
            'description' => 'Read a page\'s <head> metadata, propose better title / description / Open Graph tags, apply them as a draft and publish on confirmation.',
            'arguments' => [
                ['name' => 'page_id', 'description' => 'Page id, e.g. "about" or "" for the homepage', 'required' => true],
            ],
        ],
    ];
}

function mcpPromptsList(array $context): array
{
    $out = [];
    foreach (mcpPromptDefinitions() as $name => $def) {
        $out[] = [
            'name' => $name,
            'title' => $def['title'],
            'description' => $def['description'],
            'arguments' => $def['arguments'],
        ];
    }
    return ['prompts' => $out];
}

function mcpPromptsGet(array $context, string $name, array $arguments): array
{
    $defs = mcpPromptDefinitions();
    if (!isset($defs[$name])) {
        throw new Exception('Unknown prompt: ' . $name);
    }
    $def = $defs[$name];
    foreach ($def['arguments'] as $arg) {
        if (!empty($arg['required']) && trim((string)($arguments[$arg['name']] ?? '')) === '') {
            throw new Exception('Missing required prompt argument: ' . $arg['name']);
        }
    }
    $site = (string)($context['config']['site_name'] ?? 'the site');
    $base = rtrim((string)($context['config']['base_url'] ?? ''), '/');
    $a = function (string $k, string $fallback = '') use ($arguments): string {
        $v = trim((string)($arguments[$k] ?? ''));
        return $v !== '' ? $v : $fallback;
    };

    $rules = "Rules for every job on {$site}:\n"
        . "- Every write creates a DRAFT. Nothing is live until publish_post / publish_page is called. Always show the preview_url you get back and ask before publishing.\n"
        . "- Post bodies are HTML; if you write markdown pass content_format: \"markdown\". Allowed: headings, paragraphs, lists, links, images with alt text, blockquotes, code, tables, figure/figcaption, YouTube/Vimeo iframes. style attributes and scripts are removed.\n"
        . "- Never invent image URLs. Use list_media for existing pictures or upload_image_from_url for a link the user gives you.\n"
        . "- If a search returns several matches, ask the user which one instead of guessing.";

    switch ($name) {
        case 'new_blog_post':
            $text = "Write a new blog post for {$site}.\n\n"
                . "Topic: " . $a('topic') . "\n"
                . "Audience: " . $a('audience', 'the site\'s usual readers') . "\n"
                . "Tone: " . $a('tone', 'match the existing posts (read one with read_post first)') . "\n"
                . "Length: " . $a('length', 'about 700–1000 words') . "\n\n"
                . "Steps:\n"
                . "1. Call list_posts and read_post on one recent post to match voice and structure. Call list_categories to see the category names in use and list_authors for the author_id.\n"
                . "2. Draft the article in markdown: a strong opening paragraph, 3–5 H2 sections, a short conclusion. Propose a title, a one-sentence excerpt, 3–6 tags and one existing category.\n"
                . "3. Pictures: ask the user for an image link, or run list_media and suggest a fitting existing picture. Use upload_image_from_url for a link; use the returned url as featured_image with a descriptive featured_image_alt.\n"
                . "4. Call create_post with slug, title, excerpt, content (content_format: \"markdown\"), tags, categories, featured_image, featured_image_alt and author_id. Do NOT publish yet.\n"
                . "5. Reply with the preview_url from the response and a two-line summary. Ask: publish now, schedule, or edit?\n"
                . "6. Only after an explicit yes call publish_post (or schedule_post with the date they give).\n\n"
                . $rules;
            break;

        case 'add_picture_to_post':
            $src = $a('image_source');
            $placement = $a('placement', 'featured');
            $text = "Add a picture to the post \"" . $a('slug') . "\" on {$site}.\n\n"
                . "Image source: {$src}\nPlacement: {$placement}\n\n"
                . "Steps:\n"
                . "1. Call read_post with the slug to see the current featured_image and body.\n"
                . (strtolower($src) === 'library'
                    ? "2. Call list_media, pick the 2–3 best candidates by name/alt and ask the user which one to use (show url and thumb_url).\n"
                    : "2. Call upload_image_from_url with url \"{$src}\", a short descriptive name and alt text. It resizes the image and returns url, thumb_url, width and height.\n")
                . "3. If placement is \"featured\": call update_post with featured_image and featured_image_alt. Otherwise insert <figure><img src=\"…\" alt=\"…\" width=\"…\" height=\"…\" loading=\"lazy\"><figcaption>…</figcaption></figure> at the requested spot in the body and call update_post with the full content.\n"
                . "4. Reply with the preview_url and ask whether to publish_post (only if the post is already published does the change need republishing; drafts stay drafts).\n\n"
                . $rules;
            break;

        case 'edit_site_copy':
            $text = "Edit site copy on {$site}.\n\n"
                . "Page: " . $a('page_hint') . "\nChange: " . $a('change') . "\n\n"
                . "Steps:\n"
                . "1. Find the text: call search_blocks with a distinctive phrase from the current copy (or list_pages then list_blocks if the hint is a page). If several blocks match, ask the user which one.\n"
                . "2. Call read_block for the exact page_id and block name and quote the current wording back.\n"
                . "3. Make the change with update_block (keep the surrounding HTML and classes intact; change only the text). If the block is NOT marked custom it is a global block shared by every page — say so and confirm before editing.\n"
                . "4. Reply with the preview_url from the response and a before/after diff of the sentence(s) you changed.\n"
                . "5. On confirmation call publish_page for that page_id.\n\n"
                . $rules;
            break;

        case 'page_seo_review':
            $pid = $a('page_id');
            $text = "Review and improve the SEO metadata of page \"{$pid}\" on {$site}" . ($base !== '' ? " ({$base})" : '') . ".\n\n"
                . "Steps:\n"
                . "1. Call get_page_meta with page_id \"{$pid}\" and read_block on the main content block(s) so the proposal matches what the page actually says.\n"
                . "2. Propose: title (50–60 chars), description (140–160 chars), canonical if missing, og:title / og:description / og:image, twitter card. Explain each change in one line.\n"
                . "3. After the user agrees, call update_page_meta with only the keys that change. It saves a draft.\n"
                . "4. Reply with the preview_url and ask before calling publish_page.\n\n"
                . $rules;
            break;

        default:
            throw new Exception('Unknown prompt: ' . $name);
    }

    return [
        'description' => $def['description'],
        'messages' => [
            ['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]],
        ],
    ];
}

// ---------------------------------------------------------------------------
// Resources
// ---------------------------------------------------------------------------

function mcpResourcesList(array $context): array
{
    $site = (string)($context['config']['site_name'] ?? 'Site');
    return ['resources' => [
        ['uri' => 'cms://pages', 'name' => 'pages', 'title' => "{$site} pages", 'description' => 'Every page id with its public URL, title and whether an unpublished draft exists.', 'mimeType' => 'application/json'],
        ['uri' => 'cms://posts', 'name' => 'posts', 'title' => "{$site} blog posts", 'description' => 'All posts in every collection (draft, scheduled and published) with slug, title, status, date and URL.', 'mimeType' => 'application/json'],
        ['uri' => 'cms://media', 'name' => 'media', 'title' => "{$site} media library", 'description' => 'Uploaded images with url, thumb_url, size and alt text where known.', 'mimeType' => 'application/json'],
        ['uri' => 'cms://categories', 'name' => 'categories', 'title' => "{$site} blog categories", 'description' => 'Category tree of the blog collection (id, slug, name, parent).', 'mimeType' => 'application/json'],
        ['uri' => 'cms://usage-guide', 'name' => 'usage-guide', 'title' => 'How to work this CMS', 'description' => 'Recipes for adding an article, adding a picture, editing site copy and reviewing page SEO, plus the draft/publish rules.', 'mimeType' => 'text/markdown'],
    ]];
}

function mcpResourceTemplatesList(array $context): array
{
    return ['resourceTemplates' => [
        ['uriTemplate' => 'cms://posts/{slug}', 'name' => 'post', 'title' => 'One blog post', 'description' => 'Full post record (metadata + HTML content) for a slug in the blog collection.', 'mimeType' => 'application/json'],
        ['uriTemplate' => 'cms://pages/{page_id}/blocks', 'name' => 'page-blocks', 'title' => 'Blocks of a page', 'description' => 'The editable CMS blocks of one page (name, role, custom flag). Use "index" for the homepage.', 'mimeType' => 'application/json'],
    ]];
}

function mcpResourcesRead(array $context, string $uri): array
{
    $uri = trim($uri);
    $json = function ($data) use ($uri): array {
        return ['contents' => [[
            'uri' => $uri,
            'mimeType' => 'application/json',
            'text' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]]];
    };

    switch (true) {
        case $uri === 'cms://pages':
            return $json(mcpResourcePages($context));
        case $uri === 'cms://posts':
            return $json(mcpResourcePosts($context));
        case $uri === 'cms://media':
            return $json(mcpResourceMedia($context));
        case $uri === 'cms://categories':
            return $json(mcpResourceCategories($context));
        case $uri === 'cms://usage-guide':
            return ['contents' => [[
                'uri' => $uri,
                'mimeType' => 'text/markdown',
                'text' => mcpUsageGuideMarkdown($context),
            ]]];
        case (bool)preg_match('#^cms://posts/([A-Za-z0-9_-]+)$#', $uri, $m):
            $blog = $context['managers']['blogManager'];
            $post = $blog->getPost('blog', $m[1]);
            if (!$post) throw new Exception('Unknown resource: ' . $uri . ' (no such post)');
            return $json($post);
        case (bool)preg_match('#^cms://pages/([^/]*)/blocks$#', $uri, $m):
            return $json(mcpResourcePageBlocks($context, rawurldecode($m[1])));
        default:
            throw new Exception('Unknown resource: ' . $uri);
    }
}

/** Public URL of a page id (index/"" → site root). */
function mcpResourcePageUrl(array $context, string $id): string
{
    $base = rtrim((string)($context['config']['base_url'] ?? ''), '/');
    $id = trim($id, '/');
    if ($id === '' || $id === 'index') return $base . '/';
    return $base . '/' . $id . '/';
}

function mcpResourcePages(array $context): array
{
    $pm = $context['managers']['pageManager'];
    $out = [];
    foreach ($pm->listPages() as $p) {
        $id = (string)($p['id'] ?? '');
        $title = null;
        if (!empty($p['path']) && is_file($p['path'])) {
            $head = @file_get_contents($p['path'], false, null, 0, 65536);
            if ($head !== false && preg_match('/<title[^>]*>(.*?)<\/title>/is', $head, $tm)) {
                $title = trim(html_entity_decode(strip_tags($tm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }
        $draftId = ($id === 'index') ? '' : $id;
        $out[] = [
            'id' => $id,
            'url' => mcpResourcePageUrl($context, $id),
            'title' => $title,
            'has_draft' => $pm->hasDraft($draftId) || ($draftId === '' && $pm->hasDraft('index')),
        ];
    }
    return $out;
}

function mcpResourcePosts(array $context): array
{
    $blog = $context['managers']['blogManager'];
    $base = rtrim((string)($context['config']['base_url'] ?? ''), '/');
    $out = [];
    foreach ($blog->getCollections() as $collection) {
        $cid = (string)($collection['id'] ?? '');
        if ($cid === '') continue;
        $basePath = trim((string)($collection['base_path'] ?? $cid), '/');
        foreach ($blog->listPosts($cid) as $post) {
            $out[] = [
                'collection_id' => $cid,
                'slug' => $post['slug'] ?? '',
                'title' => $post['title'] ?? '',
                'status' => $post['status'] ?? 'draft',
                'published_at' => $post['published_at'] ?? null,
                'url' => $base . '/' . $basePath . '/' . ($post['slug'] ?? '') . '/',
            ];
        }
    }
    return $out;
}

function mcpResourceMedia(array $context): array
{
    $config = $context['config'];
    $indexFile = __DIR__ . '/../core/MediaIndex.php';
    if (is_file($indexFile)) {
        require_once $indexFile;
        if (class_exists('MediaIndex')) {
            try {
                if (method_exists('MediaIndex', 'all')) {
                    $idx = new MediaIndex((string)($config['cms_dir'] ?? dirname(__DIR__)));
                    if (method_exists($idx, 'reconcile')) { try { $idx->reconcile(rtrim((string)$config['root_dir'], '/') . '/' . trim((string)($config['uploads_dir'] ?? 'assets/content/'), '/')); } catch (Throwable $e) {} }
                    $all = $idx->all();
                    if (is_array($all)) return array_values($all);
                }
            } catch (Throwable $e) {
                // fall through to the directory scan
            }
        }
    }

    $root = rtrim((string)($config['root_dir'] ?? ''), '/');
    $uploads = trim((string)($config['uploads_dir'] ?? 'assets/content/'), '/');
    $dir = $root . '/' . $uploads;
    if (!is_dir($dir)) return [];

    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $ext = strtolower($f->getExtension());
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) continue;
        $rel = ltrim(str_replace($root, '', $f->getPathname()), '/');
        $files[$rel] = $f;
    }
    $out = [];
    foreach ($files as $rel => $f) {
        $name = $f->getBasename('.' . $f->getExtension());
        if (str_ends_with($name, '-thumb')) continue; // listed under their full-size sibling
        $thumbRel = null;
        foreach (['jpg', 'jpeg', 'png', 'webp', 'gif'] as $tExt) {
            $cand = dirname($rel) . '/' . $name . '-thumb.' . $tExt;
            if (isset($files[$cand])) { $thumbRel = $cand; break; }
        }
        $size = @getimagesize($f->getPathname());
        $out[] = [
            'name' => $f->getFilename(),
            'url' => '/' . $rel,
            'thumb_url' => $thumbRel ? '/' . $thumbRel : '/' . $rel,
            'width' => $size[0] ?? null,
            'height' => $size[1] ?? null,
            'bytes' => $f->getSize(),
            'modified' => date('c', $f->getMTime()),
            'alt' => null,
        ];
    }
    usort($out, fn($a, $b) => strcmp($b['modified'], $a['modified']));
    return $out;
}

function mcpResourceCategories(array $context): array
{
    require_once __DIR__ . '/../core/CategoryManager.php';
    $cm = new CategoryManager((string)$context['config']['cms_dir']);
    $read = $cm->read('blog');
    $list = is_array($read['list'] ?? null) ? $read['list'] : (is_array($read) ? $read : []);
    $out = [];
    foreach ($list as $c) {
        if (!is_array($c)) continue;
        $name = $c['name'] ?? '';
        if (is_array($name)) $name = (string)($name['default'] ?? reset($name) ?? '');
        $out[] = [
            'id' => $c['id'] ?? null,
            'slug' => $c['slug'] ?? null,
            'name' => (string)$name,
            'parent_id' => $c['parent_id'] ?? null,
            'sort_order' => $c['sort_order'] ?? 0,
        ];
    }
    return $out;
}

function mcpResourcePageBlocks(array $context, string $pageId): array
{
    if ($pageId === '/' || $pageId === 'index') $pageId = '';
    $pm = $context['managers']['pageManager'];
    $path = $pm->getPagePath($pageId);
    if (!$path) throw new Exception('Unknown resource: page "' . $pageId . '" not found');
    require_once __DIR__ . '/../core/BlockParser.php';
    $parser = new BlockParser();
    $blocks = [];
    foreach ($parser->parseBlocks($path) as $b) {
        $blocks[] = [
            'name' => $b['name'] ?? '',
            'role' => $b['role'] ?? '',
            'custom' => (bool)($b['custom'] ?? false),
        ];
    }
    return ['page_id' => $pageId === '' ? 'index' : $pageId, 'url' => mcpResourcePageUrl($context, $pageId), 'blocks' => $blocks];
}

function mcpUsageGuideMarkdown(array $context): string
{
    $site = (string)($context['config']['site_name'] ?? 'this site');
    return <<<MD
# Working {$site} through MCP

## Rules
- Every write creates a **draft**. Nothing is live until `publish_post` / `publish_page`. Show the `preview_url` you get back and ask before publishing.
- Post bodies are HTML. Write markdown and pass `content_format: "markdown"`. Allowed: headings, paragraphs, lists, links, images (with alt), blockquote, code, tables, figure/figcaption, YouTube/Vimeo iframes. `style` attributes and scripts are stripped.
- Never invent image URLs. Use `list_media` for existing pictures or `upload_image_from_url` for a link the user gives you.
- When a search returns several matches, ask the user which one.
- Homepage `page_id` is `""` (or `index`).

## Add an article
1. `list_posts` + `read_post` on a recent post to match voice; `list_categories`, `list_authors`.
2. Draft in markdown; propose title, excerpt, tags, one existing category.
3. Picture: `list_media` or `upload_image_from_url` → use `url` as `featured_image` with `featured_image_alt`.
4. `create_post` (slug, title, excerpt, content + `content_format: "markdown"`, tags, categories, featured_image, author_id). Return `preview_url`.
5. On confirmation: `publish_post` or `schedule_post`.

## Add a picture to a post
1. `read_post` (slug).
2. `upload_image_from_url` (URL from the user) or `list_media` (pick together with the user).
3. `update_post` with `featured_image` / `featured_image_alt`, or with the body containing `<figure><img …><figcaption>…</figcaption></figure>`.
4. Return `preview_url`; republish with `publish_post` only if the post was already live.

## Edit site copy
1. `search_blocks` with a distinctive phrase (or `list_pages` → `list_blocks`).
2. `read_block` and quote the current wording.
3. `update_block` changing only the text; blocks not marked `custom` are global (shared by all pages) — confirm first.
4. Return `preview_url` + before/after; `publish_page` on confirmation.

## Review page SEO
1. `get_page_meta` + `read_block` on the main content.
2. Propose title (50–60 chars), description (140–160), canonical, og:*, twitter card.
3. `update_page_meta` with only the changed keys → draft; `publish_page` on confirmation.

## Resources
`cms://pages`, `cms://posts`, `cms://media`, `cms://categories`, `cms://posts/{slug}`, `cms://pages/{page_id}/blocks`.
MD;
}
