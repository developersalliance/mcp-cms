# WordPress-parity audit

What mcp-cms already covers of WordPress's built-ins, what is still done by hand
on installs, and how each gap can be automated within the flat-file architecture.
Audited 2026-09-07 against the live devall / dialbits / integrative-medicine installs.

## Already built in (often assumed missing)

| Feature | Where |
|---|---|
| Pagination | `core/Pagination.php`, used by `BlogRenderer::renderList()` (a theme override may bypass it, as devall's does with client-side filtering) |
| Scheduled publishing | `BlogManager::schedulePost()` + lazy publish on public render (`publishScheduledPosts()`), no cron needed |
| Category/tag filtering | `renderList()` honors `?category=` / `?tag=` server-side |
| Media library | `core/MediaIndex.php`: index, thumbnails/variants, alt/caption, search |
| Revisions + backups | `BackupManager`, per-post revisions, one-click rollback |
| Multi-author | `AuthorManager`, per-user roles, OAuth connections act as the CMS user |
| RSS feed | `blog/feed.php` stub per install |
| Sitemap | regenerated on publish/unpublish |
| SEO meta + JSON-LD | theme templates (BlogPosting, Breadcrumb) |
| AI authoring | MCP endpoint with OAuth 2.1 + static tokens; this is ahead of WordPress, not behind it |

## Genuine gaps vs WordPress, and how to automate each

Ordered by value for a typical install. Sizes: S = an evening, M = a few days.

### 1. Public site search (S)
No visitor-facing search. Automate: build `search-index.json` (slug, title,
excerpt, tokenized body) as a publish hook next to the sitemap regeneration;
ship a `blog/search/` stub that loads the index and ranks matches server-side.
No database needed; 25-500 posts is trivially in-memory.

### 2. Related posts (S)
Detail template shows none. Automate: score by shared category + tag overlap at
render time (posts are already loaded for the list); expose `$relatedPosts` to
`blog-detail.php` so themes can render a block. Zero storage.

### 3. Category/tag archive pages with own URLs (M)
`?category=x` works but has no SEO presence. Automate: on publish, generate
`blog/category/<slug>/index.php` stubs (same mechanism as post stubs) calling
`renderList()` with the filter preset; add canonical + title per archive and
include archives in the sitemap.

### 4. Newsletter subscriptions (M)
No backend (devall hides its signup box). Automate: `subscribe.php` endpoint
storing double-opt-in emails in a flat file, plus a publish hook that sends new
posts through the install's SMTP (or Brevo API); or skip storage entirely and
delegate to a Brevo form. Decide per install; engine should ship the endpoint.

### 5. Redirect manager (S)
Redirects are hand-edited `.htaccess`/nginx today. Automate: `redirects.json`
managed via admin + MCP tool (`add_redirect`), evaluated by a tiny check in the
front controller before 404. Keeps nginx/apache configs untouched.

### 6. Responsive images in post bodies (M)
Thumbnails exist but body images render at one size. Automate: generate 2-3
widths on upload (MediaIndex already writes variants) and rewrite `<img>` to
`srcset` at render time in `BlogRenderer`.

### 7. Comments — recommend NOT building
Spam moderation is the hidden cost that makes WordPress installs rot. If an
install truly needs discussion, embed a hosted widget; do not build storage.

### 8. Multilingual — defer
Real i18n (translated slugs, hreflang, per-language nav) is a project, not a
feature. Only justified by a concrete client need.

## Summary

Items 1, 2, and 5 are small and would close the most-felt gaps. Items 3, 4, 6
are medium and worth scheduling. 7 and 8 are deliberate non-goals. After 1-6 the
honest comparison with WordPress reduces to: ecosystem breadth and handover to
arbitrary maintainers (WordPress) versus security, speed, and AI-native
authoring (mcp-cms).
