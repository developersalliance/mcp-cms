# MCP CMS

A flat-file PHP CMS that integrates with AI editors via the Model Context Protocol (MCP). No database, no build step, no service dependencies. Drop the files into any PHP-capable web root, run the installer, and start editing pages, posts, and collections directly or through an AI client that speaks MCP.

## Requirements

- PHP 8.0 or newer
- PHP extensions: `gd`, `json`, `mbstring`, `dom`, `libxml`, `fileinfo`
- A web server that can execute PHP (nginx + PHP-FPM or Apache + mod_php both work)
- Writable directories at install time:
  - `cms/`
  - `config/`
  - `drafts/`
  - `backups/`
  - `uploads/`

## Install

1. Clone or unzip the release into your web root so the files live at `<webroot>/cms/`.

   Note: the `/cms/` path is currently required. Making the mount path configurable is on the roadmap for a future release.

2. Make sure the directories listed in Requirements are writable by the web server user.

3. Visit `https://yoursite/cms/install.php` in a browser and follow the prompts. The installer copies `config/config.example.php` and `config/users.example.json` into place, generates secrets, and creates the first admin user.

4. Delete or restrict access to `install.php` after first run.

## Features

- Pages with `CMS:BLOCK` markers so editable regions live inside your existing HTML templates without polluting the markup
- Drafts and publish flow with versioned backups
- Blog collections with hierarchical categories and drag-reorder
- Per-post SEO metadata (title, description, og_image, canonical URL, JSON-LD)
- TinyMCE WYSIWYG editor for post content with collection-template CSS inheritance, so the editor preview matches the live page
- AI-assisted editing through a configurable provider (Anthropic, OpenAI, or Gemini) for content drafting, SEO suggestions, and image alt text
- MCP API endpoint for external AI clients (e.g. Claude Desktop, Claude Code) to read and edit content directly
- Flat-file storage: every page, draft, and config value lives in a file you can read, diff, and commit

## Security

See [SECURITY.md](SECURITY.md) for the responsible-disclosure process and a summary of recent hardening work.

## License

MIT. See [LICENSE](LICENSE).

## Acknowledgements

Third-party libraries and fonts are listed in [ATTRIBUTIONS.md](ATTRIBUTIONS.md).
