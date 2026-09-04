<?php
/**
 * MCP Tools Definition
 *
 * Defines all available MCP tools with their descriptions
 */

function getMCPTools() {
    return [
        'list_pages' => 'List all pages in the CMS',
        'create_page' => 'Create a new page from HTML content',
        'read_page' => 'Read the full content of a page',
        'delete_page' => 'Delete a page permanently',
        'duplicate_page' => 'Duplicate an existing page',
        'publish_page' => 'Publish a draft page to live',
        'discard_draft' => 'Discard a draft and revert to live version',
        'list_blocks' => 'List all blocks in a specific page',
        'read_block' => 'Read the content of a specific block',
        'update_block' => 'Update a block (global blocks sync to all pages)',
        'insert_block' => 'Insert a new block into a page',
        'search_blocks' => 'Search for text across all blocks',
        'find_and_replace_block_content' => 'Find and replace text (global blocks sync to all pages)',
        'search_in_page' => 'Search for text within a specific page',
        'get_page_region' => 'Get a region of page content between markers',
        'update_page_region' => 'Update a region of page content',
        'list_backups' => 'List page-specific backups',
        'restore_backup' => 'Restore a single page from backup',
        'list_global_backups' => 'List global block backups (affects multiple pages)',
        'restore_global_backup' => 'Restore all pages from a global backup',
        'list_posts' => 'List blog posts with optional filters (status, author, tag, category)',
        'create_post' => 'Create a new blog post as JSON draft',
        'read_post' => 'Read a blog post (metadata + content)',
        'update_post' => 'Update blog post content and/or metadata',
        'publish_post' => 'Publish a blog post',
        'unpublish_post' => 'Unpublish a blog post back to draft',
        'delete_post' => 'Delete a blog post permanently',
        'schedule_post' => 'Schedule a post for future publishing',
        'list_authors' => 'List all author profiles',
        'get_author' => 'Get a single author profile',
        'manage_author' => 'Create, update, or delete an author profile',
        'list_files' => 'List editable text files under a directory (with optional ext filter). Whitelisted extensions only — no binaries.',
        'read_file' => 'Read a bounded slice of a text file by line range. Use after list_files / search_in_file. Default cap 4000 chars.',
        'search_in_file' => 'Find text or regex matches in a file. Returns line numbers + short snippets, never the whole file.',
        'update_file_region' => 'Patch a file by line range with optimistic locking. old_region must exactly match current bytes. Auto-creates a backup before writing.',
        'list_templates' => 'List the blog/collection templates (post list + post detail pages), which one is active, and the variables they can use',
        'read_template' => 'Read a collection template (e.g. "blog-detail") with line numbers',
        'update_template' => 'Write the site override of a collection template (theme/collection-templates/<name>.php); syntax-checked and backed up',
        'upload_file' => 'Upload a file to the server',
        'upload_image' => 'Upload and process an image',
        'upload_image_from_url' => 'Fetch an image from a public URL, resize it and add it to the media library',
        'list_media' => 'List / search images in the media library (name, alt, url, size)',
        'update_media' => 'Set the name, alt text or caption of a media library image',
        'delete_media' => 'Delete an image (all sizes) from the media library',
        'generate_image' => 'Generate an image from a text prompt with the configured AI provider and add it to the media library',
        'get_page_meta' => 'Read a page\'s <head> metadata: title, description, keywords, canonical, robots, og:*, twitter:*, JSON-LD, ai-* tags',
        'update_page_meta' => 'Update one or more <head> metadata tags on a page (title, description, og, twitter, ai, json_ld, ...). Creates a draft.',
        'get_ai_txt' => 'Read the site-wide /ai.txt file (AI-crawler directives, like robots.txt for AI agents)',
        'update_ai_txt' => 'Write the site-wide /ai.txt file',
        'get_usage_tips' => 'Get usage tips and best practices for the MCP API'
    ];
}


/**
 * Which admin capability (core/Permissions.php) a tool needs. Tools missing
 * from this map are treated as read-only and allowed for every principal.
 * The static install token bypasses this (owner). OAuth users are checked
 * against their role in tools/list (hidden) and tools/call (refused).
 */
function getMCPToolCapabilities() {
    return [
        'create_page' => 'pages.create', 'duplicate_page' => 'pages.create',
        'delete_page' => 'pages.delete',
        'publish_page' => 'pages.publish', 'discard_draft' => 'pages.edit',
        'update_block' => 'pages.edit', 'insert_block' => 'pages.edit',
        'find_and_replace_block_content' => 'pages.edit', 'update_page_region' => 'pages.edit',
        'update_page_meta' => 'pages.edit', 'update_ai_txt' => 'settings.manage',
        'restore_backup' => 'backups.manage', 'restore_global_backup' => 'backups.manage',
        'create_post' => 'blog.create', 'update_post' => 'blog.edit',
        'publish_post' => 'blog.publish', 'unpublish_post' => 'blog.publish', 'schedule_post' => 'blog.publish',
        'delete_post' => 'blog.delete', 'manage_author' => 'settings.manage',
        'upload_file' => 'media.manage', 'upload_image' => 'media.manage',
        'update_file_region' => 'files.manage',
        'update_template' => 'files.manage',
        'upload_image_from_url' => 'media.manage', 'update_media' => 'media.manage',
        'delete_media' => 'media.manage', 'generate_image' => 'media.manage',
    ];
}

