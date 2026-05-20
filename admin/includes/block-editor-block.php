<?php
/**
 * Block Editor Block Component
 * Renders a single collapsible block editor
 *
 * Required PHP variables:
 * - $block (array) - Block data with: name, role, custom, system, content
 * - $blockIndex (int) - Index for Alpine.js component
 */

$isSystem = $block['system'] ?? false;
$blockRole = (string)($block['role'] ?? '');
// Data-bound blogs blocks share the "code-only" behavior of system blocks
// because their content contains <?php tags that would break the WYSIWYG
// iframe and the contenteditable scan in setupWysiwyg().
$isBound = in_array($blockRole, ['blog-post-body', 'blog-post-list', 'blog-post-list-loop'], true);
$isCodeOnly = $isSystem || $isBound;
?>
<div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 mb-6" x-data="blockEditor(<?php echo $blockIndex; ?>, '<?php echo htmlspecialchars($block['name'], ENT_QUOTES); ?>', <?php echo $isCodeOnly ? 'true' : 'false'; ?>)">
    <!-- Block Header (clickable to expand/collapse) -->
    <div @click="collapsed = !collapsed" class="flex items-center justify-between p-6 cursor-pointer hover:bg-surface-50 dark:hover:bg-dark-300 rounded-t-2xl transition" :class="{ 'rounded-b-2xl': collapsed }">
        <div class="flex items-center gap-3">
            <svg class="w-5 h-5 text-gray-400 transition-transform" :class="{ '-rotate-90': collapsed }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Block: <code class="text-accent-600"><?php echo htmlspecialchars($block['name']); ?></code></h2>
        </div>
        <div class="flex flex-wrap gap-2">
        <?php if ($block['role']): ?>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400">
                Role: <?php echo htmlspecialchars($block['role']); ?>
            </span>
        <?php endif; ?>
        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold <?php echo $block['custom'] ? 'bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-400' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300'; ?>">
            <?php echo $block['custom'] ? 'Custom' : 'Global'; ?>
        </span>
        <?php if ($isSystem): ?>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400">
                System
            </span>
        <?php endif; ?>
        <?php if ($isBound): ?>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-accent-100 dark:bg-accent-900/30 text-accent-700 dark:text-accent-300">
                Data-bound
            </span>
        <?php endif; ?>
        </div>
    </div>

    <!-- Block Content (collapsible) -->
    <div x-show="!collapsed" x-transition class="px-6 py-6 border-t border-surface-200 dark:border-dark-200">
    <?php if ($isBound): ?>
    <div class="mb-4 px-4 py-3 rounded-lg bg-accent-50 dark:bg-accent-900/20 border-l-4 border-accent-500 text-sm text-accent-800 dark:text-accent-200">
        <strong>Data-bound block.</strong> This block renders against <code class="font-mono">$post</code><?php echo $blockRole === 'blog-post-list' ? '/<code class="font-mono">$posts</code>' : ''; ?> at render time, so edits go to PHP directly. WYSIWYG is disabled to avoid corrupting the echoes. To rebind fields, run the import flow again.
    </div>
    <?php endif; ?>
    <form x-ref="form" @submit.prevent="saveBlock()">
        <input type="hidden" name="block_name" value="<?php echo htmlspecialchars($block['name']); ?>">

        <label class="flex items-center mb-4 cursor-pointer">
            <input type="checkbox" x-ref="customCheckbox" <?php echo $block['custom'] ? 'checked' : ''; ?> <?php echo $isBound ? 'disabled' : ''; ?> class="mr-2 h-4 w-4 text-accent-600 rounded border-gray-300 dark:border-gray-600 focus:ring-accent-500">
            <span class="text-sm text-gray-700 dark:text-gray-300">Mark as custom (per-page override)<?php echo $isBound ? ' — locked on for bound blocks' : ''; ?></span>
        </label>

        <?php if (!$isCodeOnly): ?>
        <!-- View Toggle Buttons -->
        <div class="flex justify-end gap-2 mb-4">
            <button type="button" @click="switchToCode()" :class="view === 'code' ? 'bg-accent-600 text-white' : 'bg-surface-100 dark:bg-dark-300 text-gray-700 dark:text-gray-300'" class="px-4 py-2 rounded-lg text-sm font-medium transition">Code</button>
            <button type="button" @click="switchToPreview()" :class="view === 'preview' ? 'bg-accent-600 text-white' : 'bg-surface-100 dark:bg-dark-300 text-gray-700 dark:text-gray-300'" class="px-4 py-2 rounded-lg text-sm font-medium transition">Preview</button>
        </div>

        <!-- Code Editor -->
        <div x-show="view === 'code'" class="mb-4">
            <div class="ace-editor-wrapper">
                <div x-ref="editor" class="w-full" style="height: 400px;"></div>
            </div>
            <textarea x-ref="textarea" name="block_content" class="hidden"><?php echo htmlspecialchars($block['content']); ?></textarea>
        </div>

        <!-- Preview (WYSIWYG) -->
        <div x-show="view === 'preview'" class="mb-4">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">Click on text to edit directly. Select text and use the toolbar to format. Changes sync back to code.</p>

            <!-- Inline formatting toolbar (operates on iframe selection via execCommand) -->
            <div class="flex flex-wrap items-center gap-1 px-2 py-1.5 border-2 border-b-0 border-surface-200 dark:border-dark-200 rounded-t-xl bg-surface-50 dark:bg-dark-300">
                <button type="button" @click="fmt('bold')" title="Bold (Ctrl+B)" class="px-2.5 py-1 rounded hover:bg-surface-200 dark:hover:bg-dark-200 font-bold text-sm">B</button>
                <button type="button" @click="fmt('italic')" title="Italic (Ctrl+I)" class="px-2.5 py-1 rounded hover:bg-surface-200 dark:hover:bg-dark-200 italic text-sm">I</button>
                <button type="button" @click="fmt('underline')" title="Underline (Ctrl+U)" class="px-2.5 py-1 rounded hover:bg-surface-200 dark:hover:bg-dark-200 underline text-sm">U</button>
                <div class="w-px h-5 bg-surface-300 dark:bg-dark-200 mx-1"></div>
                <button type="button" @click="fmt('formatBlock', 'h2')" title="Heading 2" class="px-2.5 py-1 rounded hover:bg-surface-200 dark:hover:bg-dark-200 text-sm font-semibold">H2</button>
                <button type="button" @click="fmt('formatBlock', 'h3')" title="Heading 3" class="px-2.5 py-1 rounded hover:bg-surface-200 dark:hover:bg-dark-200 text-sm font-semibold">H3</button>
                <button type="button" @click="fmt('formatBlock', 'p')" title="Paragraph" class="px-2.5 py-1 rounded hover:bg-surface-200 dark:hover:bg-dark-200 text-sm">¶</button>
                <div class="w-px h-5 bg-surface-300 dark:bg-dark-200 mx-1"></div>
                <button type="button" @click="fmt('insertUnorderedList')" title="Bulleted list" class="px-2.5 py-1 rounded hover:bg-surface-200 dark:hover:bg-dark-200 text-sm">• List</button>
                <button type="button" @click="fmt('insertOrderedList')" title="Numbered list" class="px-2.5 py-1 rounded hover:bg-surface-200 dark:hover:bg-dark-200 text-sm">1. List</button>
                <button type="button" @click="fmt('formatBlock', 'blockquote')" title="Quote" class="px-2.5 py-1 rounded hover:bg-surface-200 dark:hover:bg-dark-200 text-sm">"</button>
                <div class="w-px h-5 bg-surface-300 dark:bg-dark-200 mx-1"></div>
                <button type="button" @click="insertLink()" title="Insert link (Ctrl+K)" class="px-2.5 py-1 rounded hover:bg-surface-200 dark:hover:bg-dark-200 text-sm">🔗</button>
                <button type="button" @click="fmt('unlink')" title="Remove link" class="px-2.5 py-1 rounded hover:bg-surface-200 dark:hover:bg-dark-200 text-sm">🚫🔗</button>
                <div class="w-px h-5 bg-surface-300 dark:bg-dark-200 mx-1"></div>
                <button type="button" @click="fmt('removeFormat')" title="Clear formatting" class="px-2.5 py-1 rounded hover:bg-surface-200 dark:hover:bg-dark-200 text-sm">⌫</button>
            </div>

            <div class="border-2 border-surface-200 dark:border-dark-200 rounded-b-xl overflow-hidden bg-white" style="height: 400px;">
                <iframe x-ref="preview" class="w-full h-full border-0"></iframe>
            </div>
        </div>
        <?php else: ?>
        <!-- Code only for system / data-bound blocks -->
        <div class="mb-4">
            <div class="ace-editor-wrapper">
                <div x-ref="editor" class="w-full" style="height: 400px;"></div>
            </div>
            <textarea x-ref="textarea" name="block_content" class="hidden"><?php echo htmlspecialchars($block['content']); ?></textarea>
        </div>
        <?php endif; ?>

        <div class="flex gap-3">
            <button type="submit" :disabled="saving" class="btn-primary px-5 py-2.5 text-white rounded-xl font-medium shadow-lg shadow-accent-500/25 disabled:opacity-50 disabled:cursor-not-allowed">
                <span x-show="!saving">Save Block</span>
                <span x-show="saving" class="flex items-center gap-2">
                    <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Saving...
                </span>
            </button>
        </div>
    </form>
    </div>
</div>
