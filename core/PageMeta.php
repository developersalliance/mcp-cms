<?php
/**
 * PageMeta — read and write page <head> metadata.
 *
 * Works on the raw page HTML (string in, string out). Does not depend on
 * CMS:BLOCK markers — uses regex on individual tags inside <head> so it
 * can edit any page regardless of whether the operator wrapped meta in
 * blocks. JSON-LD scripts inside <head> are also handled.
 */

class PageMeta
{
    /**
     * Extract structured metadata from page HTML.
     *
     * @return array {
     *     title, description, keywords, canonical, robots, author, viewport, charset, theme_color,
     *     og: { type, title, description, url, image, site_name, locale, ... },
     *     twitter: { card, title, description, image, site, creator, ... },
     *     json_ld: [ ... raw decoded objects ... ],
     *     ai: { ... any <meta name="ai-*"> tag ... },
     *     generator: string|null,
     *     other: { name|property => content }   (anything not classified above)
     * }
     */
    public function extract(string $html): array
    {
        $head = $this->headOf($html);
        $out = [
            'title' => null, 'description' => null, 'keywords' => null,
            'canonical' => null, 'robots' => null, 'author' => null,
            'viewport' => null, 'charset' => null, 'theme_color' => null,
            'generator' => null,
            'og' => [], 'twitter' => [], 'ai' => [],
            'json_ld' => [], 'other' => [],
        ];
        if ($head === '') return $out;

        // <title>
        if (preg_match('#<title\b[^>]*>(.*?)</title>#is', $head, $m)) {
            $out['title'] = $this->decode(trim($m[1]));
        }
        // <link rel="canonical" href="...">
        if (preg_match('#<link\b[^>]*\brel=(["\'])canonical\1[^>]*\bhref=(["\'])(.*?)\2[^>]*>#is', $head, $m)
            || preg_match('#<link\b[^>]*\bhref=(["\'])(.*?)\1[^>]*\brel=(["\'])canonical\3[^>]*>#is', $head, $m)) {
            $out['canonical'] = $this->decode($m[3] ?? $m[2]);
        }
        // <meta charset="...">  (rare HTML5 short form)
        if (preg_match('#<meta\b[^>]*\bcharset=(["\'])(.*?)\1[^>]*>#is', $head, $m)) {
            $out['charset'] = $this->decode($m[2]);
        }

        // Walk every <meta ...> tag
        if (preg_match_all('#<meta\b([^>]*)>#is', $head, $tags)) {
            foreach ($tags[1] as $attrs) {
                $name = $this->attr($attrs, 'name');
                $property = $this->attr($attrs, 'property');
                $content = $this->attr($attrs, 'content');
                if ($content === null) continue;
                $content = $this->decode($content);

                if ($property !== null) {
                    $property = strtolower($property);
                    if (str_starts_with($property, 'og:')) {
                        $out['og'][substr($property, 3)] = $content;
                    } elseif (str_starts_with($property, 'twitter:')) {
                        $out['twitter'][substr($property, 8)] = $content;
                    } else {
                        $out['other'][$property] = $content;
                    }
                    continue;
                }

                if ($name === null) continue;
                $name = strtolower($name);
                if (str_starts_with($name, 'twitter:')) {
                    $out['twitter'][substr($name, 8)] = $content;
                } elseif (str_starts_with($name, 'og:')) {
                    $out['og'][substr($name, 3)] = $content;
                } elseif (str_starts_with($name, 'ai-') || str_starts_with($name, 'ai:')) {
                    $out['ai'][substr($name, 3)] = $content;
                } elseif (in_array($name, ['description', 'keywords', 'robots', 'author', 'viewport', 'theme-color', 'generator'], true)) {
                    $key = ($name === 'theme-color') ? 'theme_color' : $name;
                    $out[$key] = $content;
                } else {
                    $out['other'][$name] = $content;
                }
            }
        }

        // <script type="application/ld+json">…</script>
        if (preg_match_all('#<script\b[^>]*\btype=(["\'])application/ld\+json\1[^>]*>(.*?)</script>#is', $head, $scripts)) {
            foreach ($scripts[2] as $raw) {
                $decoded = json_decode(trim($raw), true);
                $out['json_ld'][] = $decoded !== null ? $decoded : trim($raw);
            }
        }

        return $out;
    }

