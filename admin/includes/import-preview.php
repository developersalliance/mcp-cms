<?php
/**
 * Import preview partial.
 * Expects: $previewFile (array|null), $previewProposals (array|null), $previewError (string|null)
 */
?>

<div class="mb-4">
    <a href="/cms/admin/import.php" class="text-sm text-gray-500 dark:text-gray-400 hover:text-accent-600">← Back to all files</a>
</div>

<?php
$_isHtml = $previewFile && in_array($previewFile['extension'] ?? '', ['html', 'htm'], true);
$_promotedName = $_isHtml ? preg_replace('/\.(html|htm)$/i', '.php', $previewFile['relative_path']) : ($previewFile['relative_path'] ?? '');
?>
<h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100 mb-1">
    Preview: <code class="font-mono text-2xl"><?php echo htmlspecialchars($previewFile['relative_path'] ?? $_GET['preview']); ?></code>
</h1>
<p class="text-gray-600 dark:text-gray-400 mb-2">AI-proposed block boundaries. Review before applying. Your original is backed up to <code>cms/backups/_imports/</code>.</p>

<?php if ($_isHtml): ?>
<div class="bg-blue-50 dark:bg-blue-900/20 border-l-4 border-blue-500 p-3 mb-6 text-sm text-blue-800 dark:text-blue-200">
    <strong>Auto-promote:</strong> this HTML file will be written as
    <code class="font-mono"><?php echo htmlspecialchars($_promotedName); ?></code>
    and the original <code class="font-mono"><?php echo htmlspecialchars($previewFile['relative_path']); ?></code> will be deleted (backup kept).
    PHP extension lets the CMS markers stay invisible to browsers.
</div>
<?php endif; ?>

<?php if ($previewError): ?>
    <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
        <p class="text-red-700"><strong>Error:</strong> <?php echo htmlspecialchars($previewError); ?></p>
    </div>
    <p><a href="/cms/admin/import.php" class="text-accent-600 underline">Return to file list</a></p>
<?php elseif ($previewProposals === null): ?>
    <div class="bg-yellow-50 border-l-4 border-yellow-500 p-4 mb-6">
        <p class="text-yellow-800">No proposals available.</p>
    </div>
