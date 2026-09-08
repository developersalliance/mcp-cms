# Hooks: actions and filters

WordPress-style extension points, sized for a flat-file engine: two verbs,
one registry, zero magic. A site customizes engine behavior from its own
repo instead of patching engine files, so `git pull` on the engine never
conflicts with customizations.

## Where hooks live

Create `{root_dir}/theme/hooks.php` in the site's web root (the same
`theme/` directory that holds template overrides). The engine loads it once
per request from every entry path: the public blog renderer, the MCP
endpoint, the subscribe and redirect endpoints, and every admin page.

```php
<?php
// {root_dir}/theme/hooks.php

Hooks::on('post.published', function (string $collectionId, string $slug, ?array $post) {
    error_log("Published: {$collectionId}/{$slug}");
});

Hooks::filter('render.post_content', function (string $html, array $post) {
    return $html . '<p class="post-footer-note">Thanks for reading.</p>';
});
```

A hooks file that throws (or fails to parse at include time in a way PHP
can catch) is logged with `error_log()` and skipped; the engine keeps
running without it.

## API

Defined in `core/Hooks.php`.

```php
Hooks::on(string $event, callable $cb, int $priority = 10): void
Hooks::filter(string $event, callable $cb, int $priority = 10): void  // alias of on()
Hooks::do(string $event, ...$args): void
Hooks::apply(string $event, $value, ...$args)                          // returns the filtered value
Hooks::boot(string $rootDir, string $cmsDir = ''): void                // engine-internal
```

- `on()` registers a callback. `filter()` is the same function under a name
  that reads better when the event is a filter.
- Lower `$priority` runs first. Callbacks with equal priority run in
  registration order.
- `do()` fires an action: every callback receives `...$args`, return values
  are ignored.
- `apply()` runs a filter chain: each callback receives
  `($value, ...$args)` and must return the value for the next callback.
  Whatever the last callback returns is the result.
- A callback that throws never breaks the engine. The exception is logged;
  for filters the value passes through unchanged past the failing callback.
- `boot()` is called by the engine's entry points. It is idempotent (the
  site hooks file loads at most once per request) and never fatal. Sites do
  not call it.

## Events

### Actions

| Event | Callback signature | Fired |
|---|---|---|
| `post.saved` | `($collectionId, $slug, $post)` | After every post write in `BlogManager::savePost` (content saves, and the status-only saves inside publish/unpublish/schedule). `$post` is the normalized array as written. |
| `post.published` | `($collectionId, $slug, $post)` | At the end of `BlogManager::publishPost`, after the stub, sitemap and newsletter work. `$post` is re-read from disk (includes `newsletter_notified_at` when mails went out); `null` only if the file vanished mid-request. |
| `post.unpublished` | `($collectionId, $slug)` | At the end of `BlogManager::unpublishPost`. |
| `mcp.tool.before` | `($name, $input)` | Before an MCP tool handler runs, on both the REST (`?tool=`) and JSON-RPC (`tools/call`) paths. `$input` is the argument array. |
| `mcp.tool.after` | `($name, $input, $result)` | After the handler returns (or throws). `$result` is the REST-shaped result array; failures carry `['success' => false, 'error' => ...]`. Legacy handlers that emit their REST response and exit themselves skip this event on the REST path. |

### Filters

| Event | Callback signature | Applied |
|---|---|---|
| `render.post_content` | `($html, $post): string` | In `BlogRenderer::renderDetail`, after the engine's srcset pass, before the template renders. Return the (possibly rewritten) body HTML. |
| `render.page_output` | `($html, $context): string` | To the final buffered output of both `renderDetail` and `renderList`, after meta patching. `$context = ['type' => 'detail', 'collection_id' => ..., 'slug' => ...]` or `['type' => 'list', 'collection_id' => ...]`. |
| `sitemap.urls` | `($urls): array` | In `SitemapGenerator::generate`, before the XML is built. `$urls` is a list of `['loc' => ..., 'lastmod' => ..., 'priority' => ...]`. Add, remove or rewrite entries; return the array. |
| `search.results` | `($results, $q): array` | At the end of `BlogManager::searchPosts`. `$results` is the ranked post list (best match first), `$q` the query. Re-rank, trim or extend; return the array. |
| `mail.transport` | `($handled, $message, $config)` | Around every outgoing newsletter mail in `SubscriberManager` (confirmation mails and new-post notifications). See below. |

### mail.transport

The engine's default transport is PHP `mail()`. A site hook takes over
delivery by returning a boolean; returning `null` (or not registering the
filter) keeps the engine default.

The chain starts with `$handled = null`. Each callback receives
`($handled, $message, $config)`:

- return `true`: you delivered the mail; `mail()` is skipped, the send
  counts as successful.
- return `false`: you own delivery and it failed; `mail()` is skipped, the
  send counts as failed.
- return `null`: pass; the next callback (or the `mail()` fallback) decides.

`$message` shape (all values already sanitized against header injection):

```php
[
    'to'      => 'reader@example.com',       // recipient address
    'subject' => 'Site: New post title',      // single line
    'body'    => "Plain text body...\n",     // includes the unsubscribe link
    'headers' => [                            // raw header lines
        'Content-Type: text/plain; charset=UTF-8',
        'From: "Site Name" <noreply@example.com>',  // present when a from address exists
    ],
    'from'    => 'noreply@example.com',       // bare from address, '' when none derived
]
```

`$config` is the install's `config/config.php` array.

## Example: newsletter mail via SMTP

The classic case: the site already sends its contact form through SMTP
credentials, and the newsletter should use the same route instead of PHP
`mail()`. With PHPMailer available (for example via the site's composer
vendor dir):

```php
<?php
// {root_dir}/theme/hooks.php

Hooks::filter('mail.transport', function ($handled, array $message, array $config) {
    if ($handled !== null) return $handled;  // an earlier hook already took over

    require_once __DIR__ . '/../vendor/autoload.php';

    $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mailer->isSMTP();
        $mailer->Host       = 'smtp.example.com';
        $mailer->SMTPAuth   = true;
        $mailer->Username   = 'newsletter@example.com';
        $mailer->Password   = getenv('SMTP_PASSWORD') ?: '';
        $mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->Port       = 587;

        $from = $message['from'] !== '' ? $message['from'] : 'newsletter@example.com';
        $mailer->setFrom($from, (string)($config['site_name'] ?? ''));
        $mailer->addAddress($message['to']);
        $mailer->Subject = $message['subject'];
        $mailer->Body    = $message['body'];
        $mailer->CharSet = 'UTF-8';

        $mailer->send();
        return true;               // delivered: skip the mail() fallback
    } catch (Throwable $e) {
        error_log('SMTP newsletter mail failed: ' . $e->getMessage());
        return false;              // we own delivery and it failed
    }
});
```

Return `null` instead of `false` in the catch branch if you would rather
have PHP `mail()` retried as a fallback after an SMTP failure.

## Example: add a line to every post

Append a signature block to every rendered post body without touching any
template:

```php
<?php
// {root_dir}/theme/hooks.php

Hooks::filter('render.post_content', function (string $html, array $post) {
    $author = $post['_author']['name'] ?? '';
    $note = $author !== ''
        ? 'Written by ' . htmlspecialchars($author) . '. Questions? Reply to any newsletter mail.'
        : 'Questions? Reply to any newsletter mail.';
    return $html . "\n<p class=\"post-signature\"><em>" . $note . '</em></p>';
});
```

The filter runs after the engine's responsive-image pass, so the appended
HTML is delivered verbatim; keep it to markup the site's post styles cover.