    /**
     * Apply partial updates to page HTML. Only modifies the <head> section.
     * Unknown / null values are skipped. Returns new HTML.
     *
     * Supported keys: title, description, keywords, canonical, robots, author,
     * viewport, theme_color (or theme-color), generator,
     * og (associative array of sub-keys),
     * twitter (associative array),
     * ai (associative array — written as <meta name="ai-<sub>" content="…">),
     * json_ld (array of decoded objects/strings — replaces ALL existing
     *   <script type="application/ld+json"> blocks).
     */
    public function apply(string $html, array $updates): string
    {
        $headStart = stripos($html, '<head');
        $headEnd = stripos($html, '</head>');
        if ($headStart === false || $headEnd === false || $headEnd < $headStart) {
            return $html; // No <head> to patch.
        }
        $bodyStart = strpos($html, '>', $headStart);
        if ($bodyStart === false) return $html;
        $headBefore = substr($html, 0, $bodyStart + 1);
        $headBody   = substr($html, $bodyStart + 1, $headEnd - ($bodyStart + 1));
        $headAfter  = substr($html, $headEnd);

        // 1. <title>
        if (array_key_exists('title', $updates) && $updates['title'] !== null) {
            $headBody = $this->setOrInsertTitle($headBody, (string)$updates['title']);
        }

        // 2. <link rel="canonical" href="…">
        if (array_key_exists('canonical', $updates) && $updates['canonical'] !== null) {
            $headBody = $this->setOrInsertLink($headBody, 'canonical', (string)$updates['canonical']);
        }

        // 3. plain <meta name="…" content="…"> tags
        $simpleMap = [
            'description' => 'description',
            'keywords'    => 'keywords',
            'robots'      => 'robots',
            'author'      => 'author',
            'viewport'    => 'viewport',
            'generator'   => 'generator',
            'theme_color' => 'theme-color',
            'theme-color' => 'theme-color',
        ];
        foreach ($simpleMap as $key => $metaName) {
            if (array_key_exists($key, $updates) && $updates[$key] !== null) {
                $headBody = $this->setOrInsertMetaName($headBody, $metaName, (string)$updates[$key]);
            }
        }

        // 4. OG (Open Graph)
        if (!empty($updates['og']) && is_array($updates['og'])) {
            foreach ($updates['og'] as $sub => $val) {
                if ($val === null) continue;
                $headBody = $this->setOrInsertMetaProperty($headBody, 'og:' . $sub, (string)$val);
            }
        }

        // 5. Twitter — convention is name="twitter:*"; some sites use property=
        if (!empty($updates['twitter']) && is_array($updates['twitter'])) {
            foreach ($updates['twitter'] as $sub => $val) {
                if ($val === null) continue;
                $headBody = $this->setOrInsertMetaName($headBody, 'twitter:' . $sub, (string)$val);
            }
        }

        // 6. AI-specific meta — name="ai-<sub>"
        if (!empty($updates['ai']) && is_array($updates['ai'])) {
            foreach ($updates['ai'] as $sub => $val) {
                if ($val === null) continue;
                $headBody = $this->setOrInsertMetaName($headBody, 'ai-' . $sub, (string)$val);
            }
        }

        // 7. JSON-LD — replace all existing scripts with the supplied list.
        // Encoded with JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        // unconditionally so a stray "</script>" inside any string value cannot
        // break out of the surrounding <script> block. String entries are
        // re-parsed before re-encoding when possible; raw strings that contain
        // "</" are rejected outright as a defense-in-depth check.
        if (array_key_exists('json_ld', $updates) && is_array($updates['json_ld'])) {
            $headBody = preg_replace('#\s*<script\b[^>]*\btype=(["\'])application/ld\+json\1[^>]*>.*?</script>#is', '', $headBody);
            $appended = '';
            $hexFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
            foreach ($updates['json_ld'] as $item) {
                if (is_string($item)) {
                    $trimmed = trim($item);
                    if ($trimmed === '') continue;
                    $decoded = json_decode($trimmed, true);
                    if ($decoded === null) continue; // skip un-parseable
                    $payload = json_encode($decoded, $hexFlags);
                } else {
                    $payload = json_encode($item, $hexFlags);
                }
                if (!is_string($payload) || strpos($payload, '</') !== false) continue;
                $appended .= "\n<script type=\"application/ld+json\">\n" . $payload . "\n</script>\n";
            }
            $headBody = rtrim($headBody) . $appended;
        }

        return $headBefore . $headBody . $headAfter;
    }

