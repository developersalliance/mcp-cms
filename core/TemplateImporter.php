<?php

require_once __DIR__ . '/AIClient.php';

class TemplateImporter
{
    private const MAX_FILE_SIZE = 500 * 1024;
    private const SAMPLE_CHARS = 3000;

    private string $rootDir;
    private AIClient $ai;
    /** @var array<int, array{name:string, role:string}> */
    private array $lastBoundBlocks = [];

    public function __construct(string $rootDir, AIClient $ai)
    {
        $this->rootDir = rtrim($rootDir, '/');
        $this->ai = $ai;
    }

    public function propose(string $filePath): array
    {
        $original = $this->loadFile($filePath);
        $dom = $this->parseDom($original);

        $outline = [];
        $this->buildOutline($dom->documentElement, $outline, 0);

        // Remove heavy subtrees for the sample (leave outline intact)
        $sampleDom = $this->parseDom($original);
        $this->stripHeavyContent($sampleDom);
        $sample = mb_substr($sampleDom->saveHTML() ?: '', 0, self::SAMPLE_CHARS);

        $systemPrompt = <<<PROMPT
You identify semantic content blocks in a webpage template for a CMS so non-developers can edit each section independently. Output is a JSON array of proposals.

WHAT TO PICK (in priority order)
1. Direct children of <body> or <main> that are <section>, <article>, <header>, <footer>, <nav>, or <aside>. These are almost always the right blocks.
2. <div> direct-children of <body> or <main> that contain a complete content region (header, banner, hero, testimonials, etc.).
3. NOTHING ELSE. Do not propose deeper descendants, inner wrappers, individual headings, single links, images, list items, or layout flex/grid wrappers.

NAMING (strict)
- If the element has an HTML id attribute, name = that id (verbatim, in lowercase-snake-case). The id is the single best signal of intent.
- Else, name = the most descriptive class on the element (lowercase-snake-case, hyphens become underscores).
- Else, name = a 1-3-word semantic label (lowercase-snake-case) that summarizes the content: hero, services, pricing, contact, footer, etc.
- Names must be unique within the page. If two candidate blocks would collide, add a number: services, services_2.

NEVER
- Never propose the <html>, <body>, or <main> element itself. Wrapping <main> as one block defeats the purpose.
- Never propose a <br>, <span>, <a>, <img>, <li>, <h1>-<h6>, or other inline / leaf element as a standalone block.
- Never nest: if proposal A is a DOM ancestor of proposal B, drop one. Aim for SIBLING blocks at one level.
- Never propose a wrapper that contains exactly one element child (it's just a layout wrapper).

QUANTITY
- 3 to 12 blocks is typical. Long pages can have more; short pages have 1-3. Never invent blocks that don't correspond to a real content region.

VERIFY BEFORE RESPONDING
- For each proposal: re-check the outline. Is its parent <body> or <main>? If not, its parent should still NOT be another proposal. If it is, drop one of them.
- For each proposal: does its tag/id make sense as a name? If the element is <main id="main-content"> you should NOT propose it — pick its children instead.

OUTPUT
- For each block: node_id (must appear in the outline exactly), name, and a one-sentence reason that names the actual content (e.g. "hero headline + CTA", not "site-wide hero region").
PROMPT;

        $userPrompt = "DOM outline (each node has a unique node_id; 'id' is the HTML id attribute if present; 'parent' is the node_id of the parent):\n"
            . json_encode($outline, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n\nHTML sample (first " . self::SAMPLE_CHARS . " chars, heavy content stripped):\n"
            . $sample
            . "\n\nReturn JSON in this exact shape, nothing else:\n"
            . '{"blocks":[{"node_id":"n2","name":"hero","reason":"hero headline and primary CTA"}, ...]}'
            . "\n\nReminder: prefer node_ids whose tag is <section>/<article>/<header>/<footer>/<nav> and whose parent is <body> or <main>. If an element has an HTML id, use it as the block name verbatim.";

        $raw = $this->ai->complete($userPrompt, $systemPrompt, true, 2048);
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['blocks']) || !is_array($data['blocks'])) {
            throw new Exception('AI returned unexpected response shape');
        }

        // Filter and annotate proposals with outline context
        $outlineById = [];
        foreach ($outline as $o) {
            $outlineById[$o['node_id']] = $o;
        }

        // Tags that should never be standalone blocks (leaf / inline / structural roots).
        $forbiddenTags = [
            'html', 'body', 'main',
            'br', 'hr', 'img', 'span', 'a', 'b', 'i', 'em', 'strong', 'small',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'li', 'td', 'th', 'tr', 'thead', 'tbody', 'tfoot',
            'input', 'label', 'button', 'select', 'option',
            'meta', 'link', 'title', 'head', 'script', 'style',
        ];
        $forbiddenTagSet = array_flip($forbiddenTags);

        $proposals = [];
        $seenNames = [];
        foreach ($data['blocks'] as $b) {
            $nid = $b['node_id'] ?? '';
            $name = $b['name'] ?? '';
            if (!isset($outlineById[$nid])) continue;
            if (!$this->isValidBlockName($name)) continue;
            $entry = $outlineById[$nid];
            // Reject leaf / inline / page-root tags outright
            if (isset($forbiddenTagSet[$entry['tag']])) continue;
            // Reject single-child wrappers (just layout)
            if ((int)($entry['children'] ?? 0) <= 1 && (int)($entry['text_chars'] ?? 0) < 40) continue;
            // De-duplicate names by appending _2, _3, ...
            $finalName = $name;
            $suffix = 2;
            while (isset($seenNames[$finalName])) {
                $finalName = $name . '_' . $suffix;
                $suffix++;
            }
            $seenNames[$finalName] = true;
            $proposals[] = [
                'node_id' => $nid,
                'name' => $finalName,
                'reason' => (string)($b['reason'] ?? ''),
                'tag' => $entry['tag'],
                'id_attr' => $entry['id'] ?? '',
                'classes' => $entry['classes'] ?? [],
                'text_chars' => $entry['text_chars'] ?? 0,
                'children' => $entry['children'] ?? 0,
            ];
        }

        $this->dropNestedProposals($proposals, $outlineById);

        return [
            'proposals' => $proposals,
            'outline_size' => count($outline),
        ];
    }

