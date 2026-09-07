<?php
/**
 * SubscriberManager — flat-file newsletter subscriptions (double opt-in).
 *
 * File: {cms_dir}/settings/subscribers.json — a JSON list of entries:
 *   { email, status (pending|confirmed), token, created_at, confirmed_at }
 *
 * Tokens are deterministic HMACs over the email + purpose, keyed with a
 * derivative of the config mcp_token (the raw token never appears in a mail):
 *   confirm token     = HMAC(email + '|confirm')      — stored on the entry
 *   unsubscribe token = HMAC(email + '|unsubscribe')  — computed on demand
 *
 * Flow: subscribe() stores a pending entry, the public endpoint mails the
 * confirm link, confirm() flips the entry to confirmed. Every outgoing mail
 * carries the signed unsubscribe link. notifyNewPost() mails all confirmed
 * subscribers about a freshly published post via PHP mail().
 *
 * Writes are committed with an atomic rename so concurrent signups never
 * clobber each other.
 */
class SubscriberManager
{
    private string $file;
    private array $config;
    private string $hmacKey;

    public function __construct(string $settingsDir, array $config = [])
    {
        $this->file = rtrim($settingsDir, '/') . '/subscribers.json';
        $this->config = $config;
        // Key material derived from (never equal to) the install's mcp_token.
        $this->hmacKey = hash('sha256', 'mcp-cms-subscribers:' . (string)($config['mcp_token'] ?? ''));
    }

    public function path(): string
    {
        return $this->file;
    }

    /** @return array<int, array<string, mixed>> newest first */
    public function listSubscribers(): array
    {
        $items = $this->read();
        usort($items, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
        return $items;
    }

    public function getSubscriber(string $email): ?array
    {
        $email = self::normalizeEmail($email);
        foreach ($this->read() as $s) {
            if (($s['email'] ?? null) === $email) return $s;
        }
        return null;
    }

    /**
     * Add a pending subscription. Idempotent: an already-confirmed address
     * stays confirmed, an already-pending one keeps its entry. Throws on an
     * invalid address. Returns ['subscriber' => entry, 'created' => bool].
     */
    public function subscribe(string $email): array
    {
        $email = self::normalizeEmail($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new Exception('Please enter a valid email address');
        }

        $result = ['subscriber' => null, 'created' => false];
        $this->mutate(function (array &$items) use ($email, &$result) {
            foreach ($items as $s) {
                if (($s['email'] ?? null) === $email) {
                    $result['subscriber'] = $s;
                    return;
                }
            }
            $entry = [
                'email' => $email,
                'status' => 'pending',
                'token' => $this->token($email, 'confirm'),
                'created_at' => date('c'),
                'confirmed_at' => null,
            ];
            $items[] = $entry;
            $result['subscriber'] = $entry;
            $result['created'] = true;
        });
        return $result;
    }

    /** Confirm a pending subscription by its confirm token. Returns the entry or null. */
    public function confirm(string $token): ?array
    {
        if ($token === '') return null;
        $confirmed = null;
        $this->mutate(function (array &$items) use ($token, &$confirmed) {
            foreach ($items as $i => $s) {
                if (hash_equals((string)($s['token'] ?? ''), $token)) {
                    if (($s['status'] ?? '') !== 'confirmed') {
                        $items[$i]['status'] = 'confirmed';
                        $items[$i]['confirmed_at'] = date('c');
                    }
                    $confirmed = $items[$i];
                    return;
                }
            }
        });
        return $confirmed;
    }

    /** Remove a subscriber by their signed unsubscribe token. */
    public function unsubscribe(string $token): bool
    {
        if ($token === '') return false;
        $removed = false;
        $this->mutate(function (array &$items) use ($token, &$removed) {
            $items = array_values(array_filter($items, function ($s) use ($token, &$removed) {
                $expected = $this->token((string)($s['email'] ?? ''), 'unsubscribe');
                if (hash_equals($expected, $token)) {
                    $removed = true;
                    return false;
                }
                return true;
            }));
        });
        return $removed;
    }

    /** Admin-side removal by address. */
    public function deleteSubscriber(string $email): bool
    {
        $email = self::normalizeEmail($email);
        $removed = false;
        $this->mutate(function (array &$items) use ($email, &$removed) {
            $items = array_values(array_filter($items, function ($s) use ($email, &$removed) {
                if (($s['email'] ?? null) === $email) {
                    $removed = true;
                    return false;
                }
                return true;
            }));
        });
        return $removed;
    }

    /** Absolute URL of the public subscribe endpoint (…/cms/subscribe.php). */
    public function endpointUrl(): string
    {
        $base = rtrim((string)($this->config['base_url'] ?? ''), '/');
        return $base . $this->cmsUrlPrefix() . '/subscribe.php';
    }

    public function confirmUrl(array $subscriber): string
    {
        return $this->endpointUrl() . '?confirm=' . urlencode((string)($subscriber['token'] ?? ''));
    }

    public function unsubscribeUrl(string $email): string
    {
        return $this->endpointUrl() . '?unsubscribe=' . urlencode($this->token(self::normalizeEmail($email), 'unsubscribe'));
    }

    /** Send the double-opt-in confirmation mail. Returns true when mail() accepted it. */
    public function sendConfirmationMail(array $subscriber): bool
    {
        $email = (string)($subscriber['email'] ?? '');
        if ($email === '') return false;
        $siteName = (string)($this->config['site_name'] ?? 'our site');
        $subject = 'Confirm your subscription to ' . $siteName;
        $body = "Hi,\n\n"
            . "Someone (hopefully you) asked to subscribe this address to updates from " . $siteName . ".\n\n"
            . "Confirm your subscription:\n" . $this->confirmUrl($subscriber) . "\n\n"
            . "If this wasn't you, simply ignore this mail — nothing will be sent.\n\n"
            . "Didn't mean to sign up? Unsubscribe here:\n" . $this->unsubscribeUrl($email) . "\n";
        return $this->sendMail($email, $subject, $body);
    }

    /**
     * Mail all confirmed subscribers about a newly published post.
     * $post: expects title, and any of url|link|slug for the link, plus an
     * optional excerpt. Returns the number of mails mail() accepted.
     */
    public function notifyNewPost(array $post, array $config): int
    {
        $siteName = (string)($config['site_name'] ?? $this->config['site_name'] ?? 'our site');
        $baseUrl = rtrim((string)($config['base_url'] ?? $this->config['base_url'] ?? ''), '/');

        $title = trim((string)($post['title'] ?? 'A new post'));
        $excerpt = trim((string)($post['excerpt'] ?? $post['description'] ?? ''));
        $link = trim((string)($post['url'] ?? $post['link'] ?? ''));
        if ($link === '' && !empty($post['slug'])) {
            $link = '/blog/' . ltrim((string)$post['slug'], '/');
        }
        if ($link !== '' && !preg_match('#^https?://#i', $link)) {
            $link = $baseUrl . '/' . ltrim($link, '/');
        }

        $subject = $siteName . ': ' . $title;
        $sent = 0;
        foreach ($this->listSubscribers() as $s) {
            if (($s['status'] ?? '') !== 'confirmed') continue;
            $email = (string)($s['email'] ?? '');
            if ($email === '') continue;
            $body = $title . "\n"
                . str_repeat('=', mb_strlen($title)) . "\n\n"
                . ($excerpt !== '' ? $excerpt . "\n\n" : '')
                . ($link !== '' ? "Read the full post:\n" . $link . "\n\n" : '')
                . "--\nYou get these mails because you subscribed to updates from " . $siteName . ".\n"
                . "Unsubscribe: " . $this->unsubscribeUrl($email) . "\n";
            if ($this->sendMail($email, $subject, $body)) {
                $sent++;
            }
        }
        return $sent;
    }

    /** Deterministic signed token for an email + purpose. */
    public function token(string $email, string $purpose): string
    {
        return hash_hmac('sha256', self::normalizeEmail($email) . '|' . $purpose, $this->hmacKey);
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    // --- internals -------------------------------------------------------

    private function sendMail(string $to, string $subject, string $body): bool
    {
        // Addresses were validated with FILTER_VALIDATE_EMAIL (rejects CR/LF),
        // and the subject is folded to a single line against header injection.
        $subject = preg_replace('/[\r\n]+/', ' ', $subject);
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        $from = trim((string)($this->config['email'] ?? ''));
        if ($from === '') {
            $host = (string)(parse_url((string)($this->config['base_url'] ?? ''), PHP_URL_HOST) ?? '');
            if ($host !== '') {
                $from = 'noreply@' . preg_replace('/^www\./', '', $host);
            }
        }
        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) !== false) {
            $siteName = preg_replace('/[\r\n"]+/', '', (string)($this->config['site_name'] ?? ''));
            $headers[] = 'From: ' . ($siteName !== '' ? '"' . $siteName . '" <' . $from . '>' : $from);
        }
        return @mail($to, $subject, $body, implode("\r\n", $headers));
    }

