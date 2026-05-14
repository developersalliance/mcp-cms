<?php
/**
 * BlockParser - Parses and updates CMS blocks in PHP template files.
 *
 * Blocks are defined via PHP comment markers:
 * <?php /* CMS:BLOCK name=header role=meta custom=1 start *\/ ?>
 * ... content ...
 * <?php /* CMS:BLOCK name=header end *\/ ?>
 */

class BlockParser
{
    public function parseBlocksFromString(string $content): array
    {
        $blocks = [];

        // Regex to match block start markers
        $pattern = '/<!--\s*CMS:BLOCK\s+(.+?)\s+start\s*-->|<\?php\s+\/\*\s*CMS:BLOCK\s+(.+?)\s+start\s*\*\/\s*\?>/i';

        preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $index => $match) {
            $startTag = $match[0];
            $startPos = $match[1];

            // Extract attributes from start tag
            $attrString = $matches[1][$index][0] ?: $matches[2][$index][0];
            $attributes = $this->parseAttributes($attrString);

            if (!isset($attributes['name'])) {
                continue; // Skip blocks without name
            }

            $blockName = $attributes['name'];

            // Find corresponding end tag
            $endPattern = '/<!--\s*CMS:BLOCK\s+name=' . preg_quote($blockName, '/') . '\s+end\s*-->|<\?php\s+\/\*\s*CMS:BLOCK\s+name=' . preg_quote($blockName, '/') . '\s+end\s*\*\/\s*\?>/i';

            if (preg_match($endPattern, $content, $endMatch, PREG_OFFSET_CAPTURE, $startPos)) {
                $endTag = $endMatch[0][0];
                $endPos = $endMatch[0][1];

                // Extract content between tags
                $contentStart = $startPos + strlen($startTag);
                $contentLength = $endPos - $contentStart;
                $blockContent = substr($content, $contentStart, $contentLength);

                $blocks[] = [
                    'name' => $blockName,
                    'role' => $attributes['role'] ?? null,
                    'custom' => isset($attributes['custom']) && $attributes['custom'] === '1',
                    'system' => isset($attributes['system']) && $attributes['system'] === '1',
                    'content' => $blockContent,
                    'start_pos' => $startPos,
                    'end_pos' => $endPos + strlen($endTag),
                ];
            }
        }

