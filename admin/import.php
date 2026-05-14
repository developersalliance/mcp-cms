<?php

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/PageImporter.php';
require_once __DIR__ . '/../core/AIClient.php';
require_once __DIR__ . '/../core/TemplateImporter.php';
require_once __DIR__ . '/../core/BlockParser.php';
require_once __DIR__ . '/../core/CSRF.php';

$VALID_MODES = ['page'];

// AJAX: re-check a single file (used by manual-conversion help panel)
if (($_GET['action'] ?? '') === 'recheck') {
    header('Content-Type: application/json');
    $importer = new PageImporter($config['root_dir']);
    $info = $importer->getFileByRelativePath((string)($_GET['file'] ?? ''));
    if ($info === null) {
        echo json_encode(['success' => false, 'error' => 'File not found']);
        exit;
    }
    echo json_encode(['success' => true, 'file' => $info]);
    exit;
}

$importer = new PageImporter($config['root_dir']);
$aiClient = AIClient::fromConfig($config);
$aiConfigured = $aiClient !== null;

// POST: apply
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
    CSRF::verifyOrDie();
    try {
        if (!$aiConfigured) throw new Exception('AI provider not configured');
        $mode = $_POST['mode'] ?? 'page';
        if (!in_array($mode, $VALID_MODES, true)) throw new Exception('Invalid mode');

        $relPath = (string)($_POST['file'] ?? '');
        $fileInfo = $importer->getFileByRelativePath($relPath);
        if ($fileInfo === null) throw new Exception('File not found');

        $selected = json_decode($_POST['blocks'] ?? '[]', true);
        if (!is_array($selected) || empty($selected)) {
            throw new Exception('No blocks selected to apply');
        }
        $tpl = new TemplateImporter($config['root_dir'], $aiClient);
        $wrapped = $tpl->apply($fileInfo['path'], $selected);

        // Detect any blog roles the user marked. The auto-bind pass has
        // been removed — the user will hand-edit the resulting template
        // in admin/collection-templates.php to wire $post fields.
        $boundBlocks = $tpl->getLastBoundBlocks();
        $hasBlogContent = false;
        $hasBlogList = false;
        foreach ($boundBlocks as $bb) {
            if ($bb['role'] === 'blog-post-body') $hasBlogContent = true;
            if ($bb['role'] === 'blog-post-list') $hasBlogList = true;
        }

        // When any blog role is present, route the output to
        // collection-templates/{slug}-{detail|list}.php instead of
        // promoting the source HTML to a regular page. The source
        // file gets backed up but is otherwise left in place.
        if ($hasBlogContent || $hasBlogList) {
            $collectionId = (string)($_POST['collection_id'] ?? 'default');
            if (!preg_match('/^[a-z][a-z0-9_\-]{0,40}$/', $collectionId)) {
                throw new Exception('Invalid collection slug. Use lowercase letters, digits, dash, underscore.');
            }
            $kind = $hasBlogContent ? 'detail' : 'list';
            // collection-templates lives under cms_dir (next to core/),
            // matching where BlogRenderer::getTemplatePath looks.
            $tplDir = $config['cms_dir'] . '/collection-templates';
            if (!is_dir($tplDir)) @mkdir($tplDir, 0775, true);
            $outputPath = $tplDir . '/' . $collectionId . '-' . $kind . '.php';

            // Back up any prior version of this template
            $backupsRoot = $config['cms_dir'] . '/backups/_imports';
            if (!is_dir($backupsRoot)) @mkdir($backupsRoot, 0775, true);
            $backupPath = null;
            if (file_exists($outputPath)) {
                $backupPath = $backupsRoot . '/' . basename($outputPath) . '.' . date('YmdHis');
                @copy($outputPath, $backupPath);
            }
            if (file_put_contents($outputPath, $wrapped) === false) {
                throw new Exception('Failed to write blog template to ' . $outputPath);
            }

            $outputRel = ltrim(str_replace($config['root_dir'], '', $outputPath), '/');
            $qs = 'applied_template=' . urlencode($kind)
                . '&applied=' . urlencode($outputRel)
                . '&from=' . urlencode($relPath)
                . '&slug=' . urlencode($collectionId)
                . ($backupPath ? '&backup=' . urlencode(basename($backupPath)) : '');
            header('Location: /cms/admin/import.php?' . $qs);
            exit;
        }

        $result = $tpl->writeWithBackup($fileInfo['path'], $wrapped);

        $outputRel = ltrim(str_replace($config['root_dir'], '', $result['output_path']), '/');
        $qs = 'applied=' . urlencode($outputRel)
            . '&backup=' . urlencode(basename($result['backup_path']));
        if ($result['promoted']) {
            $qs .= '&from=' . urlencode($relPath);
        }
        header('Location: /cms/admin/import.php?' . $qs);
        exit;
    } catch (Exception $e) {
        $flash = ['type' => 'error', 'message' => $e->getMessage()];
    }
}

