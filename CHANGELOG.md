# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project aims to follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
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
