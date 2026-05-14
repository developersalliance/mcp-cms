<?php
/**
 * CollectionTheme — pull the visual context of a collection's detail
 * template so we can mirror it inside the post-content WYSIWYG iframe.
 *
 * extract($collectionId) returns:
 *   - stylesheet_urls[]:  every <link rel="stylesheet"> href found in <head>
 *   - inline_css:         concatenated raw contents of every <style> block in <head>
 *   - ancestor_classes[]: outermost→innermost list of class strings on the
 *                         ancestors of the role=blog-post-body block. The
 *                         WYSIWYG renders the editable region wrapped in
 *                         nested divs reproducing this stack so descendant
 *                         selectors (.prose h2 { … }) keep matching.
 *   - has_tailwind_cdn:   true if a Tailwind CDN <script> is in <head>; the
 *                         client must snapshot + strip it before letting the
 *                         editor live with it (it scans on every keystroke).
 */

require_once __DIR__ . '/BlockParser.php';

class CollectionTheme
{
    private string $cmsDir;

    public function __construct(string $cmsDir)
    {
        $this->cmsDir = rtrim($cmsDir, '/');
    }

    public function extract(string $collectionId): array
    {
        $out = [
            'stylesheet_urls' => [],
            'inline_css' => '',
            'ancestor_classes' => [],
            'has_tailwind_cdn' => false,
            'template_found' => false,
        ];
        $path = $this->resolveTemplate($collectionId);
        if (!$path) return $out;
        $out['template_found'] = true;
        $html = (string)file_get_contents($path);
        if ($html === '') return $out;

        // Pull the <head> region as a string. We don't DOMDocument-parse the
        // whole file — PHP markers + the bound block would round-trip badly.
        if (preg_match('#<head\b[^>]*>(.*?)</head>#is', $html, $m)) {
            $head = $m[1];

            // 1. <link rel="stylesheet" href="...">
            if (preg_match_all('#<link\b[^>]*\brel=(["\'])stylesheet\1[^>]*>#is', $head, $links)) {
                foreach ($links[0] as $tag) {
                    if (preg_match('#\bhref=(["\'])(.*?)\1#i', $tag, $hm)) {
                        $out['stylesheet_urls'][] = $hm[2];
                    }
                }
            }

            // 2. <style>…</style> raw contents (NOT executed; just style rules)
            if (preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $head, $styles)) {
                $out['inline_css'] = implode("\n\n", $styles[1]);
            }

            // 3. Tailwind CDN detection — any script src containing
            //    "tailwind" (covers cdn.tailwindcss.com, jsdelivr Play CDN,
            //    and the legacy play.tailwindcss.com URL).
            if (preg_match('#<script\b[^>]*\bsrc=(["\'])[^"\']*tailwind[^"\']*\1#is', $head)) {
                $out['has_tailwind_cdn'] = true;
            }
        }

        // 4. Ancestor class stack of the role=blog-post-body block.
        //    We approximate: scan from the start of the file up to the
        //    block's start marker, collect each *open* tag that's still
        //    open at that point, harvest its class attribute.
        $out['ancestor_classes'] = $this->ancestorClassesAt($html);

        return $out;
    }

    private function resolveTemplate(string $collectionId): ?string
    {
        $dir = $this->cmsDir . '/collection-templates';
        $candidates = [
            $dir . '/' . $collectionId . '-detail.php',
            $dir . '/default-detail.php',
        ];
        foreach ($candidates as $p) {
            if (is_file($p)) return $p;
        }
        return null;
    }

    /**
     * Stack-based ancestor walk: scan tags in document order, push on open,
     * pop on close. When we hit the bound-block start marker, return the
     * stack. Self-closing / void elements are skipped.
     */
    private function ancestorClassesAt(string $html): array
    {
        $boundMarker = 'role=blog-post-body';
        $bodyStart = stripos($html, '<body');
        if ($bodyStart === false) return [];
        $cursor = $bodyStart;
        // Skip past the <body ...> tag itself
        $bodyTagEnd = strpos($html, '>', $cursor);
        if ($bodyTagEnd === false) return [];
        $cursor = $bodyTagEnd + 1;

        $stack = [];
        $voidTags = ['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'];

        // Tokenize tags + the bound marker. We use preg_match to advance.
        while ($cursor < strlen($html)) {
            // Find next interesting position: a tag opener "<" or the
            // bound-block marker.
            $tagPos = strpos($html, '<', $cursor);
            $markerPos = strpos($html, $boundMarker, $cursor);
            if ($markerPos !== false && ($tagPos === false || $markerPos < $tagPos)) {
                // The marker lives inside a PHP comment that's inside a
                // CMS:BLOCK opening tag. Stack as of now is the answer.
                return $this->stackToClassChain($stack);
            }
            if ($tagPos === false) break;
            $cursor = $tagPos;

            // <!-- ... --> comment — skip
            if (substr($html, $cursor, 4) === '<!--') {
                $end = strpos($html, '-->', $cursor + 4);
                if ($end === false) break;
                $cursor = $end + 3;
                continue;
            }
            /* PHP open tag — skip past the closer. */
            if (substr($html, $cursor, 5) === '<?php' || substr($html, $cursor, 3) === '<?=') {
                $end = strpos($html, '?>', $cursor);
                if ($end === false) break;
                // Need to check the PHP block for our marker before skipping
                $php = substr($html, $cursor, $end - $cursor);
                if (strpos($php, $boundMarker) !== false) {
                    return $this->stackToClassChain($stack);
                }
                $cursor = $end + 2;
                continue;
            }

            // Match an HTML tag
            if (!preg_match('#<(/?)([a-zA-Z][a-zA-Z0-9]*)\b([^>]*)>#A', $html, $m, 0, $cursor)) {
                $cursor++;
                continue;
            }
            $isClose = $m[1] === '/';
            $tag = strtolower($m[2]);
            $attrs = $m[3];
            $tagLen = strlen($m[0]);

            if ($isClose) {
                // Pop matching open
                for ($i = count($stack) - 1; $i >= 0; $i--) {
                    if ($stack[$i]['tag'] === $tag) {
                        array_splice($stack, $i);
                        break;
                    }
                }
            } elseif (in_array($tag, $voidTags, true) || substr(rtrim($attrs), -1) === '/') {
                // self-closing, no push
            } elseif (in_array($tag, ['script','style'], true)) {
                // Skip its content entirely
                $closing = '</' . $tag;
                $end = stripos($html, $closing, $cursor + $tagLen);
                if ($end === false) break;
                $endTagEnd = strpos($html, '>', $end);
                $cursor = ($endTagEnd === false) ? $end : $endTagEnd + 1;
                continue;
            } else {
                $class = '';
                if (preg_match('#\bclass=(["\'])(.*?)\1#i', $attrs, $cm)) {
                    $class = trim($cm[2]);
                }
                $stack[] = ['tag' => $tag, 'class' => $class];
            }
            $cursor += $tagLen;
        }
        return $this->stackToClassChain($stack);
    }

    private function stackToClassChain(array $stack): array
    {
        $out = [];
        foreach ($stack as $node) {
            if ($node['class'] !== '') $out[] = $node['class'];
        }
        return $out;
    }
}
