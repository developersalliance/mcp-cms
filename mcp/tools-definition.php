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
        'upload_file' => 'Upload a file to the server',
        'upload_image' => 'Upload and process an image',
        'get_page_meta' => 'Read a page\'s <head> metadata: title, description, keywords, canonical, robots, og:*, twitter:*, JSON-LD, ai-* tags',
        'update_page_meta' => 'Update one or more <head> metadata tags on a page (title, description, og, twitter, ai, json_ld, ...). Creates a draft.',
        'get_ai_txt' => 'Read the site-wide /ai.txt file (AI-crawler directives, like robots.txt for AI agents)',
        'update_ai_txt' => 'Write the site-wide /ai.txt file',
        'get_usage_tips' => 'Get usage tips and best practices for the MCP API'
    ];
}

/**
 * Get MCP tools with full JSON Schema definitions for JSON-RPC 2.0 clients (Claude Code)
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
                    'page_id' => ['type' => 'string', 'description' => 'Page ID. For homepage use: "" or "/"']
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
            'description' => 'Upload and automatically optimize an image. Generates WebP and PNG, full-size and thumbnails.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'data' => ['type' => 'string', 'description' => 'Base64-encoded image data'],
                    'filename' => ['type' => 'string', 'description' => 'Original filename'],
                    'subdir' => ['type' => 'string', 'description' => 'Optional subdirectory']
                ],
                'required' => ['data', 'filename']
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
                    'og'         => ['type' => 'object', 'description' => 'Open Graph tags as { sub_key: value }'],
                    'twitter'    => ['type' => 'object', 'description' => 'Twitter card tags as { sub_key: value }'],
                    'ai'         => ['type' => 'object', 'description' => 'AI-specific meta as { sub_key: value } — becomes <meta name="ai-<sub_key>">'],
                    'json_ld'    => ['type' => 'array', 'description' => 'Array of JSON-LD objects (or raw strings). Replaces ALL existing JSON-LD scripts on the page.']
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
