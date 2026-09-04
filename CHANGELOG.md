# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project aims to follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **OAuth 2.1 sign-in for the MCP endpoint** (`core/OAuthServer.php`, `mcp/oauth/`): authorization-code + PKCE (S256), dynamic client registration, Client ID Metadata Documents, RFC 9728/8414 discovery (401 `resource_metadata` pointer, path-appended `.well-known`, root-level rewrites in `docs/oauth-well-known.md`), consent page reusing the admin login, rotating refresh tokens, per-user tokens carrying the user's role. ChatGPT (Developer mode), Claude.ai/Desktop, Gemini Spark/Enterprise and IDE clients now connect with the endpoint URL alone. **Connected apps** list with revoke on the MCP Config page; tokens are revoked when a user is edited or deleted.
- Role-aware MCP: `getMCPToolCapabilities()` maps write tools to admin capabilities; OAuth principals only see and can call tools their role allows. Static token remains owner.
- **MCP activity log** (`core/McpActivityLog.php`, `logs/mcp-activity.jsonl`) recording every write and every failed call, with an admin viewer (System → MCP Activity).
- **Media tools for LLMs**: `upload_image_from_url` (SSRF-guarded fetch), `list_media`, `update_media`, `delete_media`, `generate_image` (OpenAI / Gemini image models via the configured AI provider); `upload_image` takes `alt`/`name`/`caption`. New `content/media.json` index (`core/MediaIndex.php`) gives every picture a name and alt text; the media library and picker edit them inline.
- **Post tools for LLMs**: `create_post` accepts markdown (`content_format`), `status: published`, `published_at`, `subtitle`, `category` by name; responses carry `preview_url`, `public_url`, `next_steps` and `stripped` (what the sanitizer removed). `list_categories` / `create_category` / `update_category` / `delete_category`; `list_post_revisions` / `restore_post_revision`. Post revisions (`backups/posts/`) with a Revisions panel in the editor. `core/Markdown.php` dependency-free converter.
- **Template tools**: `list_templates`, `read_template`, `update_template` (site overrides in `theme/collection-templates/`, lint-checked, backed up).
- **MCP prompts** (`new_blog_post`, `add_picture_to_post`, `edit_site_copy`, `page_seo_review`) and **resources** (`cms://pages|posts|media|categories|usage-guide`, `cms://posts/{slug}`, `cms://pages/{id}/blocks`).
- Tool annotations (`title`, `readOnlyHint`, `destructiveHint`, `idempotentHint`) on every tool; `initialize.instructions` and `get_usage_tips` rewritten as job recipes; Writer / Developer tool presets.
- Tests: `tests/oauth-flow.php`, `media-tools.php`, `post-tools.php`, `markdown.php`, `response-hygiene.php`, `security-ops.php`, `prompts-resources.php`, `tool-tables.php`, `migrate-legacy-categories.php`.

### Changed
- One category model: posts always carry `categories: [{id, slug, name_snapshot}]` plus a derived `category` string for legacy themes; MCP accepts names/slugs/ids and auto-creates; `list_posts` filters by any of them. Run `tests/migrate-legacy-categories.php <cms_dir>` once on installs with legacy `category` strings.
- MCP responses no longer include absolute filesystem paths; `read_page` is capped (`max_chars`, `truncated`); draft-creating tools return `preview_url` + `next_steps`.
- Tool enablement is allow-list + deny-list (`mcp_disabled_tools`): tools added by an engine update stay enabled until an admin unticks them.
- `update_file_region` refuses PHP files unless `mcp_allow_php_edits` is on (Settings).
- The fake "ChatGPT Desktop config.json" preset is gone; ChatGPT and Claude.ai connect via OAuth.

### Fixed
- Media library format tabs (Alpine attribute quoting).
- Restore / Unpublish buttons in the post editor were nested forms.

### Added (2026-09-04)
- WordPress-style media picker (`admin/includes/media-picker.php`): pick from the library or upload in place, used by the post editor's new **Image** toolbar button, the **Set featured image** control (with preview / Replace / Remove) and the Open Graph image field. Images pasted or dropped into the post editor are uploaded and inserted at the caret.
- Gemini CLI and generic (Cursor / Windsurf / VS Code) presets on the MCP config page.
- `tests/mcp-smoke.php`: protocol-level smoke test for the MCP endpoint (`php tests/mcp-smoke.php <url> <token>`).

### Changed
- MCP endpoint is now a spec-compliant stateless Streamable HTTP server: negotiates `protocolVersion` (2025-06-18 / 2025-03-26 / 2024-11-05), answers notifications with `202` and no body, supports `ping`, empty `resources/list` / `prompts/list`, JSON-RPC batches, `-32700` parse errors, `Authorization: Bearer` tokens, CORS preflight; tool failures return `isError: true` results instead of `-32000` protocol errors; `tools/list` honours `mcp_allowed_tools` and normalises schemas for Gemini (no empty `required`, objects always carry `properties`, arrays always carry `items`).
- Image uploads keep photos as JPEG (quality 85, progressive) instead of converting everything to PNG; PNG/GIF/WebP sources still become PNG. `uploadImage()` now also returns flat `url`, `thumb_url`, `width`, `height`, `format` fields.
- Media library format tabs are data-driven (WebP / JPG / PNG / GIF) instead of hardcoded WebP + PNG.
- Post editor preview now honours the site's `theme/collection-templates/` overrides (previously only the engine's own templates were consulted for editor styling).

### Added (earlier)
- Hierarchical blog categories with drag-reorder (SortableJS) and cycle-safe re-parenting
- TinyMCE WYSIWYG editor for post content; inherits the collection template's CSS so the editing canvas matches the live render
- Per-post SEO meta (title / description / og_image / canonical / json_ld), with an "Update meta with AI" assistant
- Default collection templates ship in `collection-templates/default-{detail,list}.php` so the blog feature works on a fresh install
- Open-source metadata: `LICENSE`, `README.md`, `SECURITY.md`, `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `ATTRIBUTIONS.md`, `CHANGELOG.md`
- `composer.json` with minimum PHP + extension constraints
- GitHub Actions lint workflow (`.github/workflows/lint.yml`) running `php -l`

### Changed
- `config/config.php` and `config/users.json` are no longer tracked in git. Installers copy from `config/*.example.*` on first run.
- Removed the deprecated `BlogTemplateImporter` and the experimental `BlogBinder` auto-bind flow. Blog templates are hand-edited in **Collection Templates** with the variable cheatsheet panel as the documented path.

### Security
- **CRITICAL** Auth-guarded `admin/preview-backup.php`, `preview.php`, `preview-check.php` (were unauthenticated and could execute arbitrary backup PHP)
- **HIGH** Path-traversal validation on `page_id` in preview endpoints + new `PageManager::validatePageId`
- **HIGH** Case-insensitive reserved-folder check (`cms/`, `CMS/`, etc.)
- **MEDIUM** MCP token compared via `hash_equals`; auth check moved before rate-limit accounting; rate-limit JSON wrapped in `flock`
- **MEDIUM** `session_regenerate_id(true)` on login, explicit session cookie params (httponly / secure / samesite=Lax)
- **MEDIUM** SVG uploads disabled (regex sanitiser was incomplete; banning is simpler than patching)
- **MEDIUM** MCP handler executor filtered by `mcp_allowed_tools` (defense in depth against model emitting tool_use for disabled tools)
- **MEDIUM** Sibling-directory collision fix in backup-path prefix check
