# Newsletter subscriptions

A minimal double-opt-in mailing list, stored flat-file in
`settings/subscribers.json`:

```json
[
  { "email": "reader@example.com", "status": "confirmed", "token": "…",
    "created_at": "2026-09-07T12:00:00+00:00", "confirmed_at": "2026-09-07T12:05:00+00:00" }
]
```

## Signup form

Point a plain HTML form at the public endpoint `/cms/subscribe.php`
(the engine-root file `subscribe.php`):

```html
<form method="post" action="/cms/subscribe.php">
  <input type="email" name="email" required placeholder="you@example.com">
  <!-- honeypot: keep hidden, must stay empty -->
  <input type="text" name="website" style="display:none" tabindex="-1" autocomplete="off">
  <button type="submit">Subscribe</button>
</form>
```

The endpoint:

- **POST `email=…`** — validates the address, stores it as `pending` and
  mails a confirmation link. The response is the same whether the address is
  new, pending, or already confirmed, so the endpoint can't be used to probe
  who is subscribed. Spam protection: the `website` honeypot must be empty,
  and each IP gets at most 5 signup attempts per hour (tracked in
  `settings/.subscribe-rate.json`).
- **GET `?confirm=<token>`** — flips the subscriber to `confirmed`.
- **GET `?unsubscribe=<token>`** — removes the subscriber.

Tokens are HMAC-SHA256 signatures over the email + purpose, keyed with a
SHA-256 derivative of the install's `mcp_token` — unguessable, and the raw
`mcp_token` never appears in any mail. Every outgoing mail (confirmation and
post notifications alike) carries the signed unsubscribe link.

## Notifying subscribers about a new post

`SubscriberManager::notifyNewPost(array $post, array $config): int` sends a
short plain-text mail (title, excerpt, link, unsubscribe footer) to every
confirmed subscriber via PHP `mail()` and returns the number of mails
accepted. `$post` should carry `title`, an optional `excerpt` (or
`description`), and a link as `url`/`link` (absolute or site-relative) or a
`slug` (linked as `/blog/<slug>`).

```php
require_once $config['cms_dir'] . '/core/SubscriberManager.php';
$subscribers = new SubscriberManager($config['cms_dir'] . '/settings', $config);
$sent = $subscribers->notifyNewPost([
    'title' => $post['title'],
    'excerpt' => $post['excerpt'] ?? '',
    'url' => '/blog/' . $post['slug'],
], $config);
```

The `From:` address is the config's `email` when present, otherwise
`noreply@<host of base_url>`, displayed with the config's `site_name`.
Delivery uses whatever `mail()` is wired to on the host (sendmail/postfix);
on hosts without a working MTA the calls simply return 0 sent.

## Admin

Admin → Settings → Subscribers lists all addresses with their status,
allows deleting an address, and exports the list as CSV
(`admin/subscribers.php?export=csv`). Requires the `settings.manage`
capability.