/**
 * Normalise an inputSchema so every MCP client's function-declaration
 * parser accepts it. Gemini (CLI and API) is the strictest consumer: it
 * rejects OBJECT schemas without `properties`, ARRAY schemas without
 * `items`, empty `required` lists and JSON-Schema keywords it doesn't map
 * (`$schema`, `additionalProperties`, `default`, `examples`, `const`,
 * unknown `format` values). Claude/OpenAI accept the normalised form too.
 */
function mcpNormalizeInputSchema($schema) {
    if ($schema instanceof stdClass) {
        $schema = (array)$schema;
    }
    if (!is_array($schema) || $schema === []) {
        return ['type' => 'object', 'properties' => new stdClass()];
    }
    unset($schema['$schema'], $schema['$id'], $schema['additionalProperties'], $schema['default'], $schema['examples'], $schema['const']);
    if (isset($schema['format']) && !in_array($schema['format'], ['enum', 'date-time'], true)) {
        unset($schema['format']);
    }
    $type = $schema['type'] ?? null;
    if ($type === 'object' || isset($schema['properties'])) {
        $schema['type'] = 'object';
        $props = $schema['properties'] ?? [];
        if ($props instanceof stdClass) $props = (array)$props;
        $out = [];
        foreach ((array)$props as $k => $v) {
            $out[$k] = mcpNormalizeInputSchema($v);
        }
        $schema['properties'] = $out === [] ? new stdClass() : $out;
        if (isset($schema['required'])) {
            $req = array_values(array_filter((array)$schema['required'], 'is_string'));
            if ($req === []) unset($schema['required']); else $schema['required'] = $req;
        }
    } elseif ($type === 'array') {
        $schema['items'] = mcpNormalizeInputSchema($schema['items'] ?? ['type' => 'string']);
    }
    return $schema;
}

/**
 * Get MCP tools with full JSON Schema definitions for JSON-RPC 2.0 clients (Claude Code, Gemini CLI)
 */
