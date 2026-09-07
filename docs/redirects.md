# Redirects

The redirect manager (Admin → Settings → Redirects) keeps a flat-file list of
URL redirects in `settings/redirects.json`:

```json
[
  { "from": "/old-page", "to": "/new-page", "code": 301, "created_at": "2026-09-07T12:00:00+00:00" }
]
```

- `from` is always a site-relative path starting with `/`.
- `to` is a site-relative path or an absolute `http(s)` URL. Protocol-relative
  targets (`//host/...`) and anything containing whitespace or control
  characters are rejected, so entries can never inject headers.
- `code` is `301` (permanent) or `302` (temporary).
- Loops are rejected on save: a redirect may not point at itself, and a chain
  of managed redirects may never arrive back where it started.

Two delivery mechanisms exist; use whichever matches the web server.

## Apache (automatic)

On every save the manager rewrites a managed block in the **site's** pub
`.htaccess` (`root_dir/.htaccess`, one level above the engine when the engine
is symlinked as `pub/cms`):

```apache
# CMS-REDIRECTS-BEGIN
# Managed by the CMS redirect manager — do not edit inside this block.
Redirect 301 /old-page /new-page
# CMS-REDIRECTS-END
```

Apache then answers the redirect natively, before PHP is involved. Rules:

- The block is only written when `.htaccess` already exists **and** is
  writable by the web server user. When it isn't, the admin page shows a
  warning and the JSON file stays authoritative — nothing breaks.
- Everything outside the markers is left untouched; edit the rest of the file
  freely.
- Don't hand-edit inside the markers — the next save replaces the block.

## nginx (manual wiring, one time)

nginx ignores `.htaccess`, so route "page not found" through the public
handler `redirect.php` at the engine root (served as `/cms/redirect.php`).
It looks the original request path up in `redirects.json` and issues the
301/302, or renders a minimal 404 page.

Add to the server block (alongside the recipe in `docs/nginx.conf.example`):

```nginx
    # Unmatched URLs flow through the CMS redirect handler.
    error_page 404 = /cms/redirect.php;

    location = /cms/redirect.php {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.0-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
```

And make static lookups fall into the 404 handler instead of nginx's own
error page:

```nginx
    location / {
        try_files $uri $uri/ =404;
    }
```

(With the stock `try_files ... /index.php?$query_string` fallback, requests
already reach PHP; in that setup have your front controller send unknown
paths to `/cms/redirect.php?path=<original path>` instead, or keep the
`error_page` form above.)

The handler reads the path from `?path=` when given, otherwise from the
original `REQUEST_URI` (which nginx preserves for `error_page` targets).

Notes:

- Matching is exact-path first, then the same path with the trailing slash
  toggled. Query strings are ignored for matching and are not carried over.
- The handler re-validates every target before emitting the `Location`
  header: same-site relative paths or absolute http(s) URLs only.
- On Apache the handler is a harmless fallback — the `.htaccess` block
  answers first.