    public function apply(string $filePath, array $blocks): string
    {
        $original = $this->loadFile($filePath);
        // Capture raw <style> and <script> contents from the source so we can
        // restore them after DOM serialization (saveHTML() entity-encodes
        // non-ASCII chars inside <style>/<script>, which breaks CSS content:
        // values and JS string literals — e.g. "–" becoming "&ndash;").
        $rawStyles  = $this->captureRawElementContents($original, 'style');
        $rawScripts = $this->captureRawElementContents($original, 'script');
        $dom = $this->parseDom($original);

        $nodeIndex = [];
        $counter = 0;
        $this->assignIds($dom->documentElement, $counter, $nodeIndex);

        $usedNames = [];
        $placeholders = [];
        // Track which blocks were marked as a blog role so the apply step can
        // run the bind pass on them in a follow-up. For each, capture both the
        // block name and the role we wrote into the marker.
        $boundBlocks = [];
        foreach ($blocks as $i => $block) {
            $nid = $block['node_id'] ?? '';
            $name = $block['name'] ?? '';
            $disposition = (string)($block['disposition'] ?? 'page');
            // Resolve disposition → (custom, role). Disposition is what the
            // user picked in the import UI; legacy callers can still pass
            // 'custom' directly and we honor it as 'page'/'global'.
            [$custom, $role] = $this->resolveDisposition($disposition, !empty($block['custom']));
            if (!isset($nodeIndex[$nid])) {
                throw new Exception("Node {$nid} not found in document");
            }
            if (!$this->isValidBlockName($name)) {
                throw new Exception("Invalid block name: '{$name}'");
            }
            if (isset($usedNames[$name])) {
                throw new Exception("Duplicate block name: '{$name}'");
            }
            $usedNames[$name] = true;

            $node = $nodeIndex[$nid];
            $startKey = "__CMS_BLOCK_START_{$i}__";
            $endKey = "__CMS_BLOCK_END_{$i}__";
            $placeholders[$startKey] = $this->buildStartMarker($name, $custom, $role);
            $placeholders[$endKey] = $this->buildEndMarker($name);
            if ($role !== null && in_array($role, ['blog-post-body', 'blog-post-list'], true)) {
                $boundBlocks[] = ['name' => $name, 'role' => $role];
            }

            $startComment = $dom->createComment($startKey);
            $endComment = $dom->createComment($endKey);
            $node->parentNode->insertBefore($startComment, $node);
            if ($node->nextSibling) {
                $node->parentNode->insertBefore($endComment, $node->nextSibling);
            } else {
                $node->parentNode->appendChild($endComment);
            }
        }

        $serialized = $dom->saveHTML();
        if ($serialized === false) {
            throw new Exception('Failed to serialize DOM');
        }

        // Strip the UTF-8 sentinel we inject on load
        $serialized = preg_replace('/^<\?xml encoding="UTF-8"\?>\s*/u', '', $serialized);

        // Restore raw <style>/<script> contents so CSS/JS literals keep their
        // original characters (DOMDocument would HTML-entity-encode them).
        $serialized = $this->restoreRawElementContents($serialized, 'style', $rawStyles);
        $serialized = $this->restoreRawElementContents($serialized, 'script', $rawScripts);

        // Replace placeholder comments with the actual marker text
        foreach ($placeholders as $key => $marker) {
            $pattern = '/<!--' . preg_quote($key, '/') . '-->/';
            $serialized = preg_replace($pattern, $marker, $serialized);
        }

        $this->lastBoundBlocks = $boundBlocks;
        return $serialized;
    }