function getMCPToolsWithSchema() {
    return [
        'list_pages' => [
            'description' => 'List all available page_ids in the CMS. PRIMARY DISCOVERY TOOL: Use this FIRST to identify the correct page_id when the user references a page in natural language. TIP: If user wants to edit specific text, skip this tool and go directly to search_blocks.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new stdClass(),
                'required' => []
            ]
        ],
        'list_blocks' => [
            'description' => 'List all CMS blocks on a page (returns metadata only: name, role, custom). Use this BEFORE editing to understand page structure.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'string', 'description' => 'Page ID (e.g., "about", "about/team"). For homepage use: "" or "/"']
                ],
                'required' => ['page_id']
            ]
        ],
        'search_blocks' => [
            'description' => 'PRIMARY SEARCH TOOL - Search for text inside CMS blocks across all pages. Use this FIRST when looking for any user-specified text. Returns block_name, page_id, and content preview.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'search_text' => ['type' => 'string', 'description' => 'Text to search for in block content'],
                    'search_mode' => ['type' => 'string', 'enum' => ['case_insensitive', 'case_sensitive', 'html_insensitive'], 'description' => 'Search mode (default: case_insensitive)']
                ],
                'required' => ['search_text']
            ]
        ],
        'read_block' => [
            'description' => 'Read a specific CMS block\'s content from a page. Use after identifying the block via search_blocks or list_blocks.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'string', 'description' => 'Page ID. For homepage use: "" or "/"'],
                    'name' => ['type' => 'string', 'description' => 'Block name']
                ],
                'required' => ['page_id', 'name']
            ]
        ],
        'update_block' => [
            'description' => 'Update a CMS block\'s content. IMPORTANT: If block does NOT have custom=1 (global blocks like header, footer), this will automatically sync changes to ALL pages that have this block (skipping pages where block is marked custom). A global backup is created before syncing. Creates a DRAFT for the source page. After editing, provide a CLICKABLE markdown link for preview: [Preview Draft](/cms/admin/preview.php?page_id={page_id}&draft=1) and ask user to publish using publish_page tool.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'string', 'description' => 'Page ID. For homepage use: "" or "/"'],
                    'name' => ['type' => 'string', 'description' => 'Block name'],
                    'content' => ['type' => 'string', 'description' => 'New block content (HTML)'],
                    'custom' => ['type' => 'boolean', 'description' => 'Whether this block is a custom per-page override']
                ],
                'required' => ['page_id', 'name', 'content']
            ]
        ],
        'find_and_replace_block_content' => [
            'description' => 'Find and replace text inside a CMS block. PREFERRED for small edits. IMPORTANT: If block does NOT have custom=1 (global blocks like header, footer), the find/replace will automatically be applied to ALL pages that have this block (skipping pages where block is marked custom). A global backup is created before syncing. Creates a DRAFT for source page. After editing, provide a CLICKABLE markdown link for preview: [Preview Draft](/cms/admin/preview.php?page_id={page_id}&draft=1) and ask user to publish using publish_page tool.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'string', 'description' => 'Page ID. For homepage use: "" or "/"'],
                    'name' => ['type' => 'string', 'description' => 'Block name'],
                    'search' => ['type' => 'string', 'description' => 'Exact text to search for'],
                    'replace' => ['type' => 'string', 'description' => 'Replacement text'],
                    'mode' => ['type' => 'string', 'enum' => ['first', 'all'], 'description' => 'Replace mode (default: first)'],
                    'case_sensitive' => ['type' => 'boolean', 'description' => 'Case sensitive search (default: true)']
                ],
                'required' => ['page_id', 'name', 'search', 'replace']
            ]
        ],
        'publish_page' => [
            'description' => 'Publish a page draft to make it live. NOTE: tool name is "publish_page", NOT "publish_draft". Use after editing to make changes live.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'string', 'description' => 'Page ID. For homepage use: "" or "/"']
                ],
                'required' => ['page_id']
            ]
        ],
        'discard_draft' => [
            'description' => 'Discard a page draft without publishing. Keeps the live page unchanged.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'string', 'description' => 'Page ID. For homepage use: "" or "/"']
                ],
                'required' => ['page_id']
            ]
        ],
        'create_page' => [
            'description' => 'Create a new page with optional HTML content.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'string', 'description' => 'New page ID (e.g., "about", "services/web")'],
                    'content' => ['type' => 'string', 'description' => 'Optional HTML content for the page']
                ],
                'required' => ['page_id']
            ]
        ],
        'read_page' => [
            'description' => 'Read the full HTML content of a page file. Use sparingly - prefer list_blocks + read_block.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'string', 'description' => 'Page ID. For homepage use: "" or "/"'],
                    'max_chars' => ['type' => 'integer', 'description' => 'Cap on returned content length (default 60000). Response has total_chars + truncated so you know if more exists.'],
                ],
                'required' => ['page_id']
            ]
        ],
        'delete_page' => [
            'description' => 'Delete a page permanently. Creates backup before deletion.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'string', 'description' => 'Page ID to delete']
                ],
                'required' => ['page_id']
            ]
        ],
        'duplicate_page' => [
            'description' => 'Duplicate an existing page to create a new one.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'source_page_id' => ['type' => 'string', 'description' => 'Source page ID to duplicate from'],
                    'new_page_id' => ['type' => 'string', 'description' => 'New page ID']
                ],
                'required' => ['source_page_id', 'new_page_id']
            ]
        ],
        'insert_block' => [
            'description' => 'Insert a new CMS block into a page at a specific position. Creates a DRAFT. After inserting, provide a CLICKABLE markdown link for preview: [Preview Draft](/cms/admin/preview.php?page_id={page_id}&draft=1) and ask user to publish using publish_page tool.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'string', 'description' => 'Page ID'],
                    'position' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['before_block', 'after_block', 'at_end']],
                            'block_name' => ['type' => 'string', 'description' => 'Reference block name']
                        ],
                        'required' => ['type']
                    ],
                    'name' => ['type' => 'string', 'description' => 'New block name (must be unique)'],
                    'role' => ['type' => 'string', 'description' => 'Optional block role'],
                    'custom' => ['type' => 'boolean', 'description' => 'Whether this is a custom block'],
                    'content' => ['type' => 'string', 'description' => 'HTML content for the new block']
                ],
                'required' => ['page_id', 'position', 'name', 'content']
            ]
        ],
        'search_in_page' => [
            'description' => 'RAW FILE SEARCH - FALLBACK ONLY. Use only if search_blocks finds nothing.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'string', 'description' => 'Page ID'],
                    'search' => ['type' => 'string', 'description' => 'Text to search for'],
                    'limit' => ['type' => 'integer', 'description' => 'Max matches to return (default: 20)'],
                    'case_sensitive' => ['type' => 'boolean', 'description' => 'Case sensitive search (default: false)']
                ],
                'required' => ['page_id', 'search']
            ]
        ],
        'get_page_region' => [
            'description' => 'Retrieve a region of a page by line range. Use after search_in_page.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'string', 'description' => 'Page ID'],
                    'start_line' => ['type' => 'integer', 'description' => '1-based line number (inclusive)'],
                    'end_line' => ['type' => 'integer', 'description' => '1-based line number (inclusive)'],
                    'max_chars' => ['type' => 'integer', 'description' => 'Soft cap on region length (default: 4000)']
                ],
                'required' => ['page_id', 'start_line', 'end_line']
            ]
        ],
        'update_page_region' => [
            'description' => 'Apply a patch to a page region using optimistic locking. Creates a DRAFT. After updating, provide a CLICKABLE markdown link for preview: [Preview Draft](/cms/admin/preview.php?page_id={page_id}&draft=1) and ask user to publish using publish_page tool.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'string', 'description' => 'Page ID'],
                    'start_line' => ['type' => 'integer', 'description' => 'Start line number'],
                    'end_line' => ['type' => 'integer', 'description' => 'End line number'],
                    'old_region' => ['type' => 'string', 'description' => 'Exact content from get_page_region'],
                    'new_region' => ['type' => 'string', 'description' => 'New content to replace with']
                ],
                'required' => ['page_id', 'start_line', 'end_line', 'old_region', 'new_region']
            ]
        ],
        'list_backups' => [
            'description' => 'List PAGE-SPECIFIC backups for a single page. These are created when publishing custom blocks. Use list_global_backups for backups created by global block updates (header, footer, etc.).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'string', 'description' => 'Page ID. For homepage use: "" or "/"']
                ],
                'required' => ['page_id']
            ]
        ],
        'restore_backup' => [
            'description' => 'Restore a SINGLE page from a page-specific backup. Only affects the specified page. For restoring multiple pages from a global block update, use restore_global_backup instead.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'string', 'description' => 'Page ID'],
                    'timestamp' => ['type' => 'string', 'description' => 'Backup timestamp (YmdHis format)']
                ],
                'required' => ['page_id', 'timestamp']
            ]
        ],
        'list_global_backups' => [
            'description' => 'List GLOBAL backups created when editing blocks without custom=1 (header, footer, etc.). Each global backup contains snapshots of ALL pages affected by the global block update. Use this to see grouped backups that can restore multiple pages at once.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new stdClass(),
                'required' => []
            ]
        ],
        'restore_global_backup' => [
            'description' => 'Restore a global backup, reverting ALL pages that were affected by that global block update to their previous state. Use list_global_backups first to see available backups with their timestamps and affected page counts.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'timestamp' => ['type' => 'string', 'description' => 'Backup timestamp (YmdHis format) from list_global_backups']
                ],
                'required' => ['timestamp']
            ]
        ],
        'list_posts' => [
            'description' => 'List blog posts in a collection with optional filters. Returns metadata (no content body).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'collection_id' => ['type' => 'string', 'description' => 'Collection ID (default: "blog")'],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'published', 'scheduled'], 'description' => 'Filter by status'],
                    'author_id' => ['type' => 'string', 'description' => 'Filter by author ID'],
                    'tag' => ['type' => 'string', 'description' => 'Filter by tag'],
                    'category' => ['type' => 'string', 'description' => 'Filter by category']
                ],
                'required' => []
            ]
        ],
        'create_post' => [
            'description' => 'Create a new blog post as a JSON draft. Returns the slug. Use update_post or publish_post afterwards.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'collection_id' => ['type' => 'string', 'description' => 'Collection ID (default: "blog")'],
                    'slug' => ['type' => 'string', 'description' => 'Post slug (e.g., "my-first-post")'],
                    'title' => ['type' => 'string', 'description' => 'Post title'],
                    'content' => ['type' => 'string', 'description' => 'HTML content body'],
                    'excerpt' => ['type' => 'string', 'description' => 'Short excerpt/summary'],
                    'author_id' => ['type' => 'string', 'description' => 'Author ID from authors.json'],
                    'categories' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Category names'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tag names'],
                    'featured_image' => ['type' => 'string', 'description' => 'Featured image URL'],
                    'featured_image_alt' => ['type' => 'string', 'description' => 'Featured image alt text'],
                    'featured' => ['type' => 'boolean', 'description' => 'Mark as featured post'],
                    'seo' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'description' => ['type' => 'string']], 'description' => 'SEO overrides']
                ],
                'required' => ['slug']
            ]
        ],
        'read_post' => [
            'description' => 'Read a blog post with all metadata and content.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'collection_id' => ['type' => 'string', 'description' => 'Collection ID (default: "blog")'],
                    'slug' => ['type' => 'string', 'description' => 'Post slug']
                ],
                'required' => ['slug']
            ]
        ],
        'update_post' => [
            'description' => 'Update a blog post\'s content and/or metadata fields. Only specified fields are changed.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'collection_id' => ['type' => 'string', 'description' => 'Collection ID (default: "blog")'],
                    'slug' => ['type' => 'string', 'description' => 'Post slug'],
                    'title' => ['type' => 'string', 'description' => 'Post title'],
                    'content' => ['type' => 'string', 'description' => 'HTML content body'],
                    'excerpt' => ['type' => 'string', 'description' => 'Short excerpt/summary'],
                    'author_id' => ['type' => 'string', 'description' => 'Author ID'],
                    'published_at' => ['type' => 'string', 'description' => 'Publish date (YYYY-MM-DD)'],
                    'categories' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Category names'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tag names'],
                    'featured_image' => ['type' => 'string', 'description' => 'Featured image URL'],
                    'featured_image_alt' => ['type' => 'string', 'description' => 'Featured image alt text'],
                    'featured' => ['type' => 'boolean', 'description' => 'Mark as featured'],
                    'seo' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'description' => ['type' => 'string']], 'description' => 'SEO overrides']
                ],
                'required' => ['slug']
            ]
        ],
        'publish_post' => [
            'description' => 'Publish a blog post. Generates a stub PHP file for the URL and updates the sitemap.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'collection_id' => ['type' => 'string', 'description' => 'Collection ID (default: "blog")'],
                    'slug' => ['type' => 'string', 'description' => 'Post slug']
                ],
                'required' => ['slug']
            ]
        ],
        'unpublish_post' => [
            'description' => 'Unpublish a blog post back to draft. Removes the stub file.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'collection_id' => ['type' => 'string', 'description' => 'Collection ID (default: "blog")'],
                    'slug' => ['type' => 'string', 'description' => 'Post slug']
                ],
                'required' => ['slug']
            ]
        ],
        'delete_post' => [
            'description' => 'Delete a blog post permanently. Removes JSON file and any published stub.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'collection_id' => ['type' => 'string', 'description' => 'Collection ID (default: "blog")'],
                    'slug' => ['type' => 'string', 'description' => 'Post slug']
                ],
                'required' => ['slug']
            ]
        ],
        'schedule_post' => [
            'description' => 'Schedule a post for future publishing. The post will be automatically published when the scheduled time passes.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'collection_id' => ['type' => 'string', 'description' => 'Collection ID (default: "blog")'],
                    'slug' => ['type' => 'string', 'description' => 'Post slug'],
                    'scheduled_at' => ['type' => 'string', 'description' => 'Scheduled publish datetime (YYYY-MM-DD HH:MM:SS)']
                ],
                'required' => ['slug', 'scheduled_at']
            ]
        ],
        'list_authors' => [
            'description' => 'List all author profiles.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new stdClass(),
                'required' => []
            ]
        ],
        'get_author' => [
            'description' => 'Get a single author profile by ID.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'author_id' => ['type' => 'string', 'description' => 'Author ID (slug)']
                ],
                'required' => ['author_id']
            ]
        ],
        'manage_author' => [
            'description' => 'Create, update, or delete an author profile.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['create', 'update', 'delete'], 'description' => 'Action to perform'],
                    'author_id' => ['type' => 'string', 'description' => 'Author ID (slug)'],
                    'name' => ['type' => 'string', 'description' => 'Author display name'],
                    'email' => ['type' => 'string', 'description' => 'Author email'],
                    'bio' => ['type' => 'string', 'description' => 'Author bio'],
                    'avatar' => ['type' => 'string', 'description' => 'Avatar image URL'],
                    'role' => ['type' => 'string', 'description' => 'Author role (e.g., Editor, Writer)'],
                    'social' => ['type' => 'object', 'properties' => ['twitter' => ['type' => 'string'], 'github' => ['type' => 'string'], 'linkedin' => ['type' => 'string'], 'website' => ['type' => 'string']], 'description' => 'Social media links']
                ],
                'required' => ['action', 'author_id']
            ]
        ],
        'list_files' => [
            'description' => 'List editable text files (css, js, html, php, json, xml, md, txt, svg, etc.) under a directory. Skips /cms, /vendor, /node_modules, /.git, and dotfiles. Use this first to discover paths before read_file.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'dir' => ['type' => 'string', 'description' => 'Relative directory under root_dir (default: "")'],
                    'ext' => ['type' => 'string', 'description' => 'Optional extension filter, e.g. "css"'],
                    'max' => ['type' => 'integer', 'description' => 'Cap on results (default 200, max 1000)']
                ],
                'required' => []
            ]
        ],
        'read_file' => [
            'description' => 'Read a slice of a text file by line range. Returns at most max_chars of content plus file metadata. Always prefer narrow ranges over reading the whole file.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative path under root_dir'],
                    'start_line' => ['type' => 'integer', 'description' => '1-based, default 1'],
                    'end_line' => ['type' => 'integer', 'description' => '1-based inclusive, default end-of-file'],
                    'max_chars' => ['type' => 'integer', 'description' => 'Soft cap (default 4000, max 20000)']
                ],
                'required' => ['path']
            ]
        ],
        'search_in_file' => [
            'description' => 'Find text or regex matches in a file. Returns up to max_matches lines (number + 240-char snippet). FALLBACK only after list_blocks / search_blocks for CMS pages — use directly for non-page files (CSS, JS, etc.).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative path under root_dir'],
                    'query' => ['type' => 'string', 'description' => 'Substring or regex pattern (no delimiters/flags — caller can\'t change them)'],
                    'regex' => ['type' => 'boolean', 'description' => 'Treat query as regex (default false)'],
                    'case_sensitive' => ['type' => 'boolean', 'description' => 'Default false'],
                    'max_matches' => ['type' => 'integer', 'description' => 'Cap results (default 50, max 200)']
                ],
                'required' => ['path', 'query']
            ]
        ],
        'update_file_region' => [
            'description' => 'Patch a file by line range with optimistic locking. old_region MUST exactly match the current bytes in [start_line, end_line] or the patch is refused. A backup is created automatically before any write — page-backup history if the file is a known CMS page, else under backups_dir/_file_edits/. After updating non-page files (CSS, JS), tell the user the change is LIVE (no draft/publish loop).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative path under root_dir'],
                    'start_line' => ['type' => 'integer', 'description' => '1-based, inclusive'],
                    'end_line' => ['type' => 'integer', 'description' => '1-based, inclusive'],
                    'old_region' => ['type' => 'string', 'description' => 'Exact content currently at this range (from read_file). LF newlines.'],
                    'new_region' => ['type' => 'string', 'description' => 'Replacement content. LF newlines.']
                ],
                'required' => ['path', 'start_line', 'end_line', 'old_region', 'new_region']
            ]
        ],
        'list_templates' => [
            'description' => 'List the collection templates that render the blog: engine defaults (cms/collection-templates, read-only) and site overrides (theme/collection-templates, editable), which file is active for each collection and kind (detail = single post page, list = index page), plus the variables available inside templates ($post, $author, $pagedPosts, $pagination…). Use this FIRST when asked to change how posts or the blog index look.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new stdClass(),
                'required' => []
            ]
        ],
        'read_template' => [
            'description' => 'Read one collection template as numbered lines. name is "<collection>-detail" / "<collection>-list" (e.g. "blog-detail") or "default-detail" / "default-list". Without scope, the active file is returned (site override if present, else engine default).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Template name without .php, e.g. "blog-detail", "default-list"'],
                    'scope' => ['type' => 'string', 'enum' => ['site', 'engine'], 'description' => 'Force reading the site override or the engine default (default: whichever is active)']
                ],
                'required' => ['name']
            ]
        ],
        'update_template' => [
            'description' => 'Replace a collection template with new full PHP/HTML source. Always writes the SITE override theme/collection-templates/<name>.php (created from the engine default if it did not exist); engine files are never touched. The content is syntax-checked (php -l) and the previous file is backed up before writing. Changes are live immediately — read_template first, edit, then send the complete file back.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Template name without .php, e.g. "blog-detail"'],
                    'content' => ['type' => 'string', 'description' => 'Complete new template source (PHP + HTML)']
                ],
                'required' => ['name', 'content']
            ]
        ],
        'upload_file' => [
            'description' => 'Upload a file to the uploads directory. Returns the URL.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'data' => ['type' => 'string', 'description' => 'Base64-encoded file data'],
                    'filename' => ['type' => 'string', 'description' => 'Original filename with extension'],
                    'subdir' => ['type' => 'string', 'description' => 'Optional subdirectory']
                ],
                'required' => ['data', 'filename']
            ]
        ],
        'upload_image' => [
            'description' => 'Upload an image you hold as raw bytes (base64). Prefer upload_image_from_url when the user gives you a link, and list_media to reuse a picture already in the library. The image is resized to the configured maximum, a thumbnail is generated, and it is catalogued with the name/alt/caption you pass. Returns url, thumb_url, width, height and ready-to-paste html. To use it as a post\'s cover, call update_post with featured_image = url (and featured_image_alt).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'data' => ['type' => 'string', 'description' => 'Base64-encoded image data'],
                    'filename' => ['type' => 'string', 'description' => 'Original filename'],
                    'subdir' => ['type' => 'string', 'description' => 'Optional subdirectory'],
                    'include_webp' => ['type' => 'boolean', 'description' => 'Also generate WebP versions (default false)'],
                    'alt' => ['type' => 'string', 'description' => 'Alt text describing the image (recommended)'],
                    'name' => ['type' => 'string', 'description' => 'Human-readable name for the media library, e.g. "Office team photo"'],
                    'caption' => ['type' => 'string', 'description' => 'Optional caption']
                ],
                'required' => ['data', 'filename']
            ]
        ],
        'upload_image_from_url' => [
            'description' => 'Fetch an image from a public http(s) URL and add it to the media library (resized to the configured maximum, thumbnail generated, catalogued). Use this whenever the user gives you a link to a picture — you never need the bytes. Private/internal hosts are refused. Returns url, thumb_url, width, height, alt and ready-to-paste html. For a post cover, follow with update_post {featured_image: url, featured_image_alt: alt}; to place it in the body, insert the html into the post content.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'url' => ['type' => 'string', 'description' => 'Public http(s) URL of the image'],
                    'filename' => ['type' => 'string', 'description' => 'Optional filename with extension (derived from the URL when omitted)'],
                    'alt' => ['type' => 'string', 'description' => 'Alt text describing the image (recommended)'],
                    'name' => ['type' => 'string', 'description' => 'Human-readable name for the media library'],
                    'caption' => ['type' => 'string', 'description' => 'Optional caption'],
                    'subdir' => ['type' => 'string', 'description' => 'Optional subdirectory under the uploads folder']
                ],
                'required' => ['url']
            ]
        ],
        'list_media' => [
            'description' => 'List or search images already in the media library. Use this FIRST when the user refers to an existing picture ("the office photo", "the logo") so you can reuse its url instead of uploading again. Matches name, alt text, caption and url. Each item has url, thumb_url, width, height, name, alt, caption and html (ready-to-paste <img>).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Optional search text (substring, case-insensitive)'],
                    'limit' => ['type' => 'integer', 'description' => 'Max items to return (default 50)'],
                    'offset' => ['type' => 'integer', 'description' => 'Skip this many items (paging)']
                ]
            ]
        ],
        'update_media' => [
            'description' => 'Set the human-readable name, alt text or caption of an image in the media library. Pass only the fields to change.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'url' => ['type' => 'string', 'description' => 'The image url as returned by list_media / upload tools'],
                    'alt' => ['type' => 'string', 'description' => 'Alt text'],
                    'name' => ['type' => 'string', 'description' => 'Display name'],
                    'caption' => ['type' => 'string', 'description' => 'Caption']
                ],
                'required' => ['url']
            ]
        ],
        'delete_media' => [
            'description' => 'DESTRUCTIVE: permanently delete an image (every size + thumbnail) from the media library. Posts or pages that still reference the url will show a broken image — check with search_blocks / list_posts first and confirm with the user.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'url' => ['type' => 'string', 'description' => 'The image url as returned by list_media']
                ],
                'required' => ['url']
            ]
        ],
        'generate_image' => [
            'description' => 'Generate a brand-new image from a text prompt using the site\'s configured AI provider (OpenAI gpt-image-1 or Gemini image model; Anthropic cannot generate images) and add it to the media library. Good for featured images and illustrations when the user has no picture. Takes 10-60 seconds. Returns url, thumb_url, width, height, alt and html like the upload tools.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'prompt' => ['type' => 'string', 'description' => 'What to draw. Be specific about subject, style, composition and mood.'],
                    'size' => ['type' => 'string', 'enum' => ['1024x1024', '1536x1024', '1024x1536'], 'description' => 'Output size (default 1024x1024; 1536x1024 is landscape, 1024x1536 portrait)'],
                    'alt' => ['type' => 'string', 'description' => 'Alt text (defaults to a shortened prompt)'],
                    'name' => ['type' => 'string', 'description' => 'Display name for the media library']
                ],
                'required' => ['prompt']
            ]
        ],
        'get_page_meta' => [
            'description' => 'Return all <head> metadata for a page: title, description, keywords, canonical, robots, author, viewport, theme_color, generator, og (associative), twitter (associative), ai (associative for ai-* tags), json_ld (array of decoded JSON-LD scripts), and other (catch-all for unrecognised meta tags). Reads from the draft if one exists, otherwise the live page.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'string', 'description' => 'Page ID. For homepage use: "" or "/"'],
                ],
                'required' => ['page_id']
            ]
        ],
        'update_page_meta' => [
            'description' => 'Patch one or more <head> metadata tags. Pass only the keys you want to change — missing tags are inserted into <head>, existing tags are replaced. Supported keys: title, description, keywords, canonical, robots, author, viewport, theme_color, generator, og (object of sub-keys e.g. {title,description,image,url,type,site_name,locale}), twitter (object of sub-keys e.g. {card,title,description,image,site,creator}), ai (object — each sub-key becomes <meta name="ai-<key>" content="...">), json_ld (array of objects or raw JSON strings — REPLACES all existing JSON-LD scripts on the page). Creates a draft.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'page_id'    => ['type' => 'string', 'description' => 'Page ID. For homepage use: "" or "/"'],
                    'title'      => ['type' => 'string'],
                    'description'=> ['type' => 'string'],
                    'keywords'   => ['type' => 'string'],
                    'canonical'  => ['type' => 'string'],
                    'robots'     => ['type' => 'string'],
                    'author'     => ['type' => 'string'],
                    'viewport'   => ['type' => 'string'],
                    'theme_color'=> ['type' => 'string'],
                    'generator'  => ['type' => 'string'],
                    'og'         => [
                        'type' => 'object',
                        'description' => 'Open Graph tags as { sub_key: value }, e.g. {"title":"...","image":"https://..."}',
                        'properties' => [
                            'title' => ['type' => 'string'], 'description' => ['type' => 'string'],
                            'image' => ['type' => 'string'], 'url' => ['type' => 'string'],
                            'type' => ['type' => 'string'], 'site_name' => ['type' => 'string'],
                            'locale' => ['type' => 'string'],
                        ],
                    ],
                    'twitter'    => [
                        'type' => 'object',
                        'description' => 'Twitter card tags as { sub_key: value }, e.g. {"card":"summary_large_image"}',
                        'properties' => [
                            'card' => ['type' => 'string'], 'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'], 'image' => ['type' => 'string'],
                            'site' => ['type' => 'string'], 'creator' => ['type' => 'string'],
                        ],
                    ],
                    'ai'         => [
                        'type' => 'object',
                        'description' => 'AI-specific meta as { sub_key: value } — each becomes <meta name="ai-<sub_key>">. Common keys: summary, keywords, audience, content_type, license.',
                        'properties' => [
                            'summary' => ['type' => 'string'], 'keywords' => ['type' => 'string'],
                            'audience' => ['type' => 'string'], 'content_type' => ['type' => 'string'],
                            'license' => ['type' => 'string'],
                        ],
                    ],
                    'json_ld'    => [
                        'type' => 'array',
                        'description' => 'JSON-LD scripts to write. Each item is one JSON-LD document serialised as a JSON string (objects are also accepted). Replaces ALL existing JSON-LD scripts on the page.',
                        'items' => ['type' => 'string', 'description' => 'One JSON-LD document as a JSON string'],
                    ]
                ],
                'required' => ['page_id']
            ]
        ],
        'get_ai_txt' => [
            'description' => 'Read the site-wide /ai.txt file (analogous to robots.txt but for AI crawlers — declares allowed/blocked agents, licensing, etc.). Returns content or an empty string if the file doesn\'t exist. Site-wide, not per-page.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new stdClass(),
                'required' => []
            ]
        ],
        'update_ai_txt' => [
            'description' => 'Write the site-wide /ai.txt file. Replaces its full contents. Site-wide, not per-page. Use empty content to clear.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'content' => ['type' => 'string', 'description' => 'New content of /ai.txt']
                ],
                'required' => ['content']
            ]
        ],
        'get_usage_tips' => [
            'description' => 'Get usage tips and best practices. QUICK START: 1) search_blocks to find text, 2) find_and_replace_block_content or update_block, 3) show draft preview link and ask to publish_page. NEVER guess tool names.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new stdClass(),
                'required' => []
            ]
        ]
    ];
}

