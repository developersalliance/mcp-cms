# Configuration cascade

Where a value comes from, in precedence order (later wins for the things it owns):

1. **Engine defaults** — hardcoded fallbacks in core classes (e.g. `?? 'blog'`,
   `?? 10` posts per page). Ship with the engine; never edited on installs.
2. **`config/config.php`** — written by `install.php`, per-install, excluded
   from git (`skip-worktree`). Holds identity and wiring: `root_dir`,
   `cms_dir`, `base_url`, `site_name`, `mcp_token`, directory paths. Read
   with `$config = include $cmsDir . '/config/config.php';`.
3. **Admin-saved settings** — stored under `settings/` or written back into
   `config.php` by admin pages (MCP tool grid `mcp_allowed_tools` /
   `mcp_disabled_tools`, AI provider settings, redirects, subscribers).
   Editable at runtime, still flat files.
4. **Site code** — `{root}/theme/hooks.php` (behavior via filters),
   `{root}/theme/mcp-tools.php` (extra MCP tools), `{root}/theme/...`
   template overrides. Highest precedence for what they cover, live in the
   SITE repo, never in the engine.

Rule of thumb: engine repo = code only; install state = config/ + settings/ +
content/; site customization = theme/. `git merge origin/main` on an install
must never touch layers 2-4.