    /**
     * After apply(), returns blocks the user marked Blog content / Blog list,
     * so callers can run the bind pass on just those.
     * @return array<int, array{name:string, role:string}>
     */
    public function getLastBoundBlocks(): array
    {
        return $this->lastBoundBlocks;
    }

    /**
     * Map import-UI disposition to (custom, role).
     * - 'global'        → custom=false, no role
     * - 'page'          → custom=true,  no role  (default)
     * - 'blog-content'  → custom=true,  role=blog-post-body
     * - 'blog-list'     → custom=true,  role=blog-post-list
     * The fallback flag handles legacy callers that only pass 'custom'.
     */
    private function resolveDisposition(string $disposition, bool $legacyCustom): array
    {
        switch ($disposition) {
            case 'global':
                return [false, null];
            case 'blog-content':
                return [true, 'blog-post-body'];
            case 'blog-list':
                return [true, 'blog-post-list'];
            case 'page':
                return [true, null];
            default:
                return [$legacyCustom, null];
        }
    }

    /**
     * Capture the inner text of each occurrence of $tag in $html (document order).
     * Used to preserve raw CSS/JS through DOMDocument serialization.
     */
    private function captureRawElementContents(string $html, string $tag): array
    {
        $out = [];
        $pattern = '#<' . preg_quote($tag, '#') . '\b[^>]*>(.*?)</' . preg_quote($tag, '#') . '\s*>#is';
        if (preg_match_all($pattern, $html, $matches)) {
            $out = $matches[1];
        }
        return $out;
    }

    /**
     * Walk the serialized output and replace each $tag's inner text with the
     * Nth captured raw content, in document order.
     */
    private function restoreRawElementContents(string $serialized, string $tag, array $rawContents): string
    {
        if (!$rawContents) return $serialized;
        $pattern = '#(<' . preg_quote($tag, '#') . '\b[^>]*>)(.*?)(</' . preg_quote($tag, '#') . '\s*>)#is';
        $idx = 0;
        return preg_replace_callback($pattern, function ($m) use (&$idx, $rawContents) {
            $orig = $rawContents[$idx] ?? null;
            $idx++;
            if ($orig === null) return $m[0];
            return $m[1] . $orig . $m[3];
        }, $serialized);
    }

    public function writeWithBackup(string $filePath, string $newContent): array
    {
        $pathInfo = pathinfo($filePath);
        $ext = strtolower($pathInfo['extension'] ?? '');

        // Auto-promote .html/.htm to .php so CMS:BLOCK markers stay server-side
        if ($ext === 'html' || $ext === 'htm') {
            $outputPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.php';
            if (file_exists($outputPath)) {
                throw new Exception('Cannot promote to .php: ' . basename($outputPath) . ' already exists. Rename or remove it first.');
            }
            $promoted = true;
        } else {
            $outputPath = $filePath;
            $promoted = false;
        }

        $backupsRoot = $this->rootDir . '/cms/backups/_imports';
        if (!is_dir($backupsRoot)) {
            mkdir($backupsRoot, 0755, true);
        }
        $rel = ltrim(str_replace($this->rootDir, '', $filePath), '/');
        $safeName = preg_replace('/[^A-Za-z0-9._\-]/', '_', $rel);
        $backupPath = $backupsRoot . '/' . $safeName . '.' . date('YmdHis');
        if (!copy($filePath, $backupPath)) {
            throw new Exception('Failed to back up original file');
        }

        if (file_put_contents($outputPath, $newContent) === false) {
            throw new Exception('Failed to write output file');
        }

        if ($promoted) {
            if (!@unlink($filePath)) {
                throw new Exception('Wrote ' . basename($outputPath) . ' but could not remove original ' . basename($filePath) . '. Please delete it manually.');
            }
        }

        return [
            'backup_path' => $backupPath,
            'output_path' => $outputPath,
            'promoted' => $promoted,
        ];
    }

    // -- internals --