/**
 * MCP tool annotations (spec 2025-03-26+): title + behaviour hints.
 * Clients such as ChatGPT and Claude use readOnlyHint/destructiveHint to
 * decide when to ask the user for confirmation; the activity log uses
 * readOnlyHint to skip logging pure reads. Every tool name in getMCPTools()
 * must appear here (tests/response-hygiene.php checks tools/list output).
 *
 * Shorthand per entry: [title, readOnly, destructive, idempotent].
 */
function getMCPToolAnnotations() {
    $t = [
        // Pages
        'list_pages'                     => ['List pages',                    true,  false, true ],
        'create_page'                    => ['Create page',                   false, false, false],
        'read_page'                      => ['Read page HTML',                true,  false, true ],
        'delete_page'                    => ['Delete page',                   false, true,  true ],
        'duplicate_page'                 => ['Duplicate page',                false, false, false],
        'publish_page'                   => ['Publish page draft',            false, false, true ],
        'discard_draft'                  => ['Discard page draft',            false, true,  true ],
        // Blocks
        'list_blocks'                    => ['List blocks on a page',         true,  false, true ],
        'read_block'                     => ['Read block',                    true,  false, true ],
        'update_block'                   => ['Update block (draft)',          false, false, true ],
        'insert_block'                   => ['Insert block (draft)',          false, false, false],
        'search_blocks'                  => ['Search blocks across pages',    true,  false, true ],
        'find_and_replace_block_content' => ['Find and replace in block',     false, false, false],
        // Raw page access
        'search_in_page'                 => ['Search in page source',         true,  false, true ],
        'get_page_region'                => ['Get page lines',                true,  false, true ],
        'update_page_region'             => ['Update page lines (draft)',     false, false, false],
        // Backups
        'list_backups'                   => ['List page backups',             true,  false, true ],
        'restore_backup'                 => ['Restore page backup',           false, true,  true ],
        'list_global_backups'            => ['List global backups',           true,  false, true ],
        'restore_global_backup'          => ['Restore global backup',         false, true,  true ],
        // Blog
        'list_posts'                     => ['List posts',                    true,  false, true ],
        'create_post'                    => ['Create post (draft)',           false, false, false],
        'read_post'                      => ['Read post',                     true,  false, true ],
        'update_post'                    => ['Update post',                   false, false, true ],
        'publish_post'                   => ['Publish post',                  false, false, true ],
        'unpublish_post'                 => ['Unpublish post',                false, false, true ],
        'delete_post'                    => ['Delete post',                   false, true,  true ],
        'schedule_post'                  => ['Schedule post',                 false, false, true ],
        'list_post_revisions'            => ['List post revisions',           true,  false, true ],
        'restore_post_revision'          => ['Restore post revision',         false, true,  true ],
        // Categories
        'list_categories'                => ['List categories',               true,  false, true ],
        'create_category'                => ['Create category',               false, false, false],
        'update_category'                => ['Update category',               false, false, true ],
        'delete_category'                => ['Delete category',               false, true,  true ],
        // Authors
        'list_authors'                   => ['List authors',                  true,  false, true ],
        'get_author'                     => ['Get author',                    true,  false, true ],
        'manage_author'                  => ['Create, update or delete author', false, true, false],
        // Files
        'list_files'                     => ['List site files',               true,  false, true ],
        'read_file'                      => ['Read file lines',               true,  false, true ],
        'search_in_file'                 => ['Search in file',                true,  false, true ],
        'update_file_region'             => ['Update file lines',             false, false, false],
        'list_templates'                 => ['List blog templates',           true,  false, true ],
        'read_template'                  => ['Read blog template',            true,  false, true ],
        'update_template'                => ['Update blog template',          false, false, false],
        // Media
        'upload_file'                    => ['Upload file',                   false, false, false],
        'upload_image'                   => ['Upload image (base64)',         false, false, false],
        'upload_image_from_url'          => ['Upload image from URL',         false, false, false],
        'list_media'                     => ['List media library',            true,  false, true ],
        'update_media'                   => ['Update media alt/name',         false, false, true ],
        'delete_media'                   => ['Delete media',                  false, true,  true ],
        'generate_image'                 => ['Generate image with AI',        false, false, false],
        // Meta / site
        'get_page_meta'                  => ['Get page SEO meta',             true,  false, true ],
        'update_page_meta'               => ['Update page SEO meta (draft)',  false, false, true ],
        'get_ai_txt'                     => ['Read ai.txt',                   true,  false, true ],
        'update_ai_txt'                  => ['Write ai.txt',                  false, false, true ],
        'get_usage_tips'                 => ['Usage recipes',                 true,  false, true ],
    ];
    $out = [];
    foreach ($t as $name => [$title, $ro, $destructive, $idem]) {
        $out[$name] = [
            'title'           => $title,
            'readOnlyHint'    => $ro,
            'destructiveHint' => $destructive,
            'idempotentHint'  => $idem,
            'openWorldHint'   => false,
        ];
    }
    return $out;
}

/**
 * Text returned in initialize.instructions — the model reads this once per
 * session, before any tool call, so it carries the four job recipes and the
 * two rules that prevent the common mistakes (draft vs live, style attrs).
 */
function getMCPServerInstructions(array $config): string {
    $site = (string)($config['site_name'] ?? 'this site');
    return 'You are editing "' . $site . '", a flat-file CMS: pages are HTML made of named blocks, '
        . 'blog posts live in collections, images in a media library. '
        . 'Add an article: list_authors → list_categories → create_post (saved as draft, returns preview_url) → publish_post. '
        . 'Add a picture: list_media to reuse one, else upload_image_from_url with alt text, then use the url as featured_image or in an <img>. '
        . 'Edit site copy: search_blocks → read_block → update_block (draft) → publish_page; blocks without custom=1 are global and sync to every page, so warn first. '
        . 'Change SEO: get_page_meta → update_page_meta → publish_page. '
        . 'Rules: nothing is live until publish_page / publish_post; homepage page_id is ""; '
        . 'never put style attributes, scripts or data: URLs in post HTML (they are stripped); '
        . 'never delete anything without explicit confirmation; when several pages match a search, ask which one. '
        . 'Call get_usage_tips for the full recipes.';
}
