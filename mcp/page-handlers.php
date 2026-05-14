<?php
/**
 * Page-level MCP handler functions.
 *
 * Pure functions that return arrays. Shared by mcp/index.php and
 * admin/edit-ai-chat.php so each gets to format the response its own way.
 */

// Helper function to normalize homepage page_id
function normalizePageId($pageId) {
    // The homepage has three common aliases that MUST all map to the same
    // canonical id ("") so we never end up with parallel drafts written
    // under different keys. PageManager::getPagePath() already treats ""
    // and "index" as the same live file, but getDraftPath() stores them
    // under different filenames — collapse them here.
    if ($pageId === '/' || $pageId === 'index') {
        return '';
    }

    return $pageId;
}

// Handler for insert_block tool
function handleInsertBlock($input, $pageManager, $blockParser, $backupManager, $isJsonRpc = false, $jsonRpcId = null) {
    // Validate required parameters
    $pageId = isset($input['page_id']) ? normalizePageId($input['page_id']) : null;
    $position = $input['position'] ?? null;
    $name = $input['name'] ?? '';
    $content = $input['content'] ?? '';

    if ($pageId === null || !$position || !$name || $content === '') {
        return ['success' => false, 'error' => 'Missing required parameters: page_id, position, name, content'];
    }

    // Validate position structure
    if (!is_array($position) || !isset($position['type'])) {
        return ['success' => false, 'error' => 'Invalid position parameter'];
    }

    $positionType = $position['type'];
    $referenceBlockName = $position['block_name'] ?? null;

    // Validate position type
    $validTypes = ['before_block', 'after_block', 'at_end'];
    if (!in_array($positionType, $validTypes)) {
        return ['success' => false, 'error' => 'Invalid position.type; expected before_block, after_block, or at_end'];
    }

    // Validate reference block name for before_block/after_block
    if (in_array($positionType, ['before_block', 'after_block']) && !$referenceBlockName) {
        return ['success' => false, 'error' => 'position.block_name is required for before_block/after_block'];
    }

    // Optional parameters
    $role = $input['role'] ?? null;
    $custom = $input['custom'] ?? false;

    // Get page path
    $pagePath = $pageManager->getPagePath($pageId);
    if (!$pagePath) {
        return ['success' => false, 'error' => 'Page not found'];
    }

    // Get current content (draft if exists, otherwise live page)
    $fileContent = $pageManager->hasDraft($pageId)
        ? $pageManager->getDraft($pageId)
        : file_get_contents($pagePath);

    if ($fileContent === false) {
        return ['success' => false, 'error' => 'Failed to read page file'];
    }

    // Parse existing blocks
    $blocks = $blockParser->parseBlocksFromString($fileContent);

    // Check for duplicate block name
    foreach ($blocks as $block) {
        if ($block['name'] === $name) {
            return ['success' => false, 'error' => "Block with name '{$name}' already exists on this page"];
        }
    }

    // Determine insertion position
    $insertPos = null;

    switch ($positionType) {
        case 'before_block':
            // Find the reference block and insert before it
            $found = false;
            foreach ($blocks as $block) {
                if ($block['name'] === $referenceBlockName) {
                    $insertPos = $block['start_pos'];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return ['success' => false, 'error' => "Reference block '{$referenceBlockName}' not found on this page"];
            }
            break;

        case 'after_block':
            // Find the reference block and insert after it
            $found = false;
            foreach ($blocks as $block) {
                if ($block['name'] === $referenceBlockName) {
                    $insertPos = $block['end_pos'];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return ['success' => false, 'error' => "Reference block '{$referenceBlockName}' not found on this page"];
            }
            break;

        case 'at_end':
            // Insert before </body> tag if found, otherwise at end of file
            $bodyClosePos = stripos($fileContent, '</body>');
            if ($bodyClosePos !== false) {
                $insertPos = $bodyClosePos;
            } else {
                $insertPos = strlen($fileContent);
            }
            break;
    }

    // Build the new block markup
    $attributes = ['name' => $name];
    if ($role) {
        $attributes['role'] = $role;
    }
    if ($custom) {
        $attributes['custom'] = '1';
    }

    $attrString = '';
    foreach ($attributes as $key => $value) {
        $attrString .= "{$key}={$value} ";
    }
    $attrString = rtrim($attrString);

    // Use PHP comment style (consistent with BlockParser)
    $newBlockMarkup = "\n<?php /* CMS:BLOCK {$attrString} start */ ?>\n";
    $newBlockMarkup .= $content;
    $newBlockMarkup .= "\n<?php /* CMS:BLOCK name={$name} end */ ?>\n";

    // Insert the new block
    $newFileContent = substr($fileContent, 0, $insertPos) . $newBlockMarkup . substr($fileContent, $insertPos);

    try {
        // Save as draft
        $pageManager->saveDraft($pageId, $newFileContent);

        // Create backup of live page (not draft)
        $backupManager->createBackup($pageId, $pagePath);

        return ['success' => true, 'message' => 'Block inserted and saved as draft'];
    } catch (Exception $e) {
        return ['success' => false, 'error' => sanitizeMcpError('Failed to save draft: ' . $e->getMessage())];
    }
}

// Handler for search_in_page tool
function handleSearchInPage($input, $pageManager, $isJsonRpc = false, $jsonRpcId = null) {
    // Validate required parameters
    $pageId = isset($input['page_id']) ? normalizePageId($input['page_id']) : null;
    $search = $input['search'] ?? '';

    if ($pageId === null || $search === '') {
        return ['success' => false, 'error' => 'Missing required parameters: page_id, search'];
    }

    // Optional parameters
    $limit = $input['limit'] ?? 20;
    $caseSensitive = $input['case_sensitive'] ?? false;

    // Get page path
    $pagePath = $pageManager->getPagePath($pageId);
    if (!$pagePath || !is_readable($pagePath)) {
        return ['success' => false, 'error' => 'Page not found'];
    }

    // Read file content
    $content = file_get_contents($pagePath);
    if ($content === false) {
        return ['success' => false, 'error' => 'Failed to read page file'];
    }

    // Split into lines
    $lines = preg_split("/\r\n|\n|\r/", $content);
    $matches = [];
    $matchCount = 0;

    // Search through lines
    for ($i = 0; $i < count($lines) && $matchCount < $limit; $i++) {
        $line = $lines[$i];
        $found = false;

        if ($caseSensitive) {
            $found = (strpos($line, $search) !== false);
        } else {
            $found = (stripos($line, $search) !== false);
        }

        if ($found) {
            // Determine snippet window (current line + up to 5 lines after)
            $startLine = $i + 1; // 1-based
            $endLine = min($i + 6, count($lines)); // up to 5 lines after

            // Build snippet
            $snippetLines = array_slice($lines, $i, $endLine - $startLine + 1);
            $snippet = implode("\n", $snippetLines);

            // Trim snippet to ~250 chars
            if (strlen($snippet) > 250) {
                $snippet = substr($snippet, 0, 250) . '...';
            }

            $matches[] = [
                'start_line' => $startLine,
                'end_line' => $endLine,
                'snippet' => $snippet
            ];

            $matchCount++;
        }
    }

    return [
        'success' => true,
        'matches' => $matches
    ];
}

// Handler for get_page_region tool
function handleGetPageRegion($input, $pageManager, $isJsonRpc = false, $jsonRpcId = null) {
    // Validate required parameters
    $pageId = isset($input['page_id']) ? normalizePageId($input['page_id']) : null;
    $startLine = $input['start_line'] ?? null;
    $endLine = $input['end_line'] ?? null;

    if ($pageId === null || $startLine === null || $endLine === null) {
        return ['success' => false, 'error' => 'Missing required parameters: page_id, start_line, end_line'];
    }

    // Validate line numbers
    if (!is_int($startLine) || !is_int($endLine) || $startLine < 1 || $endLine < $startLine) {
        return ['success' => false, 'error' => 'Invalid line range'];
    }

    // Optional parameters
    $maxChars = $input['max_chars'] ?? 4000;

    // Get page path
    $pagePath = $pageManager->getPagePath($pageId);
    if (!$pagePath || !is_readable($pagePath)) {
        return ['success' => false, 'error' => 'Page not found'];
    }

    // Read file content
    $content = file_get_contents($pagePath);
    if ($content === false) {
        return ['success' => false, 'error' => 'Failed to read page file'];
    }

    // Split into lines
    $lines = preg_split("/\r\n|\n|\r/", $content);
    $totalLines = count($lines);

    // Validate start line
    if ($startLine > $totalLines) {
        return ['success' => false, 'error' => 'Invalid line range'];
    }

    // Clamp end line
    $actualEndLine = min($endLine, $totalLines);

    // Extract region, respecting max_chars
    $regionLines = [];
    $charCount = 0;
    $startIdx = $startLine - 1; // Convert to 0-based

    for ($i = $startIdx; $i < $actualEndLine; $i++) {
        $lineContent = $lines[$i];
        $lineLength = strlen($lineContent) + 1; // +1 for newline

        if ($charCount + $lineLength > $maxChars && $charCount > 0) {
            // Stop if we exceed max_chars
            $actualEndLine = $i; // Adjust actual end line
            break;
        }

        $regionLines[] = $lineContent;
        $charCount += $lineLength;
    }

    $region = implode("\n", $regionLines);

    return [
        'success' => true,
        'region' => $region,
        'start_line' => $startLine,
        'end_line' => $actualEndLine
    ];
}

// Handler for update_page_region tool
function handleUpdatePageRegion($input, $pageManager, $backupManager, $isJsonRpc = false, $jsonRpcId = null) {
    // Validate required parameters
    $pageId = isset($input['page_id']) ? normalizePageId($input['page_id']) : null;
    $startLine = $input['start_line'] ?? null;
    $endLine = $input['end_line'] ?? null;
    $oldRegion = $input['old_region'] ?? '';
    $newRegion = $input['new_region'] ?? '';

    if ($pageId === null || $startLine === null || $endLine === null || $oldRegion === '' || $newRegion === '') {
        return ['success' => false, 'error' => 'Missing required parameters: page_id, start_line, end_line, old_region, new_region'];
    }

    // Validate line numbers
    if (!is_int($startLine) || !is_int($endLine) || $startLine < 1 || $endLine < $startLine) {
        return ['success' => false, 'error' => 'Invalid line range'];
    }

    // Get page path
    $pagePath = $pageManager->getPagePath($pageId);
    if (!$pagePath) {
        return ['success' => false, 'error' => 'Page not found'];
    }

    // Get current content (draft if exists, otherwise live page)
    $content = $pageManager->hasDraft($pageId)
        ? $pageManager->getDraft($pageId)
        : file_get_contents($pagePath);

    if ($content === false) {
        return ['success' => false, 'error' => 'Failed to read page file'];
    }

    // Split into lines
    $lines = preg_split("/\r\n|\n|\r/", $content);
    $totalLines = count($lines);

    // Validate line range
    if ($startLine > $totalLines || $endLine > $totalLines) {
        return ['success' => false, 'error' => 'Invalid line range'];
    }

    // Extract current region
    $startIdx = $startLine - 1; // Convert to 0-based
    $count = $endLine - $startLine + 1;
    $currentRegionLines = array_slice($lines, $startIdx, $count);
    $currentRegion = implode("\n", $currentRegionLines);

    // Check if old_region matches current region (optimistic locking)
    if ($currentRegion !== $oldRegion) {
        return [
            'success' => false,
            'error' => 'Region has changed since retrieval'
        ];
    }

    // Split new region into lines
    $newRegionLines = preg_split("/\r\n|\n|\r/", $newRegion);

    // Replace the region
    array_splice($lines, $startIdx, $count, $newRegionLines);

    // Join back into file content
    $newContent = implode("\n", $lines);

    // Save as draft
    try {
        $pageManager->saveDraft($pageId, $newContent);

        // Create backup of live page (not draft)
        $backupManager->createBackup($pageId, $pagePath);

        return ['success' => true, 'message' => 'Page region updated and saved as draft'];
    } catch (Exception $e) {
        return ['success' => false, 'error' => sanitizeMcpError('Failed to save draft: ' . $e->getMessage())];
    }
}

// Handler for find_and_replace_block_content tool
function handleFindAndReplaceBlockContent($input, $pageManager, $blockParser, $backupManager, $globalBackupManager, $isJsonRpc = false, $jsonRpcId = null) {
    // Validate required parameters
    $pageId = isset($input['page_id']) ? normalizePageId($input['page_id']) : null;
    $blockName = $input['name'] ?? '';
    $search = $input['search'] ?? '';
    $replace = $input['replace'] ?? '';

    if ($pageId === null || !$blockName || $search === '') {
        return ['success' => false, 'error' => 'Missing required parameters: page_id, name, search'];
    }

    // Optional parameters with defaults
    $mode = $input['mode'] ?? 'first';
    $caseSensitive = $input['case_sensitive'] ?? true;

    // Validate mode
    if (!in_array($mode, ['first', 'all'])) {
        return ['success' => false, 'error' => 'Invalid mode. Must be "first" or "all"'];
    }

    // Get page path
    $pagePath = $pageManager->getPagePath($pageId);
    if (!$pagePath) {
        return ['success' => false, 'error' => 'Page not found'];
    }

    // Get current content (draft if exists, otherwise live page)
    $currentContent = $pageManager->hasDraft($pageId)
        ? $pageManager->getDraft($pageId)
        : file_get_contents($pagePath);

    // Parse blocks from the working content
    $blocks = $blockParser->parseBlocksFromString($currentContent);

    // Find the target block
    $targetBlock = null;
    foreach ($blocks as $block) {
        if ($block['name'] === $blockName) {
            $targetBlock = $block;
            break;
        }
    }

    if (!$targetBlock) {
        return ['success' => false, 'error' => 'Block not found'];
    }

    // Perform find and replace
    $originalContent = $targetBlock['content'];
    $newContent = $originalContent;
    $replacements = 0;

    if ($mode === 'first') {
        // Replace only first occurrence
        if ($caseSensitive) {
            $pos = strpos($newContent, $search);
            if ($pos !== false) {
                $newContent = substr_replace($newContent, $replace, $pos, strlen($search));
                $replacements = 1;
            }
        } else {
            $pos = stripos($newContent, $search);
            if ($pos !== false) {
                // Get the actual match to preserve other case variations
                $actualMatch = substr($newContent, $pos, strlen($search));
                $newContent = substr_replace($newContent, $replace, $pos, strlen($actualMatch));
                $replacements = 1;
            }
        }
    } else {
        // Replace all occurrences
        if ($caseSensitive) {
            $newContent = str_replace($search, $replace, $originalContent, $count);
            $replacements = $count;
        } else {
            $newContent = str_ireplace($search, $replace, $originalContent, $count);
            $replacements = $count;
        }
    }

    // If no replacements, return without modifying file
    if ($replacements === 0) {
        return [
            'success' => true,
            'replacements' => 0,
            'message' => 'No occurrences of the search text were found in this block.'
        ];
    }

    try {
        // Update the block in-memory
        $updatedContent = $blockParser->updateBlockInString($currentContent, $blockName, $newContent, $targetBlock['custom'] ? true : null);

        // Save as draft
        $pageManager->saveDraft($pageId, $updatedContent);

        // Create backup of live page (not draft)
        $backupManager->createBackup($pageId, $pagePath);

        $syncMessage = '';

        // If block is NOT custom, perform same find/replace on all other pages
        if (!$targetBlock['custom']) {
            $allPages = $pageManager->listPages();

            $pagesToBackup = $blockParser->collectPagesWithBlock($allPages, $blockName, $pageId);

            // Create global backup and sync
            if (!empty($pagesToBackup)) {
                $globalBackupManager->createGlobalBackup(
                    $pagesToBackup,
                    $blockName,
                    "Global find/replace in block '{$blockName}' via MCP"
                );

                // Perform same find/replace on other pages
                $syncCount = 0;
                $skipCount = 0;

                foreach ($allPages as $page) {
                    if ($page['id'] === $pageId) continue;

                    try {
                        $pageBlocks = $blockParser->parseBlocks($page['path']);
                        foreach ($pageBlocks as $block) {
                            if ($block['name'] === $blockName) {
                                if ($block['custom']) {
                                    $skipCount++;
                                } else {
                                    // Perform same find/replace
                                    $blockContent = $block['content'];
                                    $updatedBlockContent = $blockContent;

                                    if ($mode === 'first') {
                                        if ($caseSensitive) {
                                            $pos = strpos($updatedBlockContent, $search);
                                            if ($pos !== false) {
                                                $updatedBlockContent = substr_replace($updatedBlockContent, $replace, $pos, strlen($search));
                                            }
                                        } else {
                                            $pos = stripos($updatedBlockContent, $search);
                                            if ($pos !== false) {
                                                $updatedBlockContent = substr_replace($updatedBlockContent, $replace, $pos, strlen($search));
                                            }
                                        }
                                    } else {
                                        if ($caseSensitive) {
                                            $updatedBlockContent = str_replace($search, $replace, $blockContent);
                                        } else {
                                            $updatedBlockContent = str_ireplace($search, $replace, $blockContent);
                                        }
                                    }

                                    // Only update if content changed
                                    if ($updatedBlockContent !== $blockContent) {
                                        $blockParser->updateBlock($page['path'], $blockName, $updatedBlockContent, false);
                                        $syncCount++;
                                    }
                                }
                                break;
                            }
                        }
                    } catch (Exception $e) {
                        // Skip pages that fail
                    }
                }

                if ($syncCount > 0) {
                    $syncMessage = " Applied to {$syncCount} other page(s).";
                }
                if ($skipCount > 0) {
                    $syncMessage .= " Skipped {$skipCount} custom page(s).";
                }
            }
        }

        // Return success with replacement count
        return [
            'success' => true,
            'replacements' => $replacements,
            'message' => 'Content replaced and saved as draft.' . $syncMessage
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => sanitizeMcpError($e->getMessage())];
    }
}
