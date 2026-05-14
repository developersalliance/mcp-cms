<?php
/**
 * Admin Block Editor
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/PageManager.php';
require_once __DIR__ . '/../core/BlockParser.php';
require_once __DIR__ . '/../core/BackupManager.php';
require_once __DIR__ . '/../core/GlobalBackupManager.php';
require_once __DIR__ . '/../core/SitemapGenerator.php';
require_once __DIR__ . '/../core/PageSettings.php';
require_once __DIR__ . '/../core/CSRF.php';

$reservedFolders = $config['reserved_folders'] ?? ['cms'];
$backupManager = new BackupManager($config['backups_dir'], $config['max_backups_per_page']);
$globalBackupManager = new GlobalBackupManager($config['backups_dir']);
$sitemapGenerator = new SitemapGenerator($config['root_dir'], $config['base_url'] ?? 'http://localhost', $reservedFolders, $config['drafts_dir'] ?? null);
$pageSettings = new PageSettings($config['cms_dir'] . '/settings');
$pageManager = new PageManager($config['root_dir'], $reservedFolders, $config['drafts_dir'] ?? null, $backupManager, $sitemapGenerator, $pageSettings);
$blockParser = new BlockParser();

$pageId = $_GET['page_id'] ?? '';
$pagePath = $pageManager->getPagePath($pageId);

if (!$pagePath) {
    header('Location: /cms/admin/pages.php');
    exit;
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'update_block':
                $blockName = $_POST['block_name'] ?? '';
                $blockContent = $_POST['block_content'] ?? '';
                $blockCustom = isset($_POST['block_custom']) ? true : false;

                // Get draft content (from existing draft or live page)
                $draftContent = $pageManager->hasDraft($pageId)
                    ? $pageManager->getDraft($pageId)
                    : file_get_contents($pagePath);

                // Update the block in-memory and save as draft
                $updatedContent = $blockParser->updateBlockInString($draftContent, $blockName, $blockContent, $blockCustom);
                $pageManager->saveDraft($pageId, $updatedContent);

                $successMessage = "Block saved as draft.";

                // If block is NOT custom, sync to all other pages (global block update)
                if (!$blockCustom) {
                    $allPages = $pageManager->listPages();

                    $pagesToBackup = $blockParser->collectPagesWithBlock($allPages, $blockName, $pageId);

                    // Create global backup before syncing
                    if (!empty($pagesToBackup)) {
                        $globalBackupManager->createGlobalBackup(
                            $pagesToBackup,
                            $blockName,
                            "Global update of block '{$blockName}'"
                        );

                        // Sync the block to other pages
                        $syncResults = $blockParser->updateBlockGlobally(
                            $allPages,
                            $blockName,
                            $blockContent,
                            $pageId
                        );

                        $syncCount = count($syncResults['updated']);
                        $skipCount = count($syncResults['skipped']);

                        if ($syncCount > 0) {
                            $successMessage .= " Synced to {$syncCount} other page(s).";
                        }
                        if ($skipCount > 0) {
                            $successMessage .= " Skipped {$skipCount} custom page(s).";
                        }
                    }
                }

                $successMessage .= " Preview or publish when ready.";
                break;

            case 'publish':
                $pageManager->publishDraft($pageId);
                $successMessage = "Draft published successfully.";
                break;

            case 'discard':
                if ($pageManager->hasDraft($pageId)) {
                    $pageManager->discardDraft($pageId);
                    $successMessage = "Draft discarded.";
                }
                break;

            case 'update_meta':
                require_once __DIR__ . '/../core/PageMeta.php';
                $current = $pageManager->hasDraft($pageId)
                    ? $pageManager->getDraft($pageId)
                    : file_get_contents($pagePath);
                $updates = [];
                foreach (['title','description','keywords','canonical','robots','author','og_title','og_description','og_image','twitter_title','twitter_description','twitter_image'] as $f) {
                    if (array_key_exists($f, $_POST)) {
                        $updates[$f] = trim((string)$_POST[$f]);
                    }
                }
                // Re-shape og_* / twitter_* into nested arrays
                $reshape = function (array &$updates, string $prefix, string $bucket) {
                    foreach ($updates as $k => $v) {
                        if (str_starts_with($k, $prefix . '_')) {
                            $sub = substr($k, strlen($prefix) + 1);
                            $updates[$bucket] = ($updates[$bucket] ?? []);
                            $updates[$bucket][$sub] = $v;
                            unset($updates[$k]);
                        }
                    }
                };
                $reshape($updates, 'og', 'og');
                $reshape($updates, 'twitter', 'twitter');
                // Optional raw JSON-LD textarea
                if (!empty($_POST['json_ld'])) {
                    $raw = trim((string)$_POST['json_ld']);
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        // Allow either a single object or an array of objects
                        $updates['json_ld'] = isset($decoded[0]) && is_array($decoded[0]) ? $decoded : [$decoded];
                    } else {
                        $updates['json_ld'] = [$raw];
                    }
                }
                if (empty($updates)) {
                    throw new Exception('No metadata fields provided');
                }
                $meta = new PageMeta();
                $next = $meta->apply($current, $updates);
                if ($next === $current) {
                    $successMessage = 'Metadata unchanged.';
                } else {
                    $pageManager->saveDraft($pageId, $next);
                    $backupManager->createBackup($pageId, $pagePath);
                    $successMessage = 'Metadata updated and saved as draft.';
                }
                break;

            case 'restore_backup':
                $timestamp = $_POST['timestamp'] ?? '';
                if (!$timestamp) {
                    throw new Exception("No backup timestamp provided");
                }
                $backupManager->restoreBackup($pageId, $timestamp, $pagePath);
                // Clear any existing draft since we restored
                if ($pageManager->hasDraft($pageId)) {
                    $pageManager->discardDraft($pageId);
                }
                $successMessage = "Backup restored successfully.";
                break;

            case 'restore_global_backup':
                $timestamp = $_POST['timestamp'] ?? '';
                if (!$timestamp) {
                    throw new Exception("No backup timestamp provided");
                }
                $results = $globalBackupManager->restoreGlobalBackup($timestamp, $pageManager);
                $restoredCount = count($results['restored']);
                $failedCount = count($results['failed']);

                $successMessage = "Restored {$restoredCount} page(s) from global backup.";
                if ($failedCount > 0) {
                    $successMessage .= " Failed to restore {$failedCount} page(s).";
                }

                // Clear any existing draft for current page since we restored
                if ($pageManager->hasDraft($pageId)) {
                    $pageManager->discardDraft($pageId);
                }
                break;
        }
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

$hasDraft = $pageManager->hasDraft($pageId);

// Custom-CSS page settings removed — metadata now lives in the Page
// Metadata panel below (powered by core/PageMeta.php).

// Get backups for this page
$backups = $backupManager->listBackups($pageId);

// Get global backups that include this page
$allGlobalBackups = $globalBackupManager->listGlobalBackups();
$globalBackups = array_filter($allGlobalBackups, function($backup) use ($pageId) {
    return in_array($pageId, $backup['pages'] ?? []);
});

// Parse blocks from the page (use draft if exists, otherwise live)
try {
    if ($hasDraft) {
        $blocks = $blockParser->parseBlocksFromString($pageManager->getDraft($pageId));
    } else {
        // Parse from live page
        $blocks = $blockParser->parseBlocks($pagePath);
    }
} catch (Exception $e) {
    $errorMessage = "Failed to parse blocks: " . $e->getMessage();
    $blocks = [];
}

$loaded = $pageManager->loadCurrentPageContent($pageId, $pagePath);
$pageContent = $loaded ? $loaded['content'] : '';

// Pull the source page's full <head> (link, inline <style>, fonts, CDN
// scripts like Tailwind) so block previews look like the live site. Strip
// analytics and PHP scriptlets — they hit external endpoints / leak markers.
$pageHeadHtml = '';
if (preg_match('/<head[^>]*>(.*?)<\/head>/is', $pageContent, $headMatch)) {
    $pageHeadHtml = $headMatch[1];
    $stripPatterns = [
        '#<script[^>]*\b(?:googletagmanager|google-analytics|plausible|fathom\.gg|cdn\.segment\.com|mixpanel\.com|hotjar\.com|fullstory\.com|posthog\.com)[^<]*</script>#is',
        '#<script[^>]*>\s*[^<]*\b(?:gtag\(|dataLayer|fbq\()[^<]*</script>#is',
        '#<\?php.*?\?>#is',
    ];
    foreach ($stripPatterns as $p) {
        $pageHeadHtml = preg_replace($p, '', $pageHeadHtml);
    }
    // Also pick up any <style> blocks that live in <body> (some sites do this)
    if (preg_match_all('#<style\b[^>]*>.*?</style>#is', $pageContent, $bodyStyles, PREG_OFFSET_CAPTURE)) {
        $headStartPos = stripos($pageContent, '<head');
        $headEndPos = stripos($pageContent, '</head>');
        foreach ($bodyStyles[0] as $s) {
            $pos = $s[1];
            if ($pos < $headStartPos || $pos > $headEndPos) {
                $pageHeadHtml .= "\n" . $s[0];
            }
        }
    }
    $pageHeadHtml = trim($pageHeadHtml);
}

// Base URL for relative href/src resolution in the iframe
$baseUrl = isset($config['base_url']) && $config['base_url'] ? rtrim($config['base_url'], '/') . '/' : '';
if ($baseUrl === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUrl = $scheme . '://' . $host . '/';
}

// Legacy fallback: list of <link rel=stylesheet> URLs (kept for back-compat
// with the older block-editor-scripts variable; renderPreview prefers $pageHeadHtml).
$cssFiles = [];
if (preg_match_all('/<link[^>]+rel=["\']stylesheet["\'][^>]+href=["\']([^"\']+)["\']|<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']stylesheet["\']/', $pageContent, $matches)) {
    foreach ($matches[1] as $i => $url) {
        $cssUrl = $url ?: $matches[2][$i];
        if ($cssUrl) { $cssFiles[] = $cssUrl; }
    }
}

// Extract wrapper structure around each block for realistic preview.
// Uses BlockParser's start_pos/end_pos so we don't reinvent CMS:BLOCK regex.
function extractBlockWrapper($content, $blockName, BlockParser $parser) {
    $blocks = $parser->parseBlocksFromString($content);
    $target = null;
    foreach ($blocks as $b) {
        if ($b['name'] === $blockName) { $target = $b; break; }
    }
    if (!$target) {
        return ['before' => '', 'after' => ''];
    }

    // Slice page → before-marker / after-marker, then trim to <body>...</body>
    $beforeBlock = substr($content, 0, $target['start_pos']);
    $afterBlock  = substr($content, $target['end_pos']);

    $bodyPos = stripos($beforeBlock, '<body');
    if ($bodyPos !== false) {
        $bodyEnd = strpos($beforeBlock, '>', $bodyPos);
        if ($bodyEnd !== false) {
            $beforeBlock = substr($beforeBlock, $bodyEnd + 1);
        }
    }
    $bodyClosePos = stripos($afterBlock, '</body>');
    if ($bodyClosePos !== false) {
        $afterBlock = substr($afterBlock, 0, $bodyClosePos);
    }

    // Drop every OTHER block (markers + content) from the wrapper so only
    // chrome (header/footer/etc surrounding markup) remains.
    foreach ($blocks as $b) {
        if ($b['name'] === $blockName) continue;
        $segment = substr($content, $b['start_pos'], $b['end_pos'] - $b['start_pos']);
        $beforeBlock = str_replace($segment, '', $beforeBlock);
        $afterBlock  = str_replace($segment, '', $afterBlock);
    }

    return [
        'before' => trim($beforeBlock),
        'after' => trim($afterBlock)
    ];
}

// Build wrapper data for each block (uses BlockParser, no ad-hoc regex)
$blockWrappers = [];
foreach ($blocks as $block) {
    $blockWrappers[$block['name']] = extractBlockWrapper($pageContent, $block['name'], $blockParser);
}

require_once __DIR__ . '/../core/PageMeta.php';
// $pageContent above already resolved draft-vs-published via
// loadCurrentPageContent; reuse it for meta extraction.
$pageMeta = $pageContent !== '' ? (new PageMeta())->extract($pageContent) : [];

$pageTitle = 'Edit Page: ' . ($pageId ?: '/');
$activePage = 'pages';

require __DIR__ . '/includes/header.php';
?>

<?php require __DIR__ . '/includes/block-editor-assets.php'; ?>

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">
        Edit Page: <code class="text-accent-600"><?php echo htmlspecialchars($pageId ?: '/'); ?></code>
        <?php if ($hasDraft): ?>
            <span data-draft-badge class="ml-3 px-2.5 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400">Has Draft</span>
        <?php endif; ?>
    </h1>
    <div class="flex flex-wrap items-center gap-3 text-sm">
        <a href="/cms/admin/pages.php" class="text-accent-600 hover:text-accent-700">&larr; Back to Pages</a>
        <span class="text-gray-400 dark:text-gray-600">|</span>
        <a href="/cms/admin/edit-ai.php?page_id=<?php echo urlencode($pageId); ?>" class="text-accent-600 dark:text-accent-400 hover:text-accent-700">Edit with AI</a>
        <span class="text-gray-400 dark:text-gray-600">|</span>
        <a href="/cms/admin/preview.php?page_id=<?php echo urlencode($pageId); ?>" target="_blank" class="text-emerald-600 dark:text-emerald-400 hover:text-emerald-700">Preview Live</a>

        <?php if ($hasDraft): ?>
            <span class="text-gray-400 dark:text-gray-600">|</span>
            <a href="/cms/admin/preview.php?page_id=<?php echo urlencode($pageId); ?>&draft=1" target="_blank" class="text-amber-600 dark:text-amber-400 hover:text-amber-700">Preview Draft</a>
            <span class="text-gray-400 dark:text-gray-600">|</span>
            <form method="post" class="inline" onsubmit="return confirm('Publish this draft to make it live?');">
                <?php echo CSRF::inputField(); ?>
                <input type="hidden" name="action" value="publish">
                <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1 rounded-md bg-emerald-600 hover:bg-emerald-700 text-white font-medium text-xs shadow-sm">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                    Publish draft
                </button>
            </form>
            <form method="post" class="inline" onsubmit="return confirm('Discard the draft? This cannot be undone.');">
                <?php echo CSRF::inputField(); ?>
                <input type="hidden" name="action" value="discard">
                <button type="submit" class="inline-flex items-center px-2.5 py-1 rounded-md text-gray-500 dark:text-gray-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 text-xs">Discard</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if (isset($successMessage)): ?>
    <div class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-xl p-4 mb-6 flex items-center gap-3">
        <div class="w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
        </div>
        <p class="text-emerald-800 dark:text-emerald-300 font-medium"><?php echo htmlspecialchars($successMessage); ?></p>
    </div>
<?php endif; ?>

<?php if (isset($errorMessage)): ?>
    <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl p-4 mb-6 flex items-center gap-3">
        <div class="w-8 h-8 rounded-lg bg-red-100 dark:bg-red-900/30 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </div>
        <p class="text-red-800 dark:text-red-300 font-medium"><?php echo htmlspecialchars($errorMessage); ?></p>
    </div>
<?php endif; ?>

<?php /* Page Settings (custom CSS) panel removed — handled via Page Metadata + AI editor instead. */ ?>