        return $blocks;
    }

    public function parseBlocks(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new Exception("File not found: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new Exception("File not readable: {$filePath}");
        }

        return $this->parseBlocksFromString(file_get_contents($filePath));
    }

    public function updateBlockInString(string $content, string $blockName, string $newContent, ?bool $customFlag = null): string
    {
        $blocks = $this->parseBlocksFromString($content);

        // Find the block to update
        $targetBlock = null;
        foreach ($blocks as $block) {
            if ($block['name'] === $blockName) {
                $targetBlock = $block;
                break;
            }
        }

        if (!$targetBlock) {
            throw new Exception("Block '{$blockName}' not found in file");
        }

        $beforeBlock = substr($content, 0, $targetBlock['start_pos']);
        $afterBlock = substr($content, $targetBlock['end_pos']);

        // Build new start tag
        $attributes = ['name' => $blockName];
        if ($targetBlock['role']) {
            $attributes['role'] = $targetBlock['role'];
        }
        if ($customFlag !== null) {
            if ($customFlag) {
                $attributes['custom'] = '1';
            }
        } else {
            if ($targetBlock['custom']) {
                $attributes['custom'] = '1';
            }
        }

        $attrString = $this->buildAttributeString($attributes);

        // Determine comment style from original
        $originalStart = substr($content, $targetBlock['start_pos'], 10);
        if (strpos($originalStart, '<?php') !== false) {
            $newStartTag = "<?php /* CMS:BLOCK {$attrString} start */ ?>";
            $newEndTag = "<?php /* CMS:BLOCK name={$blockName} end */ ?>";
        } else {
            $newStartTag = "<!-- CMS:BLOCK {$attrString} start -->";
            $newEndTag = "<!-- CMS:BLOCK name={$blockName} end -->";
        }

        return $beforeBlock . $newStartTag . $newContent . $newEndTag . $afterBlock;
    }

    /**
     * @throws Exception if block not found or file cannot be written
     */
    public function updateBlock(string $filePath, string $blockName, string $newContent, ?bool $customFlag = null): void
    {
        if (!file_exists($filePath)) {
            throw new Exception("File not found: {$filePath}");
        }

        if (!is_readable($filePath) || !is_writable($filePath)) {
            throw new Exception("File not readable/writable: {$filePath}");
        }

        $result = $this->updateBlockInString(file_get_contents($filePath), $blockName, $newContent, $customFlag);

        if (file_put_contents($filePath, $result) === false) {
            throw new Exception("Failed to write file: {$filePath}");
        }
    }

    /**
     * Update a block across all pages (for global blocks without custom=1).
     *
     * Skips pages where the block is marked as custom=1.
     *
     * @param array $pages Array of pages with ['id' => '', 'path' => '']
     * @param string $blockName Name of the block to update
     * @param string $newContent New content for the block
     * @param string $sourcePageId Page ID where the edit originated (will be skipped)
     * @return array Results with 'updated', 'skipped', and 'failed' arrays
     */
    public function updateBlockGlobally(
        array $pages,
        string $blockName,
        string $newContent,
        string $sourcePageId
    ): array {
        $results = [
            'updated' => [],
            'skipped' => [],
            'failed' => [],
            'not_found' => []
        ];

        foreach ($pages as $page) {
            $pageId = $page['id'] ?? '';
            $pagePath = $page['path'] ?? '';

            // Skip source page (already updated via draft)
            if ($pageId === $sourcePageId) {
                continue;
            }

            // Skip if file doesn't exist
            if (!file_exists($pagePath)) {
                $results['failed'][] = ['page_id' => $pageId, 'reason' => 'File not found'];
                continue;
            }

            try {
                // Parse blocks on target page
                $blocks = $this->parseBlocks($pagePath);

                // Find the target block
                $targetBlock = null;
                foreach ($blocks as $block) {
                    if ($block['name'] === $blockName) {
                        $targetBlock = $block;
                        break;
                    }
                }

                // Skip if block doesn't exist on this page
                if (!$targetBlock) {
                    $results['not_found'][] = $pageId;
                    continue;
                }

                // Skip if block is marked as custom on this page
                if ($targetBlock['custom']) {
                    $results['skipped'][] = $pageId;
                    continue;
                }

                // Update the block (keep custom=false)
                $this->updateBlock($pagePath, $blockName, $newContent, false);
                $results['updated'][] = $pageId;

            } catch (Exception $e) {
                $results['failed'][] = ['page_id' => $pageId, 'reason' => $e->getMessage()];
            }
        }

        return $results;
    }

    public function collectPagesWithBlock(array $allPages, string $blockName, string $excludePageId): array
    {
        $result = [];
        foreach ($allPages as $page) {
            if ($page['id'] === $excludePageId) continue;
            try {
                $pageBlocks = $this->parseBlocks($page['path']);
                foreach ($pageBlocks as $block) {
                    if ($block['name'] === $blockName && !$block['custom']) {
                        $result[$page['id']] = $page['path'];
                        break;
                    }
                }
            } catch (Exception $e) {
                // Skip pages that can't be parsed
            }
        }
        return $result;
    }

    /**
     * Parse attributes from a block start tag attribute string.
     *
     * @param string $attrString e.g., "name=header role=meta custom=1"
     * @return array Associative array of attributes
     */
    private function parseAttributes(string $attrString): array
    {
        $attributes = [];

        // Match attribute pairs: name=value
        preg_match_all('/(\w+)=([^\s]+)/', $attrString, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $attributes[$match[1]] = $match[2];
        }

        return $attributes;
    }

    /**
     * Build an attribute string from an associative array.
     *
     * @param array $attributes Associative array of attributes
     * @return string e.g., "name=header role=meta custom=1"
     */
    private function buildAttributeString(array $attributes): string
    {
        $parts = [];
        foreach ($attributes as $key => $value) {
            $parts[] = "{$key}={$value}";
        }
        return implode(' ', $parts);
    }
}
