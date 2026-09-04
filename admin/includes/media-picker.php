<?php
/**
 * Media Picker — WordPress-style "Select or Upload Media" modal.
 *
 * Include once per admin page (block-editor-assets.php does this for both
 * editors). Exposes a global `MediaPicker` object:
 *
 *   MediaPicker.open({ title, onSelect(item) })   — open the modal
 *   MediaPicker.upload(file) -> Promise<item>      — upload without UI
 *
 * An `item` is { url, thumb, width, height, name }. Uploads go through
 * admin/media.php (action=upload, CSRF-protected, media.manage capability)
 * so UploadManager applies the configured auto-resize + thumbnail rules.
 */
if (!class_exists('CSRF')) {
    require_once __DIR__ . '/../../core/CSRF.php';
}
$mediaPickerCanUpload = function_exists('user_can') ? user_can('media.manage') : true;
?>
<div id="media-picker" class="fixed inset-0 z-[100] hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-black/60" data-mp-close></div>
    <div class="absolute inset-4 md:inset-10 bg-white dark:bg-dark-400 rounded-2xl shadow-2xl flex flex-col overflow-hidden"
         role="dialog" aria-modal="true" aria-labelledby="media-picker-title">
        <!-- Header -->
        <div class="flex items-center justify-between px-5 py-3 border-b border-surface-200 dark:border-dark-200">
            <h2 id="media-picker-title" class="text-lg font-semibold text-gray-900 dark:text-white">Select or upload media</h2>
            <button type="button" data-mp-close class="p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-dark-300 text-gray-500" aria-label="Close">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <!-- Tabs -->
        <div class="flex items-center gap-2 px-5 pt-3">
            <button type="button" data-mp-tab="library" class="mp-tab px-4 py-2 rounded-lg text-sm font-medium bg-accent-600 text-white">Media Library</button>
            <?php if ($mediaPickerCanUpload): ?>
            <button type="button" data-mp-tab="upload" class="mp-tab px-4 py-2 rounded-lg text-sm font-medium bg-surface-100 dark:bg-dark-300 text-gray-700 dark:text-gray-300">Upload files</button>
            <?php endif; ?>
            <div class="flex-1"></div>
            <input type="search" data-mp-search placeholder="Search media…"
                   class="w-56 px-3 py-2 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-lg text-sm text-gray-900 dark:text-white focus:border-accent-500">
        </div>
        <!-- Library -->
        <div data-mp-panel="library" class="flex-1 overflow-auto p-5">
            <p data-mp-empty class="hidden text-sm text-gray-500 dark:text-gray-400">No images yet. Upload one to get started.</p>
            <p data-mp-loading class="text-sm text-gray-500 dark:text-gray-400">Loading library…</p>
            <div data-mp-grid class="grid gap-3" style="grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));"></div>
        </div>
        <!-- Upload -->
        <?php if ($mediaPickerCanUpload): ?>
        <div data-mp-panel="upload" class="hidden flex-1 overflow-auto p-5">
            <label data-mp-dropzone
                   class="flex flex-col items-center justify-center h-full min-h-[240px] border-2 border-dashed border-surface-300 dark:border-dark-200 rounded-2xl cursor-pointer hover:border-accent-500 transition-colors text-center px-6">
                <svg class="w-10 h-10 text-gray-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                <span class="text-base font-medium text-gray-800 dark:text-gray-200">Drop images here to upload</span>
                <span class="text-sm text-gray-500 dark:text-gray-400 mt-1">or click to select files. JPG, PNG, GIF, WebP up to 10 MB. Images are resized automatically.</span>
                <input type="file" data-mp-file accept="image/jpeg,image/png,image/gif,image/webp" multiple class="hidden">
            </label>
            <div data-mp-progress class="hidden mt-4 text-sm text-gray-600 dark:text-gray-300"></div>
        </div>
        <?php endif; ?>
        <!-- Footer -->
        <div class="flex items-center justify-between gap-3 px-5 py-3 border-t border-surface-200 dark:border-dark-200">
            <div class="flex-1 min-w-0">
                <div data-mp-selection class="hidden">
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Alt text</label>
                    <input type="text" data-mp-alt placeholder="Describe the image"
                           class="w-full max-w-md px-3 py-1.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-lg text-sm text-gray-900 dark:text-white focus:border-accent-500">
                </div>
            </div>
            <button type="button" data-mp-close class="px-4 py-2 rounded-lg text-sm font-medium bg-surface-100 dark:bg-dark-300 text-gray-700 dark:text-gray-300">Cancel</button>
            <button type="button" data-mp-confirm disabled class="px-5 py-2 rounded-lg text-sm font-semibold bg-accent-600 text-white disabled:opacity-40">Select</button>
        </div>
    </div>