if (isset($_GET['applied']) && !isset($_GET['applied_template'])) {
    $applied = htmlspecialchars((string)$_GET['applied']);
    $from = isset($_GET['from']) ? htmlspecialchars((string)$_GET['from']) : '';
    $backup = htmlspecialchars((string)($_GET['backup'] ?? ''));
    $msg = $from !== '' && $from !== $_GET['applied']
        ? 'Imported ' . $from . ' as ' . $applied . ' (promoted to PHP for invisible markers).'
        : 'Imported ' . $applied . '.';
    $msg .= ' Original backed up to cms/backups/_imports/' . $backup;
    $flash = ['type' => 'success', 'message' => $msg];
}

if (isset($_GET['applied_template'])) {
    $kind = htmlspecialchars((string)$_GET['applied_template']);
    $slug = htmlspecialchars((string)($_GET['slug'] ?? 'default'));
    $backup = (string)($_GET['backup'] ?? '');
    $msg = 'Imported as blog-' . $kind . ' template at collection-templates/' . $slug . '-' . $kind . '.php.'
        . ' Open it in <a href="/cms/admin/collection-templates.php" class="underline font-medium">Collection Templates</a> to wire $post fields — see the variable cheatsheet.';
    if ($backup !== '') {
        $msg .= ' Previous template backed up to cms/backups/_imports/' . htmlspecialchars($backup) . '.';
    }
    $flash = ['type' => 'success', 'message' => $msg];
}

// Preview mode
$previewMode = isset($_GET['preview']);
$previewFile = null;
$previewProposals = null;
$previewError = null;
$previewKind = 'page';

if ($previewMode) {
    $fileInfo = $importer->getFileByRelativePath((string)$_GET['preview']);
    if ($fileInfo === null) {
        $previewError = 'File not found in web root';
    } elseif (!$aiConfigured) {
        $previewError = 'AI provider not configured';
    } elseif ($fileInfo['extension'] !== 'html' && $fileInfo['extension'] !== 'htm') {
        $previewError = 'AI import currently supports .html files only. PHP support is planned.';
        $previewFile = $fileInfo;
    } else {
        $previewFile = $fileInfo;
        try {
            $tpl = new TemplateImporter($config['root_dir'], $aiClient);
            $previewProposals = $tpl->propose($fileInfo['path']);
        } catch (Exception $e) {
            $previewError = 'Proposal failed: ' . $e->getMessage();
        }
    }
}

$files = $importer->scan();

// Group files by status
$managed = [];
$ready = [];
$warn = [];
$skipped = [];
foreach ($files as $f) {
    match ($f['status']) {
        'managed' => $managed[] = $f,
        'warn'    => $warn[] = $f,
        'skipped' => $skipped[] = $f,
        default   => $ready[] = $f,
    };
}

$pageTitle = 'Import Pages';
$activePage = 'import';

require __DIR__ . '/includes/header.php';
?>