<!-- Backup Management Accordion -->
<?php $totalBackups = count($backups) + count($globalBackups); ?>
<div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 mb-6" x-data="{ backupsOpen: false, backupTab: 'page' }">
    <button
        type="button"
        @click="backupsOpen = !backupsOpen"
        class="w-full flex items-center justify-between p-6 text-left hover:bg-surface-50 dark:hover:bg-dark-300 rounded-2xl transition">
        <h2 class="text-xl font-semibold text-gray-900 dark:text-white flex items-center gap-3">
            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <span>Version History</span>
            <?php if ($totalBackups > 0): ?>
                <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-gray-100 dark:bg-dark-300 text-gray-600 dark:text-gray-400"><?php echo $totalBackups; ?></span>
            <?php endif; ?>
        </h2>
        <svg
            class="w-5 h-5 text-gray-500 dark:text-gray-400 transition-transform"
            :class="backupsOpen ? 'rotate-180' : ''"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
        </svg>
    </button>

    <div x-show="backupsOpen" x-cloak class="border-t border-surface-200 dark:border-dark-200">
        <!-- Tabs -->
        <div class="flex border-b border-surface-200 dark:border-dark-200">
            <button
                type="button"
                @click="backupTab = 'page'"
                :class="backupTab === 'page' ? 'border-accent-500 text-accent-600 dark:text-accent-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                class="flex-1 px-4 py-3 text-sm font-medium border-b-2 transition">
                Page Backups
                <?php if (count($backups) > 0): ?>
                    <span class="ml-1.5 px-1.5 py-0.5 text-xs rounded-full bg-gray-100 dark:bg-dark-300"><?php echo count($backups); ?></span>
                <?php endif; ?>
            </button>
            <button
                type="button"
                @click="backupTab = 'global'"
                :class="backupTab === 'global' ? 'border-accent-500 text-accent-600 dark:text-accent-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                class="flex-1 px-4 py-3 text-sm font-medium border-b-2 transition">
                Global Backups
                <?php if (count($globalBackups) > 0): ?>
                    <span class="ml-1.5 px-1.5 py-0.5 text-xs rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400"><?php echo count($globalBackups); ?></span>
                <?php endif; ?>
            </button>
        </div>

        <!-- Page Backups Tab -->
        <div x-show="backupTab === 'page'" class="p-6">
            <?php if (empty($backups)): ?>
                <p class="text-gray-500 dark:text-gray-400 text-center py-4">No page backups available yet. Page backups are created when you publish changes to custom blocks.</p>
            <?php else: ?>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Page backups are created when you publish changes. These restore only this page.</p>
                <div class="space-y-3">
                    <?php foreach ($backups as $i => $backup): ?>
                        <div class="flex items-center justify-between p-4 bg-surface-50 dark:bg-dark-300 rounded-xl <?php echo $i === 0 ? 'border-2 border-emerald-200 dark:border-emerald-800' : ''; ?>">
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 rounded-lg bg-gray-200 dark:bg-dark-200 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-5 h-5 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <div class="font-medium text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($backup['date']); ?>
                                        <?php if ($i === 0): ?>
                                            <span class="ml-2 px-2 py-0.5 text-xs font-medium rounded-full bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400">Latest</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                        <?php
                                        $fileSize = file_exists($backup['path']) ? filesize($backup['path']) : 0;
                                        echo number_format($fileSize / 1024, 1) . ' KB';
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <a href="/cms/admin/preview-backup.php?page_id=<?php echo urlencode($pageId); ?>&timestamp=<?php echo urlencode($backup['timestamp']); ?>"
                                   target="_blank"
                                   class="px-3 py-1.5 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-dark-400 border border-gray-300 dark:border-dark-200 rounded-lg hover:bg-gray-50 dark:hover:bg-dark-300 transition">
                                    Preview
                                </a>
                                <form method="post" class="inline" onsubmit="return confirm('Restore this backup? This will replace the current live version.');">
                                    <?php echo CSRF::inputField(); ?>
                                    <input type="hidden" name="action" value="restore_backup">
                                    <input type="hidden" name="timestamp" value="<?php echo htmlspecialchars($backup['timestamp']); ?>">
                                    <button type="submit" class="px-3 py-1.5 text-sm font-medium text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg hover:bg-amber-100 dark:hover:bg-amber-900/30 transition">
                                        Restore
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Global Backups Tab -->
        <div x-show="backupTab === 'global'" class="p-6">
            <?php if (empty($globalBackups)): ?>
                <p class="text-gray-500 dark:text-gray-400 text-center py-4">No global backups available yet. Global backups are created when you edit blocks without the custom flag (header, footer, etc.).</p>
            <?php else: ?>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Global backups are created when editing shared blocks (header, footer). Restoring will revert <strong>all affected pages</strong> at once.</p>
                <div class="space-y-3">
                    <?php foreach ($globalBackups as $i => $gbackup): ?>
                        <div class="flex items-center justify-between p-4 bg-surface-50 dark:bg-dark-300 rounded-xl <?php echo $i === 0 ? 'border-2 border-blue-200 dark:border-blue-800' : ''; ?>">
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                                    </svg>
                                </div>
                                <div>
                                    <div class="font-medium text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($gbackup['date']); ?>
                                        <?php if ($i === 0): ?>
                                            <span class="ml-2 px-2 py-0.5 text-xs font-medium rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400">Latest</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                        Block: <code class="text-accent-600"><?php echo htmlspecialchars($gbackup['block_name']); ?></code>
                                        &bull; <?php echo count($gbackup['pages']); ?> page(s) affected
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <form method="post" class="inline" onsubmit="return confirm('Restore this global backup?\n\nThis will revert <?php echo count($gbackup['pages']); ?> page(s) to their state from <?php echo htmlspecialchars($gbackup['date']); ?>.\n\nAffected pages:\n<?php echo htmlspecialchars(implode(', ', array_map(function($p) { return $p ?: '/'; }, $gbackup['pages']))); ?>');">
                                    <?php echo CSRF::inputField(); ?>
                                    <input type="hidden" name="action" value="restore_global_backup">
                                    <input type="hidden" name="timestamp" value="<?php echo htmlspecialchars($gbackup['timestamp']); ?>">
                                    <button type="submit" class="px-3 py-1.5 text-sm font-medium text-blue-700 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg hover:bg-blue-100 dark:hover:bg-blue-900/30 transition">
                                        Restore All Pages
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Page metadata — collapsed by default -->
<div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 mb-6" x-data="{ open: false }">
    <button type="button" @click="open = !open" class="w-full flex items-center justify-between p-6 cursor-pointer hover:bg-surface-50 dark:hover:bg-dark-300 rounded-2xl transition" :class="{ 'rounded-b-none': open }">
        <div class="flex items-center gap-3">
            <svg class="w-5 h-5 text-gray-400 transition-transform" :class="{ '-rotate-90': !open }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Page Metadata</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">title · description · canonical · OG · Twitter · JSON-LD</span>
        </div>
        <span class="text-xs text-gray-500 dark:text-gray-400 truncate max-w-[40%]"><?php echo htmlspecialchars($pageMeta['title'] ?? ''); ?></span>
    </button>
    <div x-show="open" x-collapse class="px-6 pb-6 border-t border-surface-200 dark:border-dark-200">
        <form method="post" class="grid gap-4 mt-5">
            <?php echo CSRF::inputField(); ?>
            <input type="hidden" name="action" value="update_meta">

            <div class="grid md:grid-cols-2 gap-4">
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1.5">Title</span>
                    <input type="text" name="title" value="<?php echo htmlspecialchars($pageMeta['title'] ?? ''); ?>" class="w-full px-3 py-2 bg-white dark:bg-dark-300 border border-surface-200 dark:border-dark-200 rounded-lg text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-accent-500">
                </label>
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1.5">Canonical URL</span>
                    <input type="text" name="canonical" value="<?php echo htmlspecialchars($pageMeta['canonical'] ?? ''); ?>" class="w-full px-3 py-2 bg-white dark:bg-dark-300 border border-surface-200 dark:border-dark-200 rounded-lg text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-accent-500">
                </label>
            </div>
            <label class="block">
                <span class="block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1.5">Description</span>
                <textarea name="description" rows="2" class="w-full px-3 py-2 bg-white dark:bg-dark-300 border border-surface-200 dark:border-dark-200 rounded-lg text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-accent-500"><?php echo htmlspecialchars($pageMeta['description'] ?? ''); ?></textarea>
            </label>
            <div class="grid md:grid-cols-2 gap-4">
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1.5">Keywords</span>
                    <input type="text" name="keywords" value="<?php echo htmlspecialchars($pageMeta['keywords'] ?? ''); ?>" class="w-full px-3 py-2 bg-white dark:bg-dark-300 border border-surface-200 dark:border-dark-200 rounded-lg text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-accent-500">
                </label>
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1.5">Robots</span>
                    <input type="text" name="robots" placeholder="index, follow" value="<?php echo htmlspecialchars($pageMeta['robots'] ?? ''); ?>" class="w-full px-3 py-2 bg-white dark:bg-dark-300 border border-surface-200 dark:border-dark-200 rounded-lg text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-accent-500">
                </label>
            </div>

            <!-- Open Graph -->
            <div x-data="{ ogOpen: false }" class="rounded-lg border border-surface-200 dark:border-dark-200">
                <button type="button" @click="ogOpen = !ogOpen" class="w-full flex items-center justify-between px-4 py-3 text-left">
                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">Open Graph (Facebook, LinkedIn)</span>
                    <svg class="w-4 h-4 text-gray-400 transition-transform" :class="{ '-rotate-90': !ogOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </button>
                <div x-show="ogOpen" x-collapse class="px-4 pb-4 grid md:grid-cols-2 gap-3 border-t border-surface-200 dark:border-dark-200 pt-3">
                    <label class="block"><span class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">og:title</span><input type="text" name="og_title" value="<?php echo htmlspecialchars($pageMeta['og']['title'] ?? ''); ?>" class="w-full px-3 py-2 bg-white dark:bg-dark-300 border border-surface-200 dark:border-dark-200 rounded-md text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-accent-500"></label>
                    <label class="block"><span class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">og:image</span><input type="text" name="og_image" value="<?php echo htmlspecialchars($pageMeta['og']['image'] ?? ''); ?>" class="w-full px-3 py-2 bg-white dark:bg-dark-300 border border-surface-200 dark:border-dark-200 rounded-md text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-accent-500"></label>
                    <label class="block md:col-span-2"><span class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">og:description</span><textarea name="og_description" rows="2" class="w-full px-3 py-2 bg-white dark:bg-dark-300 border border-surface-200 dark:border-dark-200 rounded-md text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-accent-500"><?php echo htmlspecialchars($pageMeta['og']['description'] ?? ''); ?></textarea></label>
                </div>
            </div>

            <!-- Twitter Card -->
            <div x-data="{ twOpen: false }" class="rounded-lg border border-surface-200 dark:border-dark-200">
                <button type="button" @click="twOpen = !twOpen" class="w-full flex items-center justify-between px-4 py-3 text-left">
                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">Twitter Card</span>
                    <svg class="w-4 h-4 text-gray-400 transition-transform" :class="{ '-rotate-90': !twOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </button>
                <div x-show="twOpen" x-collapse class="px-4 pb-4 grid md:grid-cols-2 gap-3 border-t border-surface-200 dark:border-dark-200 pt-3">
                    <label class="block"><span class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">twitter:title</span><input type="text" name="twitter_title" value="<?php echo htmlspecialchars($pageMeta['twitter']['title'] ?? ''); ?>" class="w-full px-3 py-2 bg-white dark:bg-dark-300 border border-surface-200 dark:border-dark-200 rounded-md text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-accent-500"></label>
                    <label class="block"><span class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">twitter:image</span><input type="text" name="twitter_image" value="<?php echo htmlspecialchars($pageMeta['twitter']['image'] ?? ''); ?>" class="w-full px-3 py-2 bg-white dark:bg-dark-300 border border-surface-200 dark:border-dark-200 rounded-md text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-accent-500"></label>
                    <label class="block md:col-span-2"><span class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">twitter:description</span><textarea name="twitter_description" rows="2" class="w-full px-3 py-2 bg-white dark:bg-dark-300 border border-surface-200 dark:border-dark-200 rounded-md text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-accent-500"><?php echo htmlspecialchars($pageMeta['twitter']['description'] ?? ''); ?></textarea></label>
                </div>
            </div>

            <!-- JSON-LD -->
            <div x-data="{ jsonOpen: false }" class="rounded-lg border border-surface-200 dark:border-dark-200">
                <button type="button" @click="jsonOpen = !jsonOpen" class="w-full flex items-center justify-between px-4 py-3 text-left">
                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">JSON-LD structured data</span>
                    <svg class="w-4 h-4 text-gray-400 transition-transform" :class="{ '-rotate-90': !jsonOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </button>
                <div x-show="jsonOpen" x-collapse class="px-4 pb-4 border-t border-surface-200 dark:border-dark-200 pt-3">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">Paste a JSON-LD object (or array of objects). Saving replaces ALL existing JSON-LD scripts on the page. Leave empty to keep current scripts.</p>
                    <textarea name="json_ld" rows="8" class="w-full px-3 py-2 bg-white dark:bg-dark-300 border border-surface-200 dark:border-dark-200 rounded-md text-xs font-mono text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-accent-500" placeholder='{"@context":"https://schema.org","@type":"WebPage","name":"…"}'><?php echo !empty($pageMeta['json_ld']) ? htmlspecialchars(json_encode($pageMeta['json_ld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) : ''; ?></textarea>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2">
                <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-accent-600 hover:bg-accent-700 text-white text-sm font-semibold shadow-sm">Save metadata as draft</button>
            </div>
        </form>
    </div>
</div>

<?php if (empty($blocks)): ?>
    <div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 p-6">
        <p class="text-gray-600 dark:text-gray-400">No blocks found in this page.</p>
    </div>
<?php else: ?>
    <?php foreach ($blocks as $blockIndex => $block): ?>
        <?php require __DIR__ . '/includes/block-editor-block.php'; ?>
    <?php endforeach; ?>
<?php endif; ?>


<?php
// Set up variables for the shared block editor scripts
$blockEditorPageHead = $pageHeadHtml;
$blockEditorBaseUrl = $baseUrl;
$blockEditorCssFiles = $cssFiles;      // legacy fallback
$blockEditorWrappers = $blockWrappers; // legacy (no longer rendered, kept for back-compat)
$blockEditorCsrfToken = CSRF::getToken();
$blockEditorSaveUrl = '';  // Empty = use current URL

require __DIR__ . '/includes/block-editor-scripts.php';
?>

<?php require __DIR__ . '/includes/footer.php'; ?>
