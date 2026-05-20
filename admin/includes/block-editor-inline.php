<?php
/**
 * Inline Block Editor — single editable area inside a parent form.
 *
 * Used by blog-edit.php for the post body. Unlike block-editor-block.php
 * (which AJAX-saves each CMS:BLOCK independently), this variant:
 *  - Renders a single Code/Preview UI with the formatting toolbar
 *  - Writes the current content into a hidden textarea on every change
 *  - Lets the surrounding form submit handle the save
 *
 * Required PHP vars:
 *  - $inlineFieldName    (string) — name attribute for the hidden textarea
 *  - $inlineFieldContent (string) — initial HTML content
 *  - $inlineFieldHeight  (int, optional) — pixel height for Code editor + iframe
 */
$fieldName    = $inlineFieldName    ?? 'content';
$fieldContent = $inlineFieldContent ?? '';
$fieldHeight  = (int)($inlineFieldHeight ?? 480);
?>
<div x-data="inlineBlockEditor()" x-init="init()">
    <!-- View Toggle -->
    <div class="flex justify-end gap-2 mb-3">
        <button type="button" @click="switchToCode()" :class="view === 'code' ? 'bg-accent-600 text-white' : 'bg-surface-100 dark:bg-dark-300 text-gray-700 dark:text-gray-300'" class="px-4 py-2 rounded-lg text-sm font-medium transition">Code</button>
        <button type="button" @click="switchToPreview()" :class="view === 'preview' ? 'bg-accent-600 text-white' : 'bg-surface-100 dark:bg-dark-300 text-gray-700 dark:text-gray-300'" class="px-4 py-2 rounded-lg text-sm font-medium transition">Preview</button>
    </div>

    <!-- Code Editor (Ace) -->
    <div x-show="view === 'code'">
        <div class="ace-editor-wrapper">
            <div x-ref="editor" class="w-full" style="height: <?php echo $fieldHeight; ?>px;"></div>
        </div>
    </div>

    <!-- Preview (live WYSIWYG) -->
    <div x-show="view === 'preview'">
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">Click on text to edit directly. Select text and use the toolbar to format. Changes sync back to the Code view automatically.</p>

        <!-- Formatting toolbar -->
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
            <button type="button" @click="insertLink()" title="Insert link" class="px-2.5 py-1 rounded hover:bg-surface-200 dark:hover:bg-dark-200 text-sm">🔗</button>
            <button type="button" @click="fmt('unlink')" title="Remove link" class="px-2.5 py-1 rounded hover:bg-surface-200 dark:hover:bg-dark-200 text-sm">🚫🔗</button>
            <div class="w-px h-5 bg-surface-300 dark:bg-dark-200 mx-1"></div>
            <button type="button" @click="fmt('removeFormat')" title="Clear formatting" class="px-2.5 py-1 rounded hover:bg-surface-200 dark:hover:bg-dark-200 text-sm">⌫</button>
        </div>

        <div class="border-2 border-surface-200 dark:border-dark-200 rounded-b-xl overflow-hidden bg-white" style="height: <?php echo $fieldHeight; ?>px;">
            <iframe x-ref="preview" class="w-full h-full border-0"></iframe>
        </div>
    </div>

    <!-- Hidden textarea — the form actually submits this -->
    <textarea x-ref="textarea" name="<?php echo htmlspecialchars($fieldName); ?>" class="hidden"><?php echo htmlspecialchars($fieldContent); ?></textarea>
</div>