<div class="max-w-5xl" x-data="importPage()">

    <?php if ($flash): ?>
        <div class="<?php echo $flash['type'] === 'error' ? 'bg-red-50 border-red-500' : 'bg-green-50 border-green-500'; ?> border-l-4 p-4 mb-6">
            <p class="<?php echo $flash['type'] === 'error' ? 'text-red-700' : 'text-green-700'; ?>">
                <?php echo $flash['message']; ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($previewMode): ?>
        <?php include __DIR__ . '/includes/import-preview.php'; ?>
    <?php else: ?>

    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">Import Pages</h1>
            <p class="text-gray-600 dark:text-gray-400 mt-1">Bring existing HTML or PHP templates under CMS management.</p>
        </div>
        <a href="/cms/admin/import.php" class="px-4 py-2 text-sm bg-gray-100 dark:bg-dark-200 text-gray-700 dark:text-gray-300 rounded-md hover:bg-gray-200">Rescan</a>
    </div>

    <?php if (!$aiConfigured): ?>
        <div class="bg-blue-50 dark:bg-blue-900/20 border-l-4 border-blue-500 p-4 mb-6">
            <p class="text-sm text-blue-800 dark:text-blue-200">
                <strong>AI-assisted conversion is disabled.</strong>
                Add an API key in
                <a href="/cms/admin/ai-settings.php" class="underline font-medium">AI Settings</a>
                to have the CMS wrap your templates into blocks automatically. You can always convert manually using the marker syntax — see
                <a href="/cms/admin/docs/blocks.php" class="underline font-medium">Docs → Blocks</a>.
            </p>
        </div>
    <?php endif; ?>

    <!-- Ready to import -->
    <?php if (!empty($ready) || !empty($warn)): ?>
    <div class="bg-white dark:bg-dark-100 rounded-lg shadow-md mb-6">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-200 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                Unmanaged (<?php echo count($ready) + count($warn); ?>)
            </h2>
        </div>

        <div class="divide-y divide-gray-200 dark:divide-dark-200">
            <?php foreach (array_merge($ready, $warn) as $f): ?>
                <?php $key = $f['relative_path']; ?>
                <div class="px-6 py-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <code class="font-mono text-sm font-semibold text-gray-900 dark:text-gray-100"><?php echo htmlspecialchars($f['relative_path']); ?></code>
                                <?php if ($f['status'] === 'warn'): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs bg-yellow-50 text-yellow-800 border border-yellow-200" title="<?php echo htmlspecialchars($f['status_detail']); ?>">
                                        Large
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                <?php echo htmlspecialchars($f['size_human']); ?> · <?php echo (int)$f['lines']; ?> lines
                                <?php if ($f['title'] !== ''): ?>
                                    · <span class="text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($f['title']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            <?php if ($aiConfigured): ?>
                                <a href="/cms/admin/import.php?preview=<?php echo urlencode($f['relative_path']); ?>"
                                   class="px-3 py-1.5 text-sm bg-accent-600 text-white rounded hover:bg-accent-700 inline-flex items-center gap-1">
                                    Convert with AI
                                </a>
                            <?php endif; ?>
                            <button type="button" @click="toggleManual('<?php echo htmlspecialchars(addslashes($key), ENT_QUOTES); ?>')"
                                    class="px-3 py-1.5 text-sm bg-gray-100 dark:bg-dark-200 text-gray-700 dark:text-gray-300 rounded hover:bg-gray-200">
                                Convert manually
                            </button>
                        </div>
                    </div>

                    <!-- Manual conversion help panel -->
                    <div x-show="manualOpen === '<?php echo htmlspecialchars(addslashes($key), ENT_QUOTES); ?>'" x-cloak
                         class="mt-4 p-4 bg-gray-50 dark:bg-dark-300 rounded border border-gray-200 dark:border-dark-200">
                        <p class="text-sm text-gray-700 dark:text-gray-300 mb-3">
                            Wrap each editable region with matching start/end markers.
                            Full reference: <a href="/cms/admin/docs/blocks.php" class="text-accent-600 dark:text-accent-400 underline">Docs → Blocks</a>.
                        </p>

                        <?php if ($f['extension'] === 'php'): ?>
                        <div class="mb-3">
                            <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase mb-1">PHP syntax</div>
<pre class="text-xs bg-gray-900 text-gray-100 p-3 rounded overflow-x-auto"><code>&lt;?php /* CMS:BLOCK name=hero start */ ?&gt;
  &lt;section&gt;...&lt;/section&gt;
&lt;?php /* CMS:BLOCK name=hero end */ ?&gt;</code></pre>
                        </div>
                        <?php else: ?>
                        <div class="mb-3">
                            <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase mb-1">HTML syntax</div>
