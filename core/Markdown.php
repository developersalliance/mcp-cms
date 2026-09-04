<?php
/**
 * Markdown — small dependency-free Markdown → HTML converter for post bodies.
 *
 * Covers what LLM-written articles actually use: ATX headings, paragraphs,
 * emphasis / strong / inline code, links, images, nested (one level) ordered
 * and unordered lists, blockquotes, fenced code blocks, horizontal rules,
 * pipe tables and hard line breaks. Raw HTML is escaped, except inline tags
 * from BlogManager::getAllowedTags() which pass through untouched so
 * authors can mix in <figure>, <iframe> etc. Output is still run through
 * BlogManager's sanitizer on save.
 */

class Markdown
{
    private static ?array $allowedTags = null;

    public static function toHtml(string $md): string
    {
        $md = str_replace(["\r\n", "\r"], "\n", $md);
        $lines = explode("\n", $md);
        $out = [];
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $line = $lines[$i];
            $trim = trim($line);

            // blank
            if ($trim === '') { $i++; continue; }

            // fenced code
            if (preg_match('/^```\s*([\w+-]*)\s*$/', $trim, $m)) {
                $lang = $m[1];
                $buf = [];
                $i++;
                while ($i < $n && !preg_match('/^```\s*$/', trim($lines[$i]))) { $buf[] = $lines[$i]; $i++; }
                $i++; // closing fence
                $cls = $lang !== '' ? ' class="language-' . htmlspecialchars($lang, ENT_QUOTES) . '"' : '';
                $out[] = '<pre><code' . $cls . '>' . htmlspecialchars(implode("\n", $buf), ENT_QUOTES | ENT_HTML5) . '</code></pre>';
                continue;
            }

            // horizontal rule
            if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trim)) { $out[] = '<hr>'; $i++; continue; }

            // ATX heading
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*$/', $trim, $m)) {
                $level = strlen($m[1]);
                $text = self::inline($m[2]);
                $out[] = "<h{$level}>{$text}</h{$level}>";
                $i++; continue;
            }

            // blockquote
            if (preg_match('/^>\s?/', $trim)) {
                $buf = [];
                while ($i < $n && preg_match('/^\s*>\s?(.*)$/', $lines[$i], $m)) { $buf[] = $m[1]; $i++; }
                $out[] = '<blockquote>' . self::toHtml(implode("\n", $buf)) . '</blockquote>';
                continue;
            }

            // pipe table: header row + separator row
            if (str_contains($trim, '|') && $i + 1 < $n && preg_match('/^\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?$/', trim($lines[$i + 1]))) {
                $header = self::splitRow($trim);
                $i += 2;
                $rows = [];
                while ($i < $n && str_contains($lines[$i], '|') && trim($lines[$i]) !== '') { $rows[] = self::splitRow(trim($lines[$i])); $i++; }
                $html = '<table><thead><tr>';
                foreach ($header as $h) $html .= '<th>' . self::inline($h) . '</th>';
                $html .= '</tr></thead><tbody>';
                foreach ($rows as $r) {
                    $html .= '<tr>';
                    foreach ($r as $c) $html .= '<td>' . self::inline($c) . '</td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
                $out[] = $html;
                continue;
            }

            // lists (one nesting level, 2+ spaces or a tab for nested items)
            if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $line)) {
                $out[] = self::parseList($lines, $i);
                continue;
            }

            // block-level HTML passthrough (allowed tags only)
            if (preg_match('/^<(\/?)([a-zA-Z][a-zA-Z0-9]*)/', $trim, $m) && in_array(strtolower($m[2]), self::allowedTags(), true)) {
                $buf = [];
                while ($i < $n && trim($lines[$i]) !== '') { $buf[] = $lines[$i]; $i++; }
                $out[] = implode("\n", $buf);
                continue;
            }

            // paragraph
            $buf = [];
            while ($i < $n && trim($lines[$i]) !== ''
                && !preg_match('/^(#{1,6}\s|```|>|(\s*)([-*+]|\d+[.)])\s+|(-{3,}|\*{3,}|_{3,})$)/', trim($lines[$i]))) {
                $buf[] = $lines[$i];
                $i++;
            }
            if ($buf === []) { $buf[] = $line; $i++; }
            $text = implode("\n", $buf);
            // hard line breaks: two trailing spaces or backslash
            $text = preg_replace('/( {2,}|\\\\)\n/', "<br>\n", $text);
            $out[] = '<p>' . self::inline($text) . '</p>';
        }

        return implode("\n", $out);
    }

    private static function parseList(array $lines, int &$i): string
    {
        $n = count($lines);
        $items = []; // [indent, ordered, text, children[]]
        $baseIndent = null;
        while ($i < $n) {
            $line = $lines[$i];
            if (trim($line) === '') {
                // list ends on a blank line followed by a non-list line
                $j = $i + 1;
                if ($j < $n && preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $lines[$j])) { $i++; continue; }
                break;
            }
            if (!preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $line, $m)) {
                // continuation line of the previous item
                if ($items) { $items[count($items) - 1]['text'] .= ' ' . trim($line); $i++; continue; }
                break;
            }
            $indent = strlen(str_replace("\t", '    ', $m[1]));
            if ($baseIndent === null) $baseIndent = $indent;
            $ordered = (bool)preg_match('/^\d/', $m[2]);
            if ($indent > $baseIndent && $items) {
                $items[count($items) - 1]['children'][] = ['ordered' => $ordered, 'text' => $m[3]];
            } elseif ($indent < $baseIndent) {
                break;
            } else {
                $items[] = ['ordered' => $ordered, 'text' => $m[3], 'children' => []];
            }
            $i++;
        }
        if (!$items) return '';
        $tag = $items[0]['ordered'] ? 'ol' : 'ul';
        $html = "<{$tag}>";
        foreach ($items as $it) {
            $html .= '<li>' . self::inline($it['text']);
            if ($it['children']) {
                $ctag = $it['children'][0]['ordered'] ? 'ol' : 'ul';
                $html .= "<{$ctag}>";
                foreach ($it['children'] as $c) $html .= '<li>' . self::inline($c['text']) . '</li>';
                $html .= "</{$ctag}>";
            }
            $html .= '</li>';
        }
        return $html . "</{$tag}>";
    }

    private static function splitRow(string $row): array
    {
        $row = preg_replace('/^\||\|$/', '', trim($row));
        return array_map('trim', explode('|', $row));
    }

    /** Inline markdown → HTML. Escapes raw HTML except allowed inline tags. */
    public static function inline(string $text): string
    {
        $codes = [];
        // protect inline code first
        $text = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes) {
            $codes[] = '<code>' . htmlspecialchars($m[1], ENT_QUOTES | ENT_HTML5) . '</code>';
            return "\x00" . (count($codes) - 1) . "\x00";
        }, $text);

        // protect allowed inline HTML tags, escape everything else
        $allowed = self::allowedTags();
        $text = preg_replace_callback('/<(\/?)([a-zA-Z][a-zA-Z0-9]*)([^<>]*)>/', function ($m) use ($allowed, &$codes) {
            if (in_array(strtolower($m[2]), $allowed, true)) {
                $codes[] = $m[0];
                return "\x00" . (count($codes) - 1) . "\x00";
            }
            return htmlspecialchars($m[0], ENT_QUOTES | ENT_HTML5);
        }, $text);
        // stray angle brackets / ampersands
        $text = preg_replace('/&(?![a-zA-Z#][a-zA-Z0-9]*;)/', '&amp;', $text);
        $text = str_replace(['<', '>'], ['&lt;', '&gt;'], $text);

        // images before links
        $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/', function ($m) {
            $alt = htmlspecialchars($m[1], ENT_QUOTES);
            $src = htmlspecialchars($m[2], ENT_QUOTES);
            $title = isset($m[3]) && $m[3] !== '' ? ' title="' . htmlspecialchars($m[3], ENT_QUOTES) . '"' : '';
            return "<img src=\"{$src}\" alt=\"{$alt}\"{$title} loading=\"lazy\">";
        }, $text);
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/', function ($m) {
            $href = htmlspecialchars($m[2], ENT_QUOTES);
            $title = isset($m[3]) && $m[3] !== '' ? ' title="' . htmlspecialchars($m[3], ENT_QUOTES) . '"' : '';
            return "<a href=\"{$href}\"{$title}>{$m[1]}</a>";
        }, $text);
        // autolinks
        $text = preg_replace('/(?<![="\'>])\bhttps?:\/\/[^\s<]+[^\s<.,;:!?)\]]/', '<a href="$0">$0</a>', $text);

        // strong / em / strikethrough
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.+?)__/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $text);
        $text = preg_replace('/(?<![\w_])_(?!\s)(.+?)(?<!\s)_(?![\w_])/s', '<em>$1</em>', $text);
        $text = preg_replace('/~~(.+?)~~/s', '<s>$1</s>', $text);

        // restore protected fragments
        $text = preg_replace_callback('/\x00(\d+)\x00/', fn($m) => $codes[(int)$m[1]], $text);
        return $text;
    }

    private static function allowedTags(): array
    {
        if (self::$allowedTags === null) {
            if (!class_exists('BlogManager')) {
                require_once __DIR__ . '/BlogManager.php';
            }
            self::$allowedTags = BlogManager::getAllowedTags();
        }
        return self::$allowedTags;
    }
}