    private function loadFile(string $filePath): string
    {
        $realRoot = realpath($this->rootDir);
        $real = realpath($filePath);
        if ($real === false || $realRoot === false || strpos($real, $realRoot) !== 0) {
            throw new Exception('File is outside the web root');
        }
        $size = filesize($real);
        if ($size > self::MAX_FILE_SIZE) {
            throw new Exception('File exceeds import size limit (' . self::MAX_FILE_SIZE . ' bytes)');
        }
        $content = file_get_contents($real);
        if ($content === false) {
            throw new Exception('Failed to read file');
        }
        return $content;
    }

    private function parseDom(string $html): DOMDocument
    {
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // Prepend an XML encoding hint so libxml parses UTF-8 correctly
        $dom->loadHTML('<?xml encoding="UTF-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $dom;
    }

    private function buildOutline(?DOMNode $node, array &$outline, int $depth, ?string $parentId = null): void
    {
        if (!$node instanceof DOMElement) return;

        $id = 'n' . (count($outline) + 1);
        $classes = [];
        $classAttr = $node->getAttribute('class');
        if ($classAttr !== '') {
            $classes = array_values(array_filter(preg_split('/\s+/', $classAttr)));
        }

        $childCount = 0;
        foreach ($node->childNodes as $c) {
            if ($c instanceof DOMElement) $childCount++;
        }

        $textChars = mb_strlen(trim($node->textContent ?? ''));

        $entry = [
            'node_id' => $id,
            'tag' => strtolower($node->tagName),
            'depth' => $depth,
            'parent' => $parentId,
            'id' => $node->getAttribute('id') ?: null,
            'classes' => $classes,
            'children' => $childCount,
            'text_chars' => $textChars,
        ];
        $outline[] = $entry;

        // Attach id via a dedicated attribute so apply() can find the same node
        $node->setAttribute('data-cms-nid', $id);

        // Outline depth caps: don't drill below 4 levels — AI doesn't need leaf nodes
        if ($depth >= 4) return;

        foreach ($node->childNodes as $c) {
            if ($c instanceof DOMElement) {
                $this->buildOutline($c, $outline, $depth + 1, $id);
            }
        }
    }

    private function assignIds(?DOMNode $node, int &$counter, array &$index): void
    {
        if (!$node instanceof DOMElement) return;
        $counter++;
        $id = 'n' . $counter;
        $index[$id] = $node;
        foreach ($node->childNodes as $c) {
            if ($c instanceof DOMElement) {
                $this->assignIds($c, $counter, $index);
            }
        }
    }

    private function stripHeavyContent(DOMDocument $dom): void
    {
        foreach (['script', 'style', 'svg', 'noscript'] as $tag) {
            $nodes = iterator_to_array($dom->getElementsByTagName($tag));
            foreach ($nodes as $n) {
                if ($n->parentNode) {
                    $placeholder = $dom->createTextNode("[…{$tag} stripped…]");
                    $n->parentNode->replaceChild($placeholder, $n);
                }
            }
        }
    }

    private function isValidBlockName(string $name): bool
    {
        return $name !== '' && preg_match('/^[a-z][a-z0-9_\-]{0,40}$/', $name) === 1;
    }

    private function buildStartMarker(string $name, bool $custom, ?string $role = null): string
    {
        $attrs = 'name=' . $name;
        if ($role !== null && $role !== '') {
            $attrs .= ' role=' . $role;
        }
        if ($custom) {
            $attrs .= ' custom=1';
        }
        return "<?php /* CMS:BLOCK {$attrs} start */ ?>";
    }

    private function buildEndMarker(string $name): string
    {
        return "<?php /* CMS:BLOCK name={$name} end */ ?>";
    }

    private function dropNestedProposals(array &$proposals, array $outlineById): void
    {
        // Build ancestor set for each node_id
        $ancestors = [];
        foreach ($outlineById as $nid => $o) {
            $chain = [];
            $cur = $o['parent'] ?? null;
            while ($cur !== null && isset($outlineById[$cur])) {
                $chain[] = $cur;
                $cur = $outlineById[$cur]['parent'] ?? null;
            }
            $ancestors[$nid] = $chain;
        }
        $proposedIds = array_column($proposals, 'node_id');
        $proposedSet = array_flip($proposedIds);
        $filtered = [];
        foreach ($proposals as $p) {
            $hasAncestorInProposals = false;
            foreach ($ancestors[$p['node_id']] ?? [] as $a) {
                if (isset($proposedSet[$a])) {
                    $hasAncestorInProposals = true;
                    break;
                }
            }
            if (!$hasAncestorInProposals) {
                $filtered[] = $p;
            }
        }
        $proposals = $filtered;
    }
}