<pre class="text-xs bg-gray-900 text-gray-100 p-3 rounded overflow-x-auto"><code>&lt;!-- CMS:BLOCK name=hero start --&gt;
  &lt;section&gt;...&lt;/section&gt;
&lt;!-- CMS:BLOCK name=hero end --&gt;</code></pre>
                        </div>
                        <?php endif; ?>

                        <div class="flex items-center gap-3 mt-3">
                            <button type="button" @click="recheck('<?php echo htmlspecialchars(addslashes($key), ENT_QUOTES); ?>')"
                                    class="px-3 py-1.5 text-sm bg-accent-600 text-white rounded hover:bg-accent-700"
                                    :disabled="recheckState['<?php echo htmlspecialchars(addslashes($key), ENT_QUOTES); ?>'] === 'loading'">
                                <span x-show="recheckState['<?php echo htmlspecialchars(addslashes($key), ENT_QUOTES); ?>'] !== 'loading'">Recheck</span>
                                <span x-show="recheckState['<?php echo htmlspecialchars(addslashes($key), ENT_QUOTES); ?>'] === 'loading'">Checking…</span>
                            </button>
                            <span class="text-sm text-gray-500 dark:text-gray-400"
                                  x-text="recheckMessage['<?php echo htmlspecialchars(addslashes($key), ENT_QUOTES); ?>'] || 'Save your changes, then click Recheck.'"></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Already managed -->
    <?php if (!empty($managed)): ?>
    <div class="bg-white dark:bg-dark-100 rounded-lg shadow-md mb-6">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-200">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Already managed (<?php echo count($managed); ?>)</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">These files already contain CMS:BLOCK markers.</p>
        </div>

        <div class="divide-y divide-gray-200 dark:divide-dark-200">
            <?php foreach ($managed as $f): ?>
                <div class="px-6 py-3 flex items-center justify-between">
                    <div>
                        <code class="font-mono text-sm text-gray-900 dark:text-gray-100"><?php echo htmlspecialchars($f['relative_path']); ?></code>
                        <span class="ml-2 text-xs text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($f['status_detail']); ?></span>
                    </div>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs bg-green-50 text-green-700 border border-green-200">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Managed
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Skipped -->
    <?php if (!empty($skipped)): ?>
    <div class="bg-white dark:bg-dark-100 rounded-lg shadow-md mb-6">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-200">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Skipped (<?php echo count($skipped); ?>)</h2>
        </div>
        <div class="divide-y divide-gray-200 dark:divide-dark-200">
            <?php foreach ($skipped as $f): ?>
                <div class="px-6 py-3 flex items-center justify-between text-sm">
                    <code class="font-mono text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($f['relative_path']); ?></code>
                    <span class="text-xs text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($f['status_detail']); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($files)): ?>
        <div class="bg-white dark:bg-dark-100 rounded-lg shadow-md p-8 text-center text-gray-600 dark:text-gray-400">
            No HTML or PHP files found in the web root.
        </div>
    <?php endif; ?>

    <?php endif; // !previewMode ?>
</div>

<script>
function importPage() {
    return {
        manualOpen: null,
        recheckState: {},
        recheckMessage: {},
        toggleManual(key) {
            this.manualOpen = this.manualOpen === key ? null : key;
        },
        async recheck(key) {
            this.recheckState[key] = 'loading';
            try {
                const r = await fetch('/cms/admin/import.php?action=recheck&file=' + encodeURIComponent(key));
                const j = await r.json();
                this.recheckState[key] = j.success ? 'ok' : 'fail';
                if (j.success) {
                    if (j.file.managed) {
                        this.recheckMessage[key] = '✓ ' + j.file.status_detail + ' detected. Reload the page to refresh the list.';
                    } else {
                        this.recheckMessage[key] = 'Still 0 blocks detected. Check your marker syntax.';
                    }
                } else {
                    this.recheckMessage[key] = j.error || 'Failed';
                }
            } catch (e) {
                this.recheckState[key] = 'fail';
                this.recheckMessage[key] = e.message;
            }
        },
    };
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
