<?php
/**
 * Block Editor Scripts
 * Shared JavaScript for block editing (Pages & Blog posts)
 *
 * Required PHP variables before including:
 * - $blockEditorPageHead (string) - <head> HTML from the source page (styles, fonts, CDN scripts)
 * - $blockEditorBaseUrl  (string) - Base URL for resolving relative href/src in the iframe
 * - $blockEditorCsrfToken (string)- CSRF token
 * - $blockEditorSaveUrl   (string)- URL for AJAX save (current page URL)
 * - $blockEditorCssFiles  (array) - DEPRECATED: legacy list of CSS URLs (fallback only)
 */
?>
<script>
// Source-page <head> (inline <style>, link, fonts, Tailwind CDN, etc.)
const pageHeadHtml = <?php echo json_encode($blockEditorPageHead ?? ''); ?>;

// Base URL for resolving relative href/src in the preview iframe
const pageBaseUrl = <?php echo json_encode($blockEditorBaseUrl ?? ''); ?>;

// Back-compat fallback: list of stylesheet URLs used if pageHeadHtml is empty
const pageCssFiles = <?php echo json_encode($blockEditorCssFiles ?? []); ?>;

// CSRF token for AJAX requests
const csrfToken = '<?php echo $blockEditorCsrfToken ?? ''; ?>';

// Save URL
const saveUrl = '<?php echo $blockEditorSaveUrl ?? ''; ?>';