<?php else: ?>

    <?php if (empty($previewProposals['proposals'])): ?>
        <div class="bg-yellow-50 border-l-4 border-yellow-500 p-4 mb-6">
            <p class="text-yellow-800">The AI did not identify any content blocks in this file. You may want to convert it manually.</p>
        </div>
    <?php else: ?>

    <div class="bg-white dark:bg-dark-100 rounded-lg shadow-md p-6 mb-6"
         x-data="{
            proposals: <?php echo htmlspecialchars(json_encode(array_values(array_map(fn($p) => array_merge($p, ['include' => true, 'disposition' => 'page']), $previewProposals['proposals']))), ENT_QUOTES, 'UTF-8'); ?>,
            renameIdx: null,
            applyState: 'idle',
            get selected() { return this.proposals.filter(p => p.include); },
            selectAll() { this.proposals.forEach(p => p.include = true); },
            clearAll() { this.proposals.forEach(p => p.include = false); },
            validateName(name) { return /^[a-z][a-z0-9_\-]{0,40}$/.test(name); },
            submitApply(e) {
                const selected = this.selected.map(p => ({ node_id: p.node_id, name: p.name, disposition: p.disposition, custom: p.disposition !== 'global' }));
                if (selected.length === 0) { e.preventDefault(); alert('Select at least one block.'); return; }
                const names = selected.map(p => p.name);
                if (new Set(names).size !== names.length) { e.preventDefault(); alert('Duplicate block names. Rename before applying.'); return; }
                for (const p of selected) {
                    if (!this.validateName(p.name)) { e.preventDefault(); alert('Invalid name: ' + p.name); return; }
                }
                const bodies = selected.filter(p => p.disposition === 'blog-content');
                const lists = selected.filter(p => p.disposition === 'blog-list');
                if (bodies.length > 1) { e.preventDefault(); alert('Only one block can be marked as Blog content per file.'); return; }
                if (lists.length > 1) { e.preventDefault(); alert('Only one block can be marked as Blog list per file.'); return; }
                document.getElementById('blocks-json').value = JSON.stringify(selected);
                this.applyState = 'submitting';
            },
         }">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    Proposed blocks <span class="text-gray-500 text-sm font-normal" x-text="'(' + selected.length + ' of ' + proposals.length + ' selected)'"></span>
                </h2>
            </div>
            <div class="flex gap-2 text-sm">
                <button type="button" @click="selectAll()" class="px-3 py-1 bg-gray-100 dark:bg-dark-200 text-gray-700 dark:text-gray-300 rounded">Select all</button>
                <button type="button" @click="clearAll()" class="px-3 py-1 bg-gray-100 dark:bg-dark-200 text-gray-700 dark:text-gray-300 rounded">Clear</button>
            </div>
        </div>

        <div class="divide-y divide-gray-200 dark:divide-dark-200">
            <template x-for="(p, idx) in proposals" :key="p.node_id">
                <div class="py-3 flex items-start gap-3">
                    <input type="checkbox" x-model="p.include" class="mt-1 rounded">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <template x-if="renameIdx !== idx">
                                <div class="flex items-center gap-2">
                                    <code class="font-mono text-base font-semibold text-gray-900 dark:text-gray-100" x-text="p.name"></code>
                                    <button type="button" @click="renameIdx = idx" class="text-xs text-accent-600 hover:underline">rename</button>
                                </div>
                            </template>
                            <template x-if="renameIdx === idx">
                                <div class="flex items-center gap-2">
                                    <input type="text" x-model="p.name" @keydown.enter="renameIdx = null" @keydown.escape="renameIdx = null"
                                           class="font-mono text-sm px-2 py-1 border border-gray-300 dark:border-dark-200 dark:bg-dark-300 dark:text-gray-100 rounded"
                                           @blur="renameIdx = null" x-ref="renameInput" x-init="$nextTick(() => $refs.renameInput && $refs.renameInput.focus())">
                                </div>
                            </template>
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                &lt;<span x-text="p.tag"></span><template x-if="p.id_attr"> id=<span x-text="p.id_attr"></span></template><template x-if="p.classes.length">.<span x-text="p.classes.join('.')"></span></template>&gt;
                            </span>
                            <span class="ml-auto text-xs text-gray-400">
                                <span x-text="p.text_chars"></span> chars, <span x-text="p.children"></span> children
                            </span>
                        </div>
                        <div class="text-sm text-gray-600 dark:text-gray-400 mt-0.5" x-text="p.reason"></div>
                        <label class="mt-1 inline-flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                            <span>Role:</span>
                            <select x-model="p.disposition"
                                    class="text-xs px-2 py-0.5 border border-gray-300 dark:border-dark-200 dark:bg-dark-300 dark:text-gray-100 rounded">
                                <option value="page">Page block (custom)</option>
                                <option value="global">Global block (syncs across pages)</option>
                                <option value="blog-content">Blog content (post body — bound to $post)</option>
                                <option value="blog-list">Blog list (repeating cards — bound to $posts)</option>
                            </select>
                            <template x-if="p.disposition === 'blog-content' || p.disposition === 'blog-list'">
                                <span class="text-accent-700 dark:text-accent-300 font-medium">Output goes to collection-templates/{slug}-{detail|list}.php — open in Collection Templates to wire fields.</span>
                            </template>
                        </label>
                    </div>
                </div>
            </template>
        </div>

        <form method="post" action="/cms/admin/import.php" @submit="submitApply($event)" class="mt-6 pt-4 border-t border-gray-200 dark:border-dark-200">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRF::getToken() ?? CSRF::generateToken()); ?>">
            <input type="hidden" name="action" value="apply">
            <input type="hidden" name="file" value="<?php echo htmlspecialchars($previewFile['relative_path']); ?>">
            <input type="hidden" name="blocks" id="blocks-json" value="">
            <template x-if="proposals.some(p => p.include && (p.disposition === 'blog-content' || p.disposition === 'blog-list'))">
                <div class="mb-4 p-4 rounded-lg bg-accent-50 dark:bg-accent-900/20 border border-accent-200 dark:border-accent-800/50">
                    <label class="block text-sm font-medium text-gray-800 dark:text-gray-100 mb-2">Collection slug for this blog template</label>
                    <input type="text" name="collection_id" value="default" pattern="[a-z][a-z0-9_-]{0,40}"
                           class="w-full px-3 py-2 text-sm font-mono border border-gray-300 dark:border-dark-200 dark:bg-dark-300 dark:text-gray-100 rounded">
                    <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">Written to <code class="font-mono">collection-templates/{slug}-{detail|list}.php</code>. Use the same slug across detail + list pages.</p>
                </div>
            </template>
            <div class="flex items-center justify-between">
                <p class="text-sm text-gray-500 dark:text-gray-400">Your original file will be backed up before writing.</p>
                <button type="submit" :disabled="applyState === 'submitting'"
                        class="px-6 py-2 bg-accent-600 text-white font-semibold rounded-md hover:bg-accent-700 disabled:opacity-50 transition">
                    <span x-show="applyState !== 'submitting'">Apply to file</span>
                    <span x-show="applyState === 'submitting'">Applying…</span>
                </button>
            </div>
        </form>
    </div>

    <div class="bg-blue-50 dark:bg-blue-900/20 border-l-4 border-blue-500 p-4 text-sm text-blue-800 dark:text-blue-200">
        <strong>Note:</strong> Applying this will reparse your HTML through PHP's DOMDocument and re-serialize it.
        Whitespace and attribute quoting may be normalized. Review the backup if you need the original byte-for-byte.
    </div>

    <?php endif; ?>
<?php endif; ?>
