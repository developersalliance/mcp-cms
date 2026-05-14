<?php
/**
 * MCP tool handler dispatch table.
 *
 * Returns [tool_name => closure]. Each closure receives ($input) and returns
 * a result array. The 5 legacy handleX() functions call outputResult+exit
 * internally, so their wrappers never return.
 */

function getMcpHandlers($pageManager, $blockParser, $backupManager, $globalBackupManager, $blogManager, $uploadManager, $authorManager, $config, $isJsonRpc, $jsonRpcId) {
    return [
        'list_pages' => function ($input) use ($pageManager) {
            return ['success' => true, 'pages' => $pageManager->listPages()];
        },

        'list_blocks' => function ($input) use ($pageManager, $blockParser) {
            $pageId = normalizePageId($input['page_id'] ?? '');
            $pagePath = $pageManager->getPagePath($pageId);

            if (!$pagePath) {
                return ['success' => false, 'error' => 'Page not found'];
            }

            $blocks = $blockParser->parseBlocks($pagePath);
            $blockMetadata = array_map(function ($block) {
                return [
                    'name' => $block['name'],
                    'role' => $block['role'],
                    'custom' => $block['custom']
                ];
            }, $blocks);

            return ['success' => true, 'blocks' => $blockMetadata];
        },

        'update_block' => function ($input) use ($pageManager, $blockParser, $backupManager, $globalBackupManager) {
            $pageId = isset($input['page_id']) ? normalizePageId($input['page_id']) : null;
            $blockName = $input['name'] ?? '';
            $content = $input['content'] ?? '';
            $custom = $input['custom'] ?? null;

            if ($pageId === null || !$blockName || $content === '') {
                return ['success' => false, 'error' => 'Missing required parameters'];
            }

            $pagePath = $pageManager->getPagePath($pageId);
            if (!$pagePath) {
                return ['success' => false, 'error' => 'Page not found'];
            }

            $currentContent = $pageManager->hasDraft($pageId)
                ? $pageManager->getDraft($pageId)
                : file_get_contents($pagePath);

            try {
                $existingBlocks = $blockParser->parseBlocksFromString($currentContent);
                $isBlockCustom = false;
                foreach ($existingBlocks as $block) {
                    if ($block['name'] === $blockName) {
                        $isBlockCustom = $block['custom'] || ($custom === true);
                        break;
                    }
                }

                $updatedContent = $blockParser->updateBlockInString($currentContent, $blockName, $content, $custom);
                $pageManager->saveDraft($pageId, $updatedContent);
                $backupManager->createBackup($pageId, $pagePath);

                $syncMessage = '';

                if (!$isBlockCustom) {
                    $allPages = $pageManager->listPages();
                    $pagesToBackup = $blockParser->collectPagesWithBlock($allPages, $blockName, $pageId);

                    if (!empty($pagesToBackup)) {
                        $globalBackupManager->createGlobalBackup(
                            $pagesToBackup,
                            $blockName,
                            "Global update of block '{$blockName}' via MCP"
                        );

                        $syncResults = $blockParser->updateBlockGlobally($allPages, $blockName, $content, $pageId);
                        $syncCount = count($syncResults['updated']);
                        $skipCount = count($syncResults['skipped']);

                        if ($syncCount > 0) {
                            $syncMessage .= " Global block synced to {$syncCount} other page(s).";
                        }
                        if ($skipCount > 0) {
                            $syncMessage .= " Skipped {$skipCount} page(s) with custom override.";
                        }
                    }
                }

                return ['success' => true, 'message' => 'Block updated and saved as draft.' . $syncMessage];
            } catch (Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        },

        'duplicate_page' => function ($input) use ($pageManager) {
            $sourcePageId = $input['source_page_id'] ?? '';
            $newPageId = $input['new_page_id'] ?? '';

            if (!$sourcePageId || !$newPageId) {
                return ['success' => false, 'error' => 'Missing required parameters'];
            }

            $pageManager->duplicatePage($sourcePageId, $newPageId);
            return ['success' => true];
        },

        'delete_page' => function ($input) use ($pageManager, $backupManager) {
            $pageId = normalizePageId($input['page_id'] ?? '');

            if ($pageId === null) {
                return ['success' => false, 'error' => 'Missing page_id parameter'];
            }

            $pagePath = $pageManager->getPagePath($pageId);
            if ($pagePath) {
                $backupManager->createBackup($pageId, $pagePath);
            }

            $pageManager->deletePage($pageId);
            return ['success' => true];
        },

        'list_backups' => function ($input) use ($backupManager) {
            $pageId = normalizePageId($input['page_id'] ?? '');

            if (!isset($input['page_id'])) {
                return ['success' => false, 'error' => 'Missing page_id parameter'];
            }

            return ['success' => true, 'backups' => $backupManager->listBackups($pageId)];
        },

        'restore_backup' => function ($input) use ($pageManager, $backupManager) {
            $pageId = normalizePageId($input['page_id'] ?? '');
            $timestamp = $input['timestamp'] ?? '';

            if (!isset($input['page_id']) || !$timestamp) {
                return ['success' => false, 'error' => 'Missing required parameters'];
            }

            $pagePath = $pageManager->getPagePath($pageId);
            if (!$pagePath) {
                return ['success' => false, 'error' => 'Page not found'];
            }

            $backupManager->restoreBackup($pageId, $timestamp, $pagePath);
            return ['success' => true];
        },

        'list_global_backups' => function ($input) use ($globalBackupManager) {
            $backups = $globalBackupManager->listGlobalBackups();
            $formattedBackups = array_map(function ($b) {
                return [
                    'timestamp' => $b['timestamp'],
                    'date' => $b['date'],
                    'block_name' => $b['block_name'],
                    'description' => $b['description'] ?? '',
                    'pages_count' => count($b['pages'] ?? []),
                    'pages' => $b['pages'] ?? []
                ];
            }, $backups);
            return ['success' => true, 'backups' => $formattedBackups];
        },

        'restore_global_backup' => function ($input) use ($globalBackupManager, $pageManager) {
            $timestamp = $input['timestamp'] ?? '';

            if (!$timestamp) {
                return ['success' => false, 'error' => 'Missing timestamp parameter'];
            }

            try {
                $restoreResults = $globalBackupManager->restoreGlobalBackup($timestamp, $pageManager);
                $restoredCount = count($restoreResults['restored']);
                $failedCount = count($restoreResults['failed']);

                $result = [
                    'success' => true,
                    'message' => "Restored {$restoredCount} page(s) from global backup.",
                    'restored' => $restoreResults['restored'],
                    'failed' => $restoreResults['failed']
                ];

                if ($failedCount > 0) {
                    $result['message'] .= " Failed to restore {$failedCount} page(s).";
                }
                return $result;
            } catch (Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        },

        'search_blocks' => function ($input) use ($pageManager, $blockParser) {
            $searchText = $input['search_text'] ?? '';
            $searchMode = $input['search_mode'] ?? 'case_insensitive';

            if (!$searchText) {
                return ['success' => false, 'error' => 'Missing search_text parameter'];
            }

            $validModes = ['case_insensitive', 'case_sensitive', 'html_insensitive'];
            if (!in_array($searchMode, $validModes)) {
                return ['success' => false, 'error' => 'Invalid search_mode. Must be: case_insensitive, case_sensitive, or html_insensitive'];
            }

            $pages = $pageManager->listPages();
            $matches = [];

            foreach ($pages as $page) {
                $blocks = $blockParser->parseBlocks($page['path']);

                foreach ($blocks as $block) {
                    $found = false;

                    switch ($searchMode) {
                        case 'case_sensitive':
                            $found = (strpos($block['content'], $searchText) !== false);
                            break;
                        case 'html_insensitive':
                            $found = (stripos(strip_tags($block['content']), strip_tags($searchText)) !== false);
                            break;
                        case 'case_insensitive':
                        default:
                            $found = (stripos($block['content'], $searchText) !== false);
                            break;
                    }

                    if ($found) {
                        $matches[] = [
                            'page_id' => $page['id'],
                            'page_path' => $page['path'],
                            'block_name' => $block['name'],
                            'block_role' => $block['role'],
                            'block_custom' => $block['custom'],
                            'content_preview' => substr($block['content'], 0, 200)
                        ];
                    }
                }
            }

            return [
                'success' => true,
                'matches' => $matches,
                'count' => count($matches),
                'disambiguation_required' => count($matches) > 1,
                'disambiguation_message' => count($matches) > 1
                    ? 'Multiple blocks contain the same text. Ask the user which page/section is correct.'
                    : null
            ];
        },

        'get_usage_tips' => function ($input) {
            return [
                'success' => true,
                'tips' => [
                    'Always use search_blocks before update_block',
                    'Save large responses to files: curl ... > file.json',
                    'Homepage page_id: use "" or "/"',
                    'Ask user when multiple matches found'
                ]
            ];
        },

        'create_page' => function ($input) use ($pageManager) {
            $pageId = normalizePageId($input['page_id'] ?? '');
            $content = $input['content'] ?? '';

            if (!isset($input['page_id'])) {
                return ['success' => false, 'error' => 'Missing page_id parameter'];
            }
            if ($content === '') {
                return ['success' => false, 'error' => 'Missing content parameter'];
            }

            try {
                $pageManager->createPageFromHtml($pageId, $content);
                return ['success' => true, 'page_id' => $pageId];
            } catch (Throwable $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        },

        'read_page' => function ($input) use ($pageManager) {
            $pageId = normalizePageId($input['page_id'] ?? '');

            if (!isset($input['page_id'])) {
                return ['success' => false, 'error' => 'Missing page_id parameter'];
            }

            $pagePath = $pageManager->getPagePath($pageId);
            if (!$pagePath) {
                return ['success' => false, 'error' => 'Page not found'];
            }

            // Default to draft if one exists — every other handler does, so
            // the AI sees current edits, not the last-published version.
            // Caller can pass include_draft=false to force the live file.
            $includeDraft = !isset($input['include_draft']) || $input['include_draft'];
            $loaded = $includeDraft
                ? $pageManager->loadCurrentPageContent($pageId, $pagePath)
                : ['content' => (string)file_get_contents($pagePath), 'is_draft' => false];
            if (!$loaded) {
                return ['success' => false, 'error' => 'Page content unreadable'];
            }
            return [
                'success' => true,
                'page_id' => $pageId,
                'path' => $pagePath,
                'is_draft' => $loaded['is_draft'],
                'content' => $loaded['content'],
            ];
        },

        'publish_page' => function ($input) use ($pageManager) {
            $pageId = normalizePageId($input['page_id'] ?? '');

            if (!isset($input['page_id'])) {
                return ['success' => false, 'error' => 'Missing page_id parameter'];
            }

            try {
                $pageManager->publishDraft($pageId);
                return ['success' => true, 'message' => 'Draft published successfully'];
            } catch (Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        },

        'discard_draft' => function ($input) use ($pageManager) {
            $pageId = normalizePageId($input['page_id'] ?? '');

            if (!isset($input['page_id'])) {
                return ['success' => false, 'error' => 'Missing page_id parameter'];
            }

            try {
                $pageManager->discardDraft($pageId);
                return ['success' => true, 'message' => 'Draft discarded successfully'];
            } catch (Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        },

        'read_block' => function ($input) use ($pageManager, $blockParser) {
            $pageId = normalizePageId($input['page_id'] ?? '');
            $blockName = $input['name'] ?? '';

            if (!isset($input['page_id']) || !$blockName) {
                return ['success' => false, 'error' => 'Missing required parameters'];
            }

            $pagePath = $pageManager->getPagePath($pageId);
            if (!$pagePath) {
                return ['success' => false, 'error' => 'Page not found'];
            }

            $blocks = $blockParser->parseBlocks($pagePath);
            $foundBlock = null;
            foreach ($blocks as $block) {
                if ($block['name'] === $blockName) {
                    $foundBlock = $block;
                    break;
                }
            }

            if (!$foundBlock) {
                return ['success' => false, 'error' => 'Block not found'];
            }

            return ['success' => true, 'page_id' => $pageId, 'block' => $foundBlock];
        },

        'list_posts' => function ($input) use ($blogManager) {
            $collectionId = $input['collection_id'] ?? 'blog';
            $filters = [];
            if (!empty($input['status'])) $filters['status'] = $input['status'];
            if (!empty($input['author_id'])) $filters['author_id'] = $input['author_id'];
            if (!empty($input['tag'])) $filters['tag'] = $input['tag'];
            if (!empty($input['category'])) $filters['category'] = $input['category'];

            try {
                $posts = $blogManager->listPosts($collectionId, $filters);
                // Return metadata only (exclude content for listing)
                $summary = array_map(function ($p) {
                    return [
                        'slug' => $p['slug'],
                        'title' => $p['title'] ?? '',
                        'status' => $p['status'] ?? 'draft',
                        'author_id' => $p['author_id'] ?? '',
                        'created_at' => $p['created_at'] ?? '',
                        'published_at' => $p['published_at'] ?? null,
                        'scheduled_at' => $p['scheduled_at'] ?? null,
                        'categories' => $p['categories'] ?? [],
                        'tags' => $p['tags'] ?? [],
                        'excerpt' => $p['excerpt'] ?? '',
                        'featured' => $p['featured'] ?? false,
                    ];
                }, $posts);
                return [
                    'success' => true,
                    'collection_id' => $collectionId,
                    'posts' => $summary,
                    'count' => count($summary)
                ];
            } catch (Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        },

        'create_post' => function ($input) use ($blogManager) {
            $collectionId = $input['collection_id'] ?? 'blog';
            $slug = $input['slug'] ?? '';

            if (!$slug) {
                return ['success' => false, 'error' => 'Missing slug parameter'];
            }

            $data = [];
            foreach (['title', 'content', 'excerpt', 'author_id', 'featured_image', 'featured_image_alt'] as $field) {
                if (isset($input[$field])) $data[$field] = $input[$field];
            }
            if (isset($input['categories'])) $data['categories'] = (array) $input['categories'];
            if (isset($input['tags'])) $data['tags'] = (array) $input['tags'];
            if (isset($input['featured'])) $data['featured'] = (bool) $input['featured'];
            if (isset($input['seo'])) $data['seo'] = (array) $input['seo'];

            try {
                $post = $blogManager->createPost($collectionId, $slug, $data);
                return [
                    'success' => true,
                    'collection_id' => $collectionId,
                    'slug' => $post['slug'],
                    'status' => 'draft'
                ];
            } catch (Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        },

        'read_post' => function ($input) use ($blogManager) {
            $collectionId = $input['collection_id'] ?? 'blog';
            $slug = $input['slug'] ?? '';

            if (!$slug) {
                return ['success' => false, 'error' => 'Missing slug parameter'];
            }

            try {
                $post = $blogManager->getPost($collectionId, $slug);
                if (!$post) {
                    throw new Exception("Post not found: {$slug}");
                }

                return [
                    'success' => true,
                    'collection_id' => $collectionId,
                    'post' => $post
                ];
            } catch (Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        },

        'update_post' => function ($input) use ($blogManager) {
            $collectionId = $input['collection_id'] ?? 'blog';
            $slug = $input['slug'] ?? '';

            if (!$slug) {
                return ['success' => false, 'error' => 'Missing slug parameter'];
            }

            try {
                $post = $blogManager->getPost($collectionId, $slug);
                if (!$post) {
                    throw new Exception("Post not found: {$slug}");
                }

                // Update allowed fields
                foreach (['title', 'content', 'excerpt', 'author_id', 'featured_image', 'featured_image_alt'] as $field) {
                    if (isset($input[$field])) $post[$field] = $input[$field];
                }
                if (isset($input['categories'])) $post['categories'] = (array) $input['categories'];
                if (isset($input['tags'])) $post['tags'] = (array) $input['tags'];
                if (isset($input['featured'])) $post['featured'] = (bool) $input['featured'];
                if (isset($input['seo'])) $post['seo'] = (array) $input['seo'];
                if (isset($input['published_at'])) $post['published_at'] = $input['published_at'];

                $blogManager->savePost($collectionId, $slug, $post);

                return [
                    'success' => true,
                    'collection_id' => $collectionId,
                    'slug' => $slug,
                    'message' => 'Post updated.'
                ];
            } catch (Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        },

        'publish_post' => function ($input) use ($blogManager) {
            $collectionId = $input['collection_id'] ?? 'blog';
            $slug = $input['slug'] ?? '';

            if (!$slug) {
                return ['success' => false, 'error' => 'Missing slug parameter'];
            }

            try {
                $blogManager->publishPost($collectionId, $slug);
                return [
                    'success' => true,
                    'collection_id' => $collectionId,
                    'slug' => $slug,
                    'status' => 'published'
                ];
            } catch (Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        },

        'unpublish_post' => function ($input) use ($blogManager) {
            $collectionId = $input['collection_id'] ?? 'blog';
            $slug = $input['slug'] ?? '';

            if (!$slug) {
                return ['success' => false, 'error' => 'Missing slug parameter'];
            }

            try {
                $blogManager->unpublishPost($collectionId, $slug);
                return [
                    'success' => true,
                    'collection_id' => $collectionId,
                    'slug' => $slug,
                    'status' => 'draft'
                ];
            } catch (Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        },

        'delete_post' => function ($input) use ($blogManager) {
            $collectionId = $input['collection_id'] ?? 'blog';
            $slug = $input['slug'] ?? '';

            if (!$slug) {
                return ['success' => false, 'error' => 'Missing slug parameter'];
            }

            try {
                $blogManager->deletePost($collectionId, $slug);
                return [
                    'success' => true,
                    'collection_id' => $collectionId,
                    'slug' => $slug,
                    'message' => 'Post deleted.'
                ];
            } catch (Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        },

        'schedule_post' => function ($input) use ($blogManager) {
            $collectionId = $input['collection_id'] ?? 'blog';
            $slug = $input['slug'] ?? '';
            $scheduledAt = $input['scheduled_at'] ?? '';

            if (!$slug) {
                return ['success' => false, 'error' => 'Missing slug parameter'];
            }
            if (!$scheduledAt) {
                return ['success' => false, 'error' => 'Missing scheduled_at parameter'];
            }

            try {
                $blogManager->schedulePost($collectionId, $slug, $scheduledAt);
                return [
                    'success' => true,
                    'collection_id' => $collectionId,
                    'slug' => $slug,
                    'status' => 'scheduled',
                    'scheduled_at' => $scheduledAt
                ];
            } catch (Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        },

        'list_authors' => function ($input) use ($authorManager) {
            return [
                'success' => true,
                'authors' => $authorManager->listAuthors()
            ];
        },

        'get_author' => function ($input) use ($authorManager) {
            $id = $input['author_id'] ?? '';
            if (!$id) {
                return ['success' => false, 'error' => 'Missing author_id parameter'];
            }

            $author = $authorManager->getAuthor($id);
            if (!$author) {
                return ['success' => false, 'error' => "Author not found: {$id}"];
            }

            return ['success' => true, 'author' => $author];
        },

        'manage_author' => function ($input) use ($authorManager) {
            $action = $input['action'] ?? '';
            $id = $input['author_id'] ?? '';

            if (!$action) {
                return ['success' => false, 'error' => 'Missing action parameter (create, update, delete)'];
            }
            if (!$id) {
                return ['success' => false, 'error' => 'Missing author_id parameter'];
            }

            try {
                $data = [];
                foreach (['name', 'email', 'bio', 'avatar', 'role'] as $field) {
                    if (isset($input[$field])) $data[$field] = $input[$field];
                }
                if (isset($input['social'])) $data['social'] = (array) $input['social'];

                switch ($action) {
                    case 'create':
                        $authorManager->createAuthor($id, $data);
                        return ['success' => true, 'message' => "Author '{$id}' created."];
                    case 'update':
                        $authorManager->updateAuthor($id, $data);
                        return ['success' => true, 'message' => "Author '{$id}' updated."];
                    case 'delete':
                        $authorManager->deleteAuthor($id);
                        return ['success' => true, 'message' => "Author '{$id}' deleted."];
                    default:
                        return ['success' => false, 'error' => "Invalid action: {$action}. Use create, update, or delete."];
                }
            } catch (Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        },

        'find_and_replace_block_content' => function ($input) use ($pageManager, $blockParser, $backupManager, $globalBackupManager) {
            return handleFindAndReplaceBlockContent($input, $pageManager, $blockParser, $backupManager, $globalBackupManager);
        },

        'insert_block' => function ($input) use ($pageManager, $blockParser, $backupManager) {
            return handleInsertBlock($input, $pageManager, $blockParser, $backupManager);
        },

        'search_in_page' => function ($input) use ($pageManager) {
            return handleSearchInPage($input, $pageManager);
        },

        'get_page_region' => function ($input) use ($pageManager) {
            return handleGetPageRegion($input, $pageManager);
        },

        'update_page_region' => function ($input) use ($pageManager, $backupManager) {
            return handleUpdatePageRegion($input, $pageManager, $backupManager);
        },

        'upload_file' => function ($input) use ($uploadManager) {
            $base64Data = $input['data'] ?? '';
            $filename = $input['filename'] ?? '';
            $subdir = $input['subdir'] ?? null;

            if (!$base64Data || !$filename) {
                return ['success' => false, 'error' => 'Missing required parameters: data, filename'];
            }

            if (strlen($base64Data) > 14 * 1024 * 1024) {
                return ['success' => false, 'error' => 'Payload exceeds maximum allowed size'];
            }

            $filename = str_replace(["\0", '\\', '/'], '', (string)$filename);
            $filename = basename($filename);
            if ($filename === '' || $filename[0] === '.') {
                return ['success' => false, 'error' => 'Invalid filename'];
            }

            $mcpAllowedFileExt = [
                'jpg','jpeg','png','gif','webp',
                'pdf','doc','docx','xls','xlsx','ppt','pptx',
                'txt','csv','md','zip',
                'mp3','mp4','webm','ogg',
            ];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($ext === 'svg') {
                return ['success' => false, 'error' => 'SVG uploads are disabled for security reasons. Convert to PNG/JPG first.'];
            }
            if ($ext === '' || !in_array($ext, $mcpAllowedFileExt, true)) {
                return ['success' => false, 'error' => 'File type not allowed'];
            }

            $blockedFrag = ['php','phtml','phar','php3','php4','php5','php7','pht','pl','py','sh','cgi','asp','aspx','jsp','htaccess','htpasswd'];
            $nameLower = strtolower($filename);
            foreach ($blockedFrag as $bad) {
                if (preg_match('/(^|\.)' . preg_quote($bad, '/') . '(\.|$)/', $nameLower)) {
                    return ['success' => false, 'error' => 'File type not allowed'];
                }
            }

            if ($subdir !== null) {
                $subdir = str_replace(["\0", '\\'], '', (string)$subdir);
                if (strpos($subdir, '..') !== false || !preg_match('#^[a-zA-Z0-9_./-]*$#', $subdir)) {
                    return ['success' => false, 'error' => 'Invalid subdir'];
                }
            }

            return $uploadManager->uploadFile($base64Data, $filename, $subdir);
        },

        'upload_image' => function ($input) use ($uploadManager) {
            $base64Data = $input['data'] ?? '';
            $filename = $input['filename'] ?? '';
            $subdir = $input['subdir'] ?? null;

            if (!$base64Data || !$filename) {
                return ['success' => false, 'error' => 'Missing required parameters: data, filename'];
            }

            if (strlen($base64Data) > 14 * 1024 * 1024) {
                return ['success' => false, 'error' => 'Payload exceeds maximum allowed size'];
            }

            $filename = str_replace(["\0", '\\', '/'], '', (string)$filename);
            $filename = basename($filename);
            if ($filename === '' || $filename[0] === '.') {
                return ['success' => false, 'error' => 'Invalid filename'];
            }

            $mcpAllowedImageExt = ['jpg','jpeg','png','gif','webp'];
            $imgExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($imgExt !== '' && !in_array($imgExt, $mcpAllowedImageExt, true)) {
                return ['success' => false, 'error' => 'Image type not allowed'];
            }

            if ($subdir !== null) {
                $subdir = str_replace(["\0", '\\'], '', (string)$subdir);
                if (strpos($subdir, '..') !== false || !preg_match('#^[a-zA-Z0-9_./-]*$#', $subdir)) {
                    return ['success' => false, 'error' => 'Invalid subdir'];
                }
            }

            return $uploadManager->uploadImage($base64Data, $filename, $subdir);
        },

        'get_page_meta' => function ($input) use ($pageManager) {
            $pageId = isset($input['page_id']) ? normalizePageId($input['page_id']) : null;
            if ($pageId === null) {
                return ['success' => false, 'error' => 'Missing required parameter: page_id'];
            }
            $pagePath = $pageManager->getPagePath($pageId);
            if (!$pagePath) {
                return ['success' => false, 'error' => 'Page not found'];
            }
            $content = $pageManager->hasDraft($pageId)
                ? $pageManager->getDraft($pageId)
                : file_get_contents($pagePath);
            require_once __DIR__ . '/../core/PageMeta.php';
            $meta = (new PageMeta())->extract($content);
            return ['success' => true, 'page_id' => $pageId, 'meta' => $meta, 'source' => $pageManager->hasDraft($pageId) ? 'draft' : 'live'];
        },

        'update_page_meta' => function ($input) use ($pageManager, $backupManager) {
            $pageId = isset($input['page_id']) ? normalizePageId($input['page_id']) : null;
            if ($pageId === null) {
                return ['success' => false, 'error' => 'Missing required parameter: page_id'];
            }
            $pagePath = $pageManager->getPagePath($pageId);
            if (!$pagePath) {
                return ['success' => false, 'error' => 'Page not found'];
            }
            // Build updates dict (whitelist of supported keys)
            $allowed = ['title','description','keywords','canonical','robots','author','viewport','theme_color','generator','og','twitter','ai','json_ld'];
            $updates = [];
            foreach ($allowed as $k) {
                if (array_key_exists($k, $input)) $updates[$k] = $input[$k];
            }
            if (empty($updates)) {
                return ['success' => false, 'error' => 'No supported meta keys provided. Allowed: ' . implode(', ', $allowed)];
            }
            $current = $pageManager->hasDraft($pageId)
                ? $pageManager->getDraft($pageId)
                : file_get_contents($pagePath);
            require_once __DIR__ . '/../core/PageMeta.php';
            $meta = new PageMeta();
            $updated = $meta->apply($current, $updates);
            if ($updated === $current) {
                return ['success' => true, 'message' => 'No changes — provided values matched existing meta.'];
            }
            try {
                $pageManager->saveDraft($pageId, $updated);
                $backupManager->createBackup($pageId, $pagePath);
                return [
                    'success' => true,
                    'message' => 'Meta updated and saved as draft.',
                    'changed_keys' => array_keys($updates),
                ];
            } catch (Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        },

        'get_ai_txt' => function ($input) use ($config) {
            $path = rtrim($config['root_dir'], '/') . '/ai.txt';
            if (!file_exists($path)) {
                return ['success' => true, 'exists' => false, 'content' => ''];
            }
            $content = @file_get_contents($path);
            if ($content === false) {
                return ['success' => false, 'error' => 'Could not read ai.txt'];
            }
            return ['success' => true, 'exists' => true, 'content' => $content, 'size' => strlen($content)];
        },

        'update_ai_txt' => function ($input) use ($config) {
            if (!array_key_exists('content', $input)) {
                return ['success' => false, 'error' => 'Missing required parameter: content'];
            }
            $content = (string)$input['content'];
            // Reject control chars except \r\n\t — basic safety
            if (preg_match('#[\x00-\x08\x0B\x0C\x0E-\x1F]#', $content)) {
                return ['success' => false, 'error' => 'Content contains disallowed control characters'];
            }
            if (strlen($content) > 1024 * 1024) {
                return ['success' => false, 'error' => 'Content too large (>1MB)'];
            }
            $path = rtrim($config['root_dir'], '/') . '/ai.txt';
            $tmp = $path . '.tmp.' . uniqid();
            if (@file_put_contents($tmp, $content) === false) {
                return ['success' => false, 'error' => 'Could not write ai.txt'];
            }
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                return ['success' => false, 'error' => 'Could not finalize ai.txt write'];
            }
            return ['success' => true, 'message' => 'ai.txt written.', 'size' => strlen($content)];
        },
    ];
}