// Block editor controller for Alpine.js
function blockEditor(index, blockName, isSystem = false) {
    return {
        aceEditor: null,
        blockName: blockName,
        isSystem: isSystem,
        view: isSystem ? 'code' : 'preview',
        collapsed: isSystem,
        saving: false,

        init() {
            // Watch for collapse changes to init Ace when expanded
            this.$watch('collapsed', (value) => {
                if (!value && !this.aceEditor) {
                    this.$nextTick(() => {
                        setTimeout(() => {
                            this.initAce();
                            if (!this.isSystem && this.view === 'preview') {
                                this.renderPreview();
                            }
                        }, 50);
                    });
                }
            });

            // Initialize immediately if not collapsed
            if (!this.collapsed) {
                this.$nextTick(() => {
                    this.initAce();
                    if (!this.isSystem && this.view === 'preview') {
                        setTimeout(() => this.renderPreview(), 100);
                    }
                });
            }
        },

        initAce() {
            const editorEl = this.$refs.editor;
            if (!editorEl || this.aceEditor) return;

            const isDark = document.documentElement.classList.contains('dark');
            this.aceEditor = ace.edit(editorEl);
            this.aceEditor.setTheme(isDark ? 'ace/theme/monokai' : 'ace/theme/chrome');
            this.aceEditor.session.setMode('ace/mode/php');
            this.aceEditor.setOptions({
                showPrintMargin: false,
                wrap: true,
                tabSize: 4,
                useSoftTabs: true
            });

            // Load initial content from hidden textarea
            if (this.$refs.textarea && this.$refs.textarea.value) {
                this.aceEditor.setValue(this.$refs.textarea.value, -1);
            }

            // Sync to hidden textarea on change
            this.aceEditor.session.on('change', () => {
                if (this.$refs.textarea) {
                    this.$refs.textarea.value = this.aceEditor.getValue();
                }
            });
        },

        switchToCode() {
            this.view = 'code';
            this.$nextTick(() => {
                if (this.aceEditor) {
                    this.aceEditor.resize();
                }
            });
        },

        switchToPreview() {
            this.view = 'preview';
            this.$nextTick(() => {
                this.renderPreview();
            });
        },

        renderPreview() {
            const content = this.aceEditor ? this.aceEditor.getValue() : (this.$refs.textarea ? this.$refs.textarea.value : '');

            const iframe = this.$refs.preview;
            if (!iframe) return;

            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;

            // Page styling: prefer the source page's full <head> (catches inline
            // <style>, Tailwind CDN script, fonts, custom CSS). Fall back to the
            // older link[rel=stylesheet]-only list if pageHeadHtml is empty.
            const headHtml = (typeof pageHeadHtml === 'string' && pageHeadHtml.length > 0)
                ? pageHeadHtml
                : pageCssFiles.map(url => `<link rel="stylesheet" href="${url}">`).join('\n');

            // <base> so relative href/src in <head> resolve against the live site
            const baseTag = (typeof pageBaseUrl === 'string' && pageBaseUrl) ? `<base href="${pageBaseUrl}">` : '';

            // WYSIWYG styles for editable elements + neutralize scroll-trigger
            // animations. Live sites typically set elements to opacity:0 and
            // rely on an IntersectionObserver (in <body>) to add a visible
            // class. We don't run body scripts in the iframe, so those
            // elements would stay invisible. Force them visible.
            const wysiwygStyles = `
                <style>
                    .fade, .reveal, .animate-on-scroll, .scroll-fade, .aos-init,
                    [data-aos], [data-reveal], [data-animate], [data-scroll],
                    [class*="fade-in"], [class*="scroll-fade"] {
                        opacity: 1 !important;
                        transform: none !important;
                        visibility: visible !important;
                        filter: none !important;
                    }
                    [data-editable]:hover {
                        outline: 2px dashed #3b82f6 !important;
                        outline-offset: 2px !important;
                        cursor: text !important;
                    }
                    [data-editable]:focus {
                        outline: 2px solid #3b82f6 !important;
                        outline-offset: 2px !important;
                        background: rgba(59, 130, 246, 0.05) !important;
                    }
                </style>
            `;

            iframeDoc.open();
            // Render ONLY this block's content (no other blocks or surrounding
            // page markup) with the page's CSS applied. Each per-block preview
            // is just "this block, styled like the live site".
            iframeDoc.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    ${baseTag}
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    ${headHtml}
                    ${wysiwygStyles}
                </head>
                <body>
                    <div id="editable-content">${content}</div>
                </body>
                </html>
            `);
            iframeDoc.close();

            // Make text elements editable after content loads
            setTimeout(() => this.setupWysiwyg(iframeDoc), 100);
        },

        setupWysiwyg(iframeDoc) {
            const editableContent = iframeDoc.getElementById('editable-content');
            if (!editableContent) return;

            // Text elements that should be editable
            const textSelectors = 'h1, h2, h3, h4, h5, h6, p, span, a, li, td, th, label, button, figcaption';
            const textElements = editableContent.querySelectorAll(textSelectors);

            textElements.forEach(el => {
                // Skip if element contains only other elements (no direct text)
                const hasDirectText = Array.from(el.childNodes).some(
                    node => node.nodeType === Node.TEXT_NODE && node.textContent.trim()
                );
                if (!hasDirectText && el.children.length > 0) return;

                // Skip PHP code markers
                if (el.textContent.includes('<?') || el.textContent.includes('?>')) return;

                el.setAttribute('contenteditable', 'true');
                el.setAttribute('data-editable', 'true');
                el.setAttribute('data-original', el.textContent);

                // Handle text changes on blur
                el.addEventListener('blur', () => {
                    const original = el.getAttribute('data-original');
                    const newText = el.textContent;

                    if (original !== newText && original && newText) {
                        this.updateSourceText(original, newText);
                        el.setAttribute('data-original', newText);
                    }
                });

                // Prevent Enter from creating new elements
                el.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        el.blur();
                    }
                });
            });
        },

        updateSourceText(originalText, newText) {
            if (!this.aceEditor) return;

            const source = this.aceEditor.getValue();
            const trimmed = (originalText || '').trim();
            if (trimmed === '') return;

            // Safety against the duplicate-text data-loss bug the audit
            // flagged: if the original text appears more than once in the
            // block source, split().join() would rewrite EVERY occurrence
            // — not just the one the user edited. Refuse the inline edit
            // and ask them to use Code view, which is unambiguous.
            const occurrences = source.split(originalText).length - 1;
            if (occurrences === 0) {
                showToast('Could not match original text — switch to Code view to edit.', 'error');
                return;
            }
            if (occurrences > 1) {
                showToast('Text appears ' + occurrences + 'x in the block — please edit in Code view to choose which one.', 'error');
                return;
            }

            const updated = source.split(originalText).join(newText);
            this.aceEditor.setValue(updated, -1);
            if (this.$refs.textarea) {
                this.$refs.textarea.value = updated;
            }
        },


        async saveBlock() {
            if (this.saving) return;

            this.saving = true;

            // Get content from Ace editor
            const content = this.aceEditor ? this.aceEditor.getValue() : (this.$refs.textarea ? this.$refs.textarea.value : '');
            const isCustom = this.$refs.customCheckbox ? this.$refs.customCheckbox.checked : false;

            const formData = new FormData();
            formData.append('action', 'update_block');
            formData.append('csrf_token', csrfToken);
            formData.append('block_name', this.blockName);
            formData.append('block_content', content);
            if (isCustom) {
                formData.append('block_custom', '1');
            }

            try {
                const response = await fetch(saveUrl || window.location.href, {
                    method: 'POST',
                    body: formData
                });

                if (response.ok) {
                    showToast('Block saved as draft', 'success');

                    // Update "Has Draft" badge if not already shown
                    const badge = document.querySelector('[data-draft-badge]');
                    if (!badge) {
                        // Add badge dynamically instead of reloading
                        const title = document.querySelector('h1');
                        if (title && !title.querySelector('[data-draft-badge]')) {
                            const badgeEl = document.createElement('span');
                            badgeEl.setAttribute('data-draft-badge', '');
                            badgeEl.className = 'ml-3 px-2.5 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400';
                            badgeEl.textContent = 'Has Draft';
                            title.appendChild(badgeEl);
                        }
                    }
                } else {
                    throw new Error('Save failed');
                }
            } catch (error) {
                showToast('Error saving block', 'error');
            } finally {
                this.saving = false;
            }
        },

    };
}

function showToast(message, type = 'success') {
    const toast = document.getElementById('toast');
    const content = document.getElementById('toast-content');
    const icon = document.getElementById('toast-icon');
    const msg = document.getElementById('toast-message');

    if (!toast || !content || !icon || !msg) return;

    msg.textContent = message;

    if (type === 'success') {
        content.className = 'flex items-center gap-3 px-5 py-4 rounded-xl shadow-lg border bg-emerald-50 dark:bg-emerald-900/30 border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300';
        icon.innerHTML = '<svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';
    } else {
        content.className = 'flex items-center gap-3 px-5 py-4 rounded-xl shadow-lg border bg-red-50 dark:bg-red-900/30 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300';
        icon.innerHTML = '<svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>';
    }

    // Show
    toast.classList.remove('translate-y-20', 'opacity-0', 'pointer-events-none');
    toast.classList.add('translate-y-0', 'opacity-100');

    // Hide after 3 seconds
    setTimeout(() => {
        toast.classList.add('translate-y-20', 'opacity-0', 'pointer-events-none');
        toast.classList.remove('translate-y-0', 'opacity-100');
    }, 3000);
}
</script>

<!-- Toast Notification -->
<div id="toast" class="fixed bottom-6 right-6 z-50 transition-all duration-300 transform translate-y-20 opacity-0 pointer-events-none">
    <div id="toast-content" class="flex items-center gap-3 px-5 py-4 rounded-xl shadow-lg border">
        <div id="toast-icon"></div>
        <span id="toast-message" class="font-medium"></span>
    </div>
</div>