    // ---------- private helpers ----------

    private function headOf(string $html): string
    {
        if (!preg_match('#<head\b[^>]*>(.*?)</head>#is', $html, $m)) return '';
        return $m[1];
    }

    private function attr(string $attrs, string $name): ?string
    {
        if (preg_match('#\b' . preg_quote($name, '#') . '=(["\'])(.*?)\1#is', $attrs, $m)) {
            return $m[2];
        }
        if (preg_match('#\b' . preg_quote($name, '#') . '=([^\s>]+)#i', $attrs, $m)) {
            return $m[1];
        }
        return null;
    }

    private function decode(string $s): string
    {
        return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function setOrInsertTitle(string $head, string $value): string
    {
        $val = $this->esc($value);
        $new = '<title>' . $val . '</title>';
        if (preg_match('#<title\b[^>]*>.*?</title>#is', $head)) {
            return preg_replace('#<title\b[^>]*>.*?</title>#is', $new, $head, 1);
        }
        return $this->prepend($head, $new);
    }

    private function setOrInsertLink(string $head, string $rel, string $href): string
    {
        $val = $this->esc($href);
        $new = '<link rel="' . $rel . '" href="' . $val . '">';
        $patterns = [
            '#<link\b[^>]*\brel=(["\'])' . preg_quote($rel, '#') . '\1[^>]*\bhref=(["\']).*?\2[^>]*>#is',
            '#<link\b[^>]*\bhref=(["\']).*?\1[^>]*\brel=(["\'])' . preg_quote($rel, '#') . '\2[^>]*>#is',
        ];
        foreach ($patterns as $pat) {
            if (preg_match($pat, $head)) {
                return preg_replace($pat, $new, $head, 1);
            }
        }
        return $this->append($head, $new);
    }

    private function setOrInsertMetaName(string $head, string $name, string $content): string
    {
        $valC = $this->esc($content);
        $valN = $this->esc($name);
        $new = '<meta name="' . $valN . '" content="' . $valC . '">';
        $patterns = [
            '#<meta\b[^>]*\bname=(["\'])' . preg_quote($name, '#') . '\1[^>]*>#is',
        ];
        foreach ($patterns as $pat) {
            if (preg_match($pat, $head)) {
                return preg_replace($pat, $new, $head, 1);
            }
        }
        return $this->append($head, $new);
    }

    private function setOrInsertMetaProperty(string $head, string $property, string $content): string
    {
        $valC = $this->esc($content);
        $valP = $this->esc($property);
        $new = '<meta property="' . $valP . '" content="' . $valC . '">';
        if (preg_match('#<meta\b[^>]*\bproperty=(["\'])' . preg_quote($property, '#') . '\1[^>]*>#is', $head)) {
            return preg_replace('#<meta\b[^>]*\bproperty=(["\'])' . preg_quote($property, '#') . '\1[^>]*>#is', $new, $head, 1);
        }
        // Some sites use name= for og:*; normalise existing name="og:*" if present
        if (preg_match('#<meta\b[^>]*\bname=(["\'])' . preg_quote($property, '#') . '\1[^>]*>#is', $head)) {
            return preg_replace('#<meta\b[^>]*\bname=(["\'])' . preg_quote($property, '#') . '\1[^>]*>#is', $new, $head, 1);
        }
        return $this->append($head, $new);
    }

    private function append(string $head, string $tag): string
    {
        return rtrim($head) . "\n  " . $tag . "\n";
    }

    private function prepend(string $head, string $tag): string
    {
        // After <meta charset…> + viewport so insertion order stays sensible
        if (preg_match('#(<meta\b[^>]*\bcharset=[^>]*>)#is', $head, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            return substr($head, 0, $pos) . "\n  " . $tag . substr($head, $pos);
        }
        return "  " . $tag . "\n" . $head;
    }
}
