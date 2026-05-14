<?php
/**
 * Example config — copy to config/config.php on first install.
 * install.php does this automatically. After copying, real values
 * (mcp_token, paths, site_name, base_url) are filled in by the
 * installer.
 */
return [
    // Token used by AI MCP clients (ChatGPT, Claude, etc.).
    // For production: prefer setting CMS_MCP_TOKEN env var in nginx fastcgi
    // and rotating this literal. Each install gets its own config so the
    // env-fallback shape is per-server, not in the engine repo.
    'mcp_token' => 'CHANGE_ME_VIA_INSTALLER',

    // Directories
    'root_dir'    => realpath(__DIR__ . '/../../'),
    'cms_dir'     => realpath(__DIR__ . '/../'),
    'drafts_dir'  => realpath(__DIR__ . '/../') . '/drafts',
    'backups_dir' => realpath(__DIR__ . '/../') . '/backups',

    // Backups
    'max_backups_per_page' => 10,

    // Reserved folder names (cannot be used as page IDs)
    'reserved_folders' => ['cms', 'blog', 'assets', 'uploads', 'index'],

    // URL path where the admin lives. The engine still has hardcoded /cms/admin/
    // links in most templates — this key currently only affects login/logout
    // redirects, but is the foundation for full prefix-flexibility in a future
    // release. Set to your real prefix if you serve the CMS at, e.g., '/panel/'.
    'admin_path' => '/cms/admin/',

    // Optional settings
    'site_name'  => 'CHANGE_ME_VIA_INSTALLER',
    'language'   => 'en',
    'base_url'   => 'CHANGE_ME_VIA_INSTALLER',

    // Upload settings
    'uploads_dir' => 'assets/content/',
    'image_thumbnail_width' => 300,
    'image_thumbnail_height' => 300,
    'image_full_width' => 1920,
    'image_full_height' => 1080,

    // MCP Security Settings
    'mcp_rate_limit_enabled' => true,
    'mcp_rate_limit_requests' => 60,  // Max requests per window
    'mcp_rate_limit_window' => 60,    // Time window in seconds
    'mcp_ip_whitelist' => '',         // Comma-separated IPs (empty = allow all)
    'mcp_allowed_tools' => ['list_pages', 'create_page', 'read_page', 'delete_page', 'duplicate_page', 'publish_page', 'discard_draft', 'list_blocks', 'read_block', 'update_block', 'insert_block', 'search_blocks', 'find_and_replace_block_content', 'search_in_page', 'get_page_region', 'update_page_region', 'list_backups', 'restore_backup', 'list_global_backups', 'restore_global_backup', 'list_posts', 'create_post', 'read_post', 'update_post', 'publish_post', 'unpublish_post', 'delete_post', 'schedule_post', 'list_authors', 'get_author', 'manage_author', 'upload_file', 'upload_image', 'get_page_meta', 'update_page_meta', 'get_ai_txt', 'update_ai_txt', 'get_usage_tips'],   // Allowed MCP tools
];