    private function cmsUrlPrefix(): string
    {
        // install.php stores cms_dir = root_dir + the served URL prefix, so
        // the prefix falls out of the difference; default to /cms otherwise.
        $root = rtrim((string)($this->config['root_dir'] ?? ''), '/');
        $cms = rtrim((string)($this->config['cms_dir'] ?? ''), '/');
        if ($root !== '' && $cms !== '' && strpos($cms, $root) === 0 && $cms !== $root) {
            return substr($cms, strlen($root));
        }
        return '/cms';
    }

    private function read(): array
    {
        if (!is_file($this->file)) return [];
        $raw = @file_get_contents($this->file);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($data)) return [];
        $out = [];
        foreach ($data as $s) {
            if (!is_array($s) || empty($s['email'])) continue;
            $out[] = [
                'email' => (string)$s['email'],
                'status' => ($s['status'] ?? '') === 'confirmed' ? 'confirmed' : 'pending',
                'token' => (string)($s['token'] ?? ''),
                'created_at' => (string)($s['created_at'] ?? ''),
                'confirmed_at' => isset($s['confirmed_at']) && $s['confirmed_at'] !== null ? (string)$s['confirmed_at'] : null,
            ];
        }
        return $out;
    }

    private function mutate(callable $fn): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new Exception('Settings directory is not writable: ' . $dir);
        }
        if (!is_file($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "Require all denied\n"); }
        $items = $this->read();
        $fn($items);
        $tmp = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || @file_put_contents($tmp, $json) === false) {
            @unlink($tmp);
            throw new Exception('Cannot write subscribers file — check that ' . $dir . ' is writable');
        }
        if (!@rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new Exception('Cannot replace subscribers file');
        }
        @chmod($this->file, 0664);
    }
}
