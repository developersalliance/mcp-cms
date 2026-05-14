<?php
/**
 * Global Block Sync Tool
 *
 * Allows syncing non-custom blocks across multiple pages
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/PageManager.php';
require_once __DIR__ . '/../core/BlockParser.php';
require_once __DIR__ . '/../core/BackupManager.php';
require_once __DIR__ . '/../core/PageSettings.php';
require_once __DIR__ . '/../core/CSRF.php';

$reservedFolders = $config['reserved_folders'] ?? ['cms'];
$pageSettings = new PageSettings($config['cms_dir'] . '/settings');
$pageManager = new PageManager($config['root_dir'], $reservedFolders, null, null, null, $pageSettings);
$blockParser = new BlockParser();
$backupManager = new BackupManager($config['backups_dir'], $config['max_backups_per_page']);

// Handle sync operation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync') {
    CSRF::verifyOrDie();

    $sourcePageId = $_POST['source_page_id'] ?? '';
    $blockName = $_POST['block_name'] ?? '';
    $targetPages = $_POST['target_pages'] ?? [];

    try {
        if (!$blockName) {
            throw new Exception('Block name is required');
        }

        // Get source block content
        $sourcePath = $pageManager->getPagePath($sourcePageId);
        if (!$sourcePath) {
            throw new Exception('Source page not found');
        }

        $sourceBlocks = $blockParser->parseBlocks($sourcePath);
        $sourceBlock = null;

        foreach ($sourceBlocks as $block) {
            if ($block['name'] === $blockName) {
                $sourceBlock = $block;
                break;
            }
        }

        if (!$sourceBlock) {
            throw new Exception('Source block not found');
        }

        // Sync to target pages
        $syncedCount = 0;
        $skippedCount = 0;

        foreach ($targetPages as $targetPageId) {
            if ($targetPageId === $sourcePageId) {
                continue; // Skip source page
            }

            $targetPath = $pageManager->getPagePath($targetPageId);
            if (!$targetPath) {
                continue;
            }

            // Parse target page blocks
            $targetBlocks = $blockParser->parseBlocks($targetPath);
            $targetBlock = null;

            foreach ($targetBlocks as $block) {
                if ($block['name'] === $blockName) {
                    $targetBlock = $block;
                    break;
                }
            }

            if (!$targetBlock) {
                continue; // Block doesn't exist in target
            }

            if ($targetBlock['custom']) {
                $skippedCount++; // Skip custom blocks
                continue;
            }

            // Create backup
            $backupManager->createBackup($targetPageId, $targetPath);

            // Update the block
            $blockParser->updateBlock($targetPath, $blockName, $sourceBlock['content'], false);
            $syncedCount++;
        }

        $successMessage = "Synced to {$syncedCount} page(s). Skipped {$skippedCount} custom block(s).";

    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

// Get all pages and their blocks for selection
$pages = $pageManager->listPages();
$pageBlocks = [];

foreach ($pages as $page) {
    try {
        $blocks = $blockParser->parseBlocks($page['path']);
        $pageBlocks[$page['id']] = $blocks;
    } catch (Exception $e) {
        $pageBlocks[$page['id']] = [];
    }
}

// Get unique block names across all pages
$allBlockNames = [];
foreach ($pageBlocks as $blocks) {
    foreach ($blocks as $block) {
        $allBlockNames[$block['name']] = true;
    }
}
$allBlockNames = array_keys($allBlockNames);
sort($allBlockNames);

$pageTitle = 'Sync Blocks';
$activePage = 'sync';

require __DIR__ . '/includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900 mb-2">Global Block Sync</h1>
    <p class="text-gray-600">
        <a href="/cms/admin/" class="text-blue-600 hover:text-blue-800">&larr; Back to Dashboard</a>
    </p>
</div>

<?php if (isset($successMessage)): ?>
    <div class="bg-green-50 border-l-4 border-green-500 p-4 mb-6">
        <p class="text-green-700"><?php echo htmlspecialchars($successMessage); ?></p>
    </div>
<?php endif; ?>

<?php if (isset($errorMessage)): ?>
    <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
        <p class="text-red-700"><?php echo htmlspecialchars($errorMessage); ?></p>
    </div>
<?php endif; ?>

<div class="bg-white rounded-lg shadow-md p-6">
    <h2 class="text-xl font-semibold text-gray-900 mb-4">Sync a Block Across Pages</h2>

    <p class="text-gray-600 mb-6">This tool copies a block's content from one page to other pages. Only <strong>non-custom</strong> blocks will be updated.</p>

    <form method="post" id="syncForm" class="space-y-6">
        <?php echo CSRF::inputField(); ?>
        <input type="hidden" name="action" value="sync">

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Source Page:
            </label>
            <select name="source_page_id" id="sourcePageId" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="">-- Select source page --</option>
                <?php foreach ($pages as $page): ?>
                    <option value="<?php echo htmlspecialchars($page['id']); ?>">
                        <?php echo htmlspecialchars($page['id'] ?: '/ (Home)'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Block Name:
            </label>
            <select name="block_name" id="blockName" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="">-- Select block --</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Target Pages (select multiple):
            </label>
            <div class="border border-gray-300 rounded-md p-4 max-h-80 overflow-y-auto space-y-2">
                <?php foreach ($pages as $page): ?>
                    <label class="flex items-center">
                        <input type="checkbox" name="target_pages[]" value="<?php echo htmlspecialchars($page['id']); ?>" class="mr-2 h-4 w-4 text-blue-600 rounded">
                        <span class="text-sm text-gray-700"><?php echo htmlspecialchars($page['id'] ?: '/ (Home)'); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div>
            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 transition">Sync Block</button>
        </div>
    </form>
</div>

<script>
// Dynamic block selection based on source page
const pageBlocks = <?php echo json_encode($pageBlocks); ?>;
const sourcePageSelect = document.getElementById('sourcePageId');
const blockNameSelect = document.getElementById('blockName');

sourcePageSelect.addEventListener('change', function() {
    const pageId = this.value;
    blockNameSelect.innerHTML = '<option value="">-- Select block --</option>';

    if (pageId && pageBlocks[pageId]) {
        pageBlocks[pageId].forEach(function(block) {
            const option = document.createElement('option');
            option.value = block.name;
            option.textContent = block.name + (block.role ? ' (' + block.role + ')' : '') + (block.custom ? ' [custom]' : '');
            blockNameSelect.appendChild(option);
        });
    }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