</div>
<script>
window.MediaPicker = (function () {
    const CSRF = <?php echo json_encode(CSRF::getToken()); ?>;
    const CAN_UPLOAD = <?php echo $mediaPickerCanUpload ? 'true' : 'false'; ?>;
    const ENDPOINT = '/cms/admin/media.php';
    const root = document.getElementById('media-picker');
    const $ = (sel) => root.querySelector(sel);
    const grid = $('[data-mp-grid]');
    let items = [];
    let selected = null;
    let onSelect = null;
    let loaded = false;

    function normalize(data) {
        // Accept both UploadManager.uploadImage() and media.php?json=1 shapes.
        const full = (data.full && (data.full.jpg || data.full.png || data.full.webp)) || null;
        const thumb = (data.thumbnail && (data.thumbnail.jpg || data.thumbnail.png || data.thumbnail.webp)) || null;
        return {
            url: data.url || (full && full.url) || '',
            thumb: data.thumb_url || data.thumb || (thumb && thumb.url) || data.url || (full && full.url) || '',
            width: data.width || (full && full.width) || null,
            height: data.height || (full && full.height) || null,
            name: data.name || data.filename || (data.url ? data.url.split('/').pop() : ''),
            alt: data.alt || '',
            caption: data.caption || '',
        };
    }

    async function upload(file, meta) {
        if (!CAN_UPLOAD) throw new Error('You do not have permission to upload media');
        const fd = new FormData();
        fd.append('action', 'upload');
        fd.append('csrf_token', CSRF);
        fd.append('file', file, file.name);
        meta = meta || {};
        if (meta.name) fd.append('name', meta.name);
        if (meta.alt) fd.append('alt', meta.alt);
        if (meta.caption) fd.append('caption', meta.caption);
        const r = await fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' });
        let data = null;
        try { data = await r.json(); } catch (e) { /* non-JSON error page */ }
        if (!r.ok || !data || !data.success) {
            throw new Error((data && data.error) || ('Upload failed (HTTP ' + r.status + ')'));
        }
        const item = normalize(data);
        if (!item.name) item.name = file.name.replace(/\.[a-z0-9]+$/i, '');
        items.unshift(item);
        return item;
    }

    // Persist name / alt / caption edits for an existing library image
    async function updateMeta(url, fields) {
        const fd = new FormData();
        fd.append('action', 'update_meta');
        fd.append('csrf_token', CSRF);
        fd.append('url', url);
        Object.keys(fields || {}).forEach(k => { if (fields[k] != null) fd.append(k, fields[k]); });
        const r = await fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await r.json().catch(() => null);
        if (!r.ok || !data || !data.success) throw new Error((data && data.error) || 'Could not save');
        const it = items.find(x => x.url === url);
        if (it) Object.assign(it, fields);
        return data.media;
    }

    async function loadLibrary(force) {
        if (loaded && !force) { render(); return; }
        $('[data-mp-loading]').classList.remove('hidden');
        try {
            const r = await fetch(ENDPOINT + '?json=1', { credentials: 'same-origin' });
            const data = await r.json();
            items = (data.images || []).map(normalize);
            loaded = true;
        } catch (e) {
            items = [];
            showToast('Could not load media library: ' + e.message, 'error');
        }
        $('[data-mp-loading]').classList.add('hidden');
        render();
    }

    function render() {
        const q = ($('[data-mp-search]').value || '').toLowerCase().trim();
        grid.innerHTML = '';
        const visible = items.filter(it => !q || (it.name + ' ' + (it.alt || '') + ' ' + (it.caption || '') + ' ' + it.url).toLowerCase().includes(q));
        $('[data-mp-empty]').classList.toggle('hidden', visible.length > 0);
        visible.forEach(it => {
            const tile = document.createElement('button');
            tile.type = 'button';
            tile.className = 'mp-tile group relative aspect-square rounded-xl overflow-hidden border-2 bg-surface-100 dark:bg-dark-300 focus:outline-none ' +
                (selected && selected.url === it.url ? 'border-accent-600 ring-2 ring-accent-300' : 'border-transparent hover:border-accent-400');
            tile.title = it.name + (it.alt ? ' — ' + it.alt : '');
            tile.innerHTML = '<img src="' + it.thumb + '" alt="' + escapeHtml(it.alt || '') + '" loading="lazy" class="w-full h-full object-cover">' +
                '<span class="absolute inset-x-0 bottom-0 px-2 py-1 text-[11px] text-white bg-black/50 truncate">' + escapeHtml(it.name || it.url.split('/').pop()) + '</span>';
            tile.addEventListener('click', () => select(it));
            tile.addEventListener('dblclick', () => { select(it); confirm(); });
            grid.appendChild(tile);
        });
    }

    function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function select(it) {
        selected = it;
        $('[data-mp-confirm]').disabled = false;
        $('[data-mp-selection]').classList.remove('hidden');
        const alt = $('[data-mp-alt]');
        if (it.alt) {
            alt.value = it.alt;
        } else if (!alt.value) {
            // Suggest alt text from a human-readable name; hash names (uploads are renamed to random hashes) give no useful hint.
            const base = (it.name || '').replace(/\.[a-z0-9]+$/i, '');
            if (!/^[0-9a-f]{24,}(-thumb)?$/i.test(base)) alt.value = base.replace(/[-_]+/g, ' ');
        }
        $('[data-mp-alt]').dataset.forUrl = it.url;
        render();
    }

    function confirm() {
        if (!selected) return;
        const altVal = $('[data-mp-alt]').value.trim();
        const item = Object.assign({}, selected, { alt: altVal });
        // Remember the alt text on the library entry so the next pick starts from it
        if (CAN_UPLOAD && altVal && altVal !== (selected.alt || '')) {
            updateMeta(selected.url, { alt: altVal }).catch(() => {});
        }
        close();
        if (onSelect) onSelect(item);
    }

    function showTab(name) {
        root.querySelectorAll('[data-mp-tab]').forEach(b => {
            const active = b.dataset.mpTab === name;
            b.className = 'mp-tab px-4 py-2 rounded-lg text-sm font-medium ' + (active ? 'bg-accent-600 text-white' : 'bg-surface-100 dark:bg-dark-300 text-gray-700 dark:text-gray-300');
        });
        root.querySelectorAll('[data-mp-panel]').forEach(p => p.classList.toggle('hidden', p.dataset.mpPanel !== name));
    }

    async function handleFiles(fileList) {
        const files = Array.from(fileList || []).filter(f => /^image\//.test(f.type));
        if (!files.length) { showToast('Please choose image files (JPG, PNG, GIF, WebP)', 'error'); return; }
        const progress = $('[data-mp-progress]');
        progress.classList.remove('hidden');
        let last = null, failed = 0;
        for (let i = 0; i < files.length; i++) {
            progress.textContent = 'Uploading ' + (i + 1) + ' of ' + files.length + ': ' + files[i].name + '…';
            try { last = await upload(files[i]); }
            catch (e) { failed++; showToast(files[i].name + ': ' + e.message, 'error'); }
        }
        progress.classList.add('hidden');
        if (last) {
            showToast((files.length - failed) + ' image' + (files.length - failed === 1 ? '' : 's') + ' uploaded', 'success');
            showTab('library');
            select(last);
            if (files.length === 1 && !failed) confirm();
        }
    }

    function open(opts) {
        opts = opts || {};
        onSelect = opts.onSelect || null;
        selected = null;
        $('[data-mp-alt]').value = '';
        $('[data-mp-confirm]').disabled = true;
        $('[data-mp-confirm]').textContent = opts.confirmLabel || 'Select';
        $('[data-mp-selection]').classList.add('hidden');
        $('#media-picker-title') && ($('#media-picker-title').textContent = opts.title || 'Select or upload media');
        root.classList.remove('hidden');
        root.setAttribute('aria-hidden', 'false');
        showTab(opts.tab === 'upload' && CAN_UPLOAD ? 'upload' : 'library');
        loadLibrary(true);
    }

    function close() {
        root.classList.add('hidden');
        root.setAttribute('aria-hidden', 'true');
    }

    // Wire static controls once
    root.querySelectorAll('[data-mp-close]').forEach(el => el.addEventListener('click', close));
    root.querySelectorAll('[data-mp-tab]').forEach(b => b.addEventListener('click', () => showTab(b.dataset.mpTab)));
    $('[data-mp-confirm]').addEventListener('click', confirm);
    $('[data-mp-search]').addEventListener('input', render);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !root.classList.contains('hidden')) close(); });
    const fileInput = $('[data-mp-file]');
    const dropzone = $('[data-mp-dropzone]');
    if (fileInput) fileInput.addEventListener('change', () => { handleFiles(fileInput.files); fileInput.value = ''; });
    if (dropzone) {
        ['dragenter', 'dragover'].forEach(ev => dropzone.addEventListener(ev, e => { e.preventDefault(); dropzone.classList.add('border-accent-500'); }));
        ['dragleave', 'drop'].forEach(ev => dropzone.addEventListener(ev, e => { e.preventDefault(); dropzone.classList.remove('border-accent-500'); }));
        dropzone.addEventListener('drop', e => handleFiles(e.dataTransfer.files));
    }
    if (typeof showToast !== 'function') {
        window.showToast = (m) => console.log(m);
    }

    return { open, close, upload, updateMeta, canUpload: CAN_UPLOAD };
})();
</script>
