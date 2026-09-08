# Extensibility plan: override everything, fork nothing

Goal: every install can tweak templates, behavior, and tools without editing
engine files, in the spirit of Magento's theme fallback, sized for a flat-file
PHP engine. The engine stays a clean git checkout on every install; `git merge
origin/main` never conflicts with customizations.

## What exists today

- `CollectionTheme`: site `theme/collection-templates/*` overrides engine
  `collection-templates/default-*.php`. Two files only (blog list/detail).
- Per-install state (config, content, settings) is already isolated from git.

## The observed pain that motivates this

devall's `blog-list.php` override is a full copy of the default. When the
engine's default template gained a search form, devall's copy did not: every
full-file override silently freezes at the feature level of the day it was
copied. The fix is smaller override units plus drift detection.

## Phase 1 — Theme resolver + partials (M)

1. **`Theme::resolve($relPath)`**: one resolver used for every include the
   engine renders. Chain: `{site}/theme/{relPath}` then `{engine}/{relPath}`.
   CollectionTheme becomes a thin wrapper over it.
2. **Extract partials** from the default templates so overrides can be
   surgical: `partials/post-row.php`, `partials/pagination.php`,
   `partials/related-posts.php`, `partials/newsletter-form.php`,
   `partials/search-form.php`, `partials/author-box.php`,
   `email/newsletter-confirm.php`, `email/newsletter-post.php`.
   Default templates include partials via the resolver; a site overrides one
   partial and keeps receiving improvements to everything else. Full-file
   overrides keep working (back-compat) but stop being the recommended path.
3. **Drift doctor**: `php tests/theme-diff.php` lists every site override, the
   engine default it shadows, and whether the default changed since the
   override was created (store the default's hash in a comment header at
   copy time; the admin Templates page shows a "default has moved on" badge).

## Phase 2 — Hooks: actions and filters (M)

WordPress-style, not Magento DI: two functions, one registry, zero magic.

- `Hooks::on('post.published', fn($post) => ...)` (actions)
- `Hooks::filter('render.post_content', fn($html, $post) => $html)` (filters)

Engine fires a small, documented set of events:

| Event | Type | Replaces the need to edit |
|---|---|---|
| `post.saved`, `post.published`, `post.unpublished` | action | BlogManager |
| `render.post_content` | filter | BlogRenderer (srcset is dogfooded here) |
| `render.head_meta` | filter | per-page SEO tweaks |
| `sitemap.urls` | filter | SitemapGenerator |
| `mail.transport` | filter | SubscriberManager (SMTP per install!) |
| `mcp.tool.before` / `mcp.tool.after` | action | activity hooks, notifications |
| `search.results` | filter | custom ranking |

Sites register hooks in **`{site}/theme/hooks.php`**, loaded once by the
engine bootstrap if present. This immediately solves the real devall case:
newsletter mail through the same SMTP as the contact form becomes a 15-line
site hook instead of an engine patch.

## Phase 3 — Site-defined MCP tools + config cascade (S/M)

1. **`{site}/theme/mcp-tools.php`** returns extra tool definitions + handlers;
   the MCP endpoint merges them (names prefixed `site_` to avoid collisions,
   permissions declared per tool). A client-specific automation ships without
   touching the engine.
2. **Config cascade formalized**: engine defaults → `config/config.php`
   (installer) → admin-editable settings. One documented precedence, one
   accessor.

## Non-goals (deliberately)

- Magento-style class rewrites / DI container: too much machinery for a
  flat-file engine; every real need above is covered by partials + hooks.
- A plugin marketplace or plugin update mechanism: sites are git checkouts;
  the site repo IS the plugin.
- Database anything.

## Sequence and effort

| Phase | Content | Size |
|---|---|---|
| 1 | Theme resolver, partials, drift doctor | a few days |
| 2 | Hooks core + 8-10 events, migrate newsletter/srcset internally | a few days |
| 3 | Site MCP tools, config cascade docs | 1-2 days |

Phase 2's `mail.transport` hook unblocks re-enabling the devall newsletter,
so 1 and 2 are worth scheduling together.
