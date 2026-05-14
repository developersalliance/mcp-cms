<?php
/**
 * AI Editor View
 *
 * Standard admin chrome (sidebar + header) with a header bar matching
 * edit.php, the outlined page rendered in an iframe, and an AI chat
 * panel below the iframe.
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/PageManager.php';
require_once __DIR__ . '/../core/PageSettings.php';
require_once __DIR__ . '/../core/SitemapGenerator.php';
require_once __DIR__ . '/../core/BlockParser.php';
require_once __DIR__ . '/../core/CSRF.php';

$config = require __DIR__ . '/../config/config.php';
$reservedFolders = $config['reserved_folders'] ?? ['cms'];
$pageSettings = new PageSettings($config['cms_dir'] . '/settings');
$sitemapGenerator = new SitemapGenerator($config['root_dir'], $config['base_url'] ?? 'http://localhost', $reservedFolders, $config['drafts_dir'] ?? null);
$pageManager = new PageManager($config['root_dir'], $reservedFolders, $config['drafts_dir'] ?? null, null, $sitemapGenerator, $pageSettings);
$blockParser = new BlockParser();

$pageId = $_GET['page_id'] ?? '';
$pagePath = $pageManager->getPagePath($pageId);
if (!$pagePath) {
    header('Location: /cms/admin/pages.php');
    exit;
}

// Publish / discard actions (mirrors edit.php). Round-trip via redirect so
// reload doesn't resubmit.
$actionMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'publish' && $pageManager->hasDraft($pageId)) {
            $pageManager->publishDraft($pageId);
            $actionMessage = 'published';
        } elseif ($action === 'discard' && $pageManager->hasDraft($pageId)) {
            $pageManager->discardDraft($pageId);
            $actionMessage = 'discarded';
        }
    } catch (Exception $e) {
        $actionMessage = 'error: ' . $e->getMessage();
    }
    header('Location: /cms/admin/edit-ai.php?page_id=' . urlencode($pageId) . ($actionMessage ? '&m=' . urlencode($actionMessage) : ''));
    exit;
}

$flashMessage = $_GET['m'] ?? '';
$hasDraft = $pageManager->hasDraft($pageId);
$blocks = $blockParser->parseBlocks($pagePath);
$blockMeta = array_map(function ($b) {
    return [
        'name' => $b['name'],
        'role' => $b['role'] ?? null,
        'custom' => !empty($b['custom']),
    ];
}, $blocks);

$csrfToken = CSRF::getToken();
$pageTitle = 'Edit with AI: ' . ($pageId ?: '/');
$activePage = 'pages';

$aiProvider = $config['ai_provider'] ?? '';
$aiReady = $aiProvider !== '' && !empty($config['ai_api_key']);

require __DIR__ . '/includes/header.php';
?>

<style>
  .ai-edit-iframe { width: 100%; height: 65vh; min-height: 460px; border: 1px solid rgb(229 231 235); border-radius: 12px; background: #fff; }
  .dark .ai-edit-iframe { border-color: rgb(55 65 81); }
  .ai-chat-log { max-height: 360px; overflow-y: auto; }
  .ai-chat-msg { padding: 10px 14px; border-radius: 12px; white-space: pre-wrap; word-break: break-word; }
  /* User input: neutral surface; not the accent — keep accent for the model's voice */
  .ai-chat-msg.user { background: rgb(244 246 248); color: rgb(55 65 81); margin-left: 60px; }
  .dark .ai-chat-msg.user { background: rgb(38 43 53); color: rgb(209 213 219); }
  /* Assistant: peachy accent */
  .ai-chat-msg.assistant { background: #fff5f3; color: #843220; margin-right: 60px; }
  .dark .ai-chat-msg.assistant { background: rgba(249,106,77,0.12); color: #ffd5cd; }
  .ai-chat-msg.tool { background: rgb(236 253 245); color: rgb(6 95 70); font-family: ui-monospace, monospace; font-size: 12px; border: 1px solid rgb(167 243 208); margin-right: 60px; }
  .ai-chat-log:not(.show-debug) .ai-chat-msg.tool { display: none; }
  .dark .ai-chat-msg.tool { background: rgba(16,185,129,0.08); color: rgb(167 243 208); border-color: rgba(16,185,129,0.25); }
  .ai-chat-msg.error { background: rgb(254 242 242); color: rgb(153 27 27); border: 1px solid rgb(254 202 202); margin-right: 60px; }
  .dark .ai-chat-msg.error { background: rgba(239,68,68,0.1); color: rgb(254 202 202); border-color: rgba(239,68,68,0.25); }
  .ai-chat-msg .tool-name { font-weight: 700; margin-bottom: 4px; }
  .block-chip { padding: 4px 10px; border-radius: 9999px; background: rgb(243 244 246); color: rgb(55 65 81); font-size: 12px; cursor: pointer; border: 1px solid transparent; transition: all 120ms; }
  .block-chip:hover { border-color: #f96a4d; color: #843220; }
  .dark .block-chip { background: rgb(38 43 53); color: rgb(209 213 219); }
  .dark .block-chip:hover { color: #ffd5cd; }
  .block-chip.active { background: #ffe8e4; color: #843220; border-color: #f96a4d; }
  .dark .block-chip.active { background: rgba(249,106,77,0.25); color: #ffd5cd; }
  /* Thinking indicator */
  .ai-thinking { display: inline-flex; gap: 4px; align-items: center; padding: 12px 14px; }
  .ai-thinking span { width: 7px; height: 7px; border-radius: 50%; background: currentColor; opacity: 0.35; animation: ai-thinking-pulse 1.1s infinite ease-in-out; }
  .ai-thinking span:nth-child(2) { animation-delay: 0.15s; }
  .ai-thinking span:nth-child(3) { animation-delay: 0.3s; }
  @keyframes ai-thinking-pulse {
    0%, 80%, 100% { opacity: 0.25; transform: scale(0.85); }
    40%           { opacity: 1;    transform: scale(1.1); }
  }
</style>

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">
        Edit with AI: <code class="text-accent-600"><?php echo htmlspecialchars($pageId ?: '/'); ?></code>
        <span id="draft-pill" class="ml-3 px-2.5 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400" style="<?php echo $hasDraft ? '' : 'display:none;'; ?>">Showing draft</span>
        <?php if ($aiReady): ?>
            <span class="ml-2 px-2.5 py-1 inline-flex items-center gap-1.5 text-xs leading-5 font-semibold rounded-full bg-accent-100 dark:bg-accent-900/30 text-accent-700 dark:text-accent-400">
                <span class="w-1.5 h-1.5 rounded-full bg-accent-500"></span><?php echo htmlspecialchars($aiProvider); ?>
            </span>
        <?php else: ?>
            <span class="ml-2 px-2.5 py-1 inline-flex items-center gap-1.5 text-xs leading-5 font-semibold rounded-full bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400">
                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>AI not configured
            </span>
        <?php endif; ?>
    </h1>
    <div class="flex flex-wrap items-center gap-3 text-sm">
        <a href="/cms/admin/pages.php" class="text-accent-600 hover:text-accent-700">&larr; Back to Pages</a>
        <span class="text-gray-400 dark:text-gray-600">|</span>
        <a href="/cms/admin/edit.php?page_id=<?php echo urlencode($pageId); ?>" class="text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white">Block editor</a>
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
    <?php if ($flashMessage === 'published'): ?>
        <div class="mt-3 px-3 py-2 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300 text-sm border border-emerald-200 dark:border-emerald-800/40">Draft published. The page is now live.</div>
    <?php elseif ($flashMessage === 'discarded'): ?>
        <div class="mt-3 px-3 py-2 rounded-lg bg-gray-50 dark:bg-dark-300 text-gray-700 dark:text-gray-300 text-sm border border-surface-200 dark:border-dark-200">Draft discarded.</div>
    <?php elseif ($flashMessage && strpos($flashMessage, 'error') === 0): ?>
        <div class="mt-3 px-3 py-2 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 text-sm border border-red-200 dark:border-red-800/40"><?php echo htmlspecialchars($flashMessage); ?></div>
    <?php endif; ?>
</div>

<!-- Chat panel (sits ABOVE the page render) -->
<div class="mb-4 bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200">
    <div class="flex items-center justify-between px-6 py-4 border-b border-surface-200 dark:border-dark-200">
        <div class="flex items-center gap-3">
            <svg class="w-5 h-5 text-accent-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
            </svg>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">AI Assistant</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">uses MCP tools to read &amp; modify this page</span>
        </div>
        <div class="flex items-center gap-4 text-sm">
            <label class="inline-flex items-center gap-1.5 text-gray-500 dark:text-gray-400 cursor-pointer select-none">
                <input type="checkbox" id="outlines-toggle" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                Block outlines
            </label>
            <label class="inline-flex items-center gap-1.5 text-gray-500 dark:text-gray-400 cursor-pointer select-none">
                <input type="checkbox" id="debug-toggle" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                Debug
            </label>
            <button id="clear-chat" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">Clear</button>
        </div>
    </div>
    <div id="chat-log" class="ai-chat-log px-6 py-5 flex flex-col gap-3">
        <div class="ai-chat-msg assistant">Hi! Tell me what to change on this page. I can update text, swap images, insert blocks, and create drafts you can review.<?php if (!$aiReady): ?>

⚠ No AI provider is configured. Set one in <code>/cms/admin/ai-settings.php</code> first.<?php endif; ?></div>
    </div>
    <!-- Media drawer (collapsed by default) -->
    <div id="media-drawer" class="border-t border-surface-200 dark:border-dark-200">
        <button type="button" id="media-toggle" class="w-full flex items-center justify-between px-6 py-2.5 text-sm text-gray-600 dark:text-gray-300 hover:bg-surface-50 dark:hover:bg-dark-500 transition">
            <span class="flex items-center gap-2">
                <svg class="w-4 h-4 text-accent-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                <span class="font-medium">Media</span>
                <span id="media-count" class="text-xs text-gray-500 dark:text-gray-400">(0 attached)</span>
            </span>
            <svg id="media-chev" class="w-4 h-4 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
        </button>
        <div id="media-panel" class="hidden px-6 py-3 space-y-3 bg-surface-50 dark:bg-dark-500 border-t border-surface-200 dark:border-dark-200">
            <div class="flex flex-wrap items-center gap-2">
                <label class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-white dark:bg-dark-300 hover:bg-accent-50 dark:hover:bg-accent-900/30 text-gray-700 dark:text-gray-200 hover:text-accent-700 dark:hover:text-accent-300 rounded-md border border-surface-200 dark:border-dark-200 cursor-pointer">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
                    Upload image(s)
                    <input type="file" id="media-upload" accept="image/*" multiple class="hidden">
                </label>
                <button type="button" id="media-browse-toggle" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-white dark:bg-dark-300 hover:bg-accent-50 dark:hover:bg-accent-900/30 text-gray-700 dark:text-gray-200 hover:text-accent-700 dark:hover:text-accent-300 rounded-md border border-surface-200 dark:border-dark-200">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path></svg>
                    Browse library
                </button>
                <span id="media-status" class="text-xs text-gray-500 dark:text-gray-400 ml-auto"></span>
            </div>

            <!-- Library browse panel (hidden until toggled) -->
            <div id="media-library" class="hidden border border-surface-200 dark:border-dark-200 rounded-md bg-white dark:bg-dark-300 p-2">
                <input type="search" id="media-search" placeholder="Filter by filename…" class="w-full mb-2 px-2 py-1 bg-surface-50 dark:bg-dark-400 border border-surface-200 dark:border-dark-200 rounded text-xs text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-accent-500">
                <div id="media-library-grid" class="grid grid-cols-4 sm:grid-cols-6 md:grid-cols-8 gap-1.5 max-h-48 overflow-y-auto"></div>
                <p id="media-library-empty" class="text-xs text-gray-500 dark:text-gray-400 p-2 hidden">No images. Use Upload to add one.</p>
            </div>

            <!-- Attached strip -->
            <div id="media-attached" class="flex flex-wrap gap-2 min-h-[16px]">
                <span id="media-attached-empty" class="text-xs text-gray-400 dark:text-gray-500">No media attached. Upload or browse to attach.</span>
            </div>
        </div>
    </div>

    <form id="chat-form" class="flex gap-2 px-6 py-4 border-t border-surface-200 dark:border-dark-200 bg-surface-50 dark:bg-dark-500 rounded-b-2xl">
        <textarea id="chat-msg" rows="2" placeholder="e.g. In the hero block, change the heading to ... · Swap the first image in services to ..." class="flex-1 px-4 py-2.5 bg-white dark:bg-dark-300 border border-surface-200 dark:border-dark-200 rounded-xl text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-accent-500 resize-none" <?php echo $aiReady ? '' : 'disabled'; ?>></textarea>
        <button type="submit" id="chat-send" class="px-6 py-2.5 bg-accent-600 hover:bg-accent-700 text-white text-sm font-semibold rounded-xl shadow-lg shadow-accent-500/25 disabled:opacity-50 disabled:cursor-not-allowed" <?php echo $aiReady ? '' : 'disabled'; ?>>Send</button>
    </form>
</div>

<!-- Block chips -->
<?php if (count($blockMeta)): ?>
<div class="mb-4 flex flex-wrap items-center gap-2">
    <span class="text-xs uppercase tracking-wider font-semibold text-gray-500 dark:text-gray-400 mr-1">Available Blocks:</span>
    <?php foreach ($blockMeta as $b): ?>
        <span class="block-chip" data-block-name="<?php echo htmlspecialchars($b['name']); ?>"><?php echo htmlspecialchars($b['name']); ?><?php echo $b['custom'] ? ' •' : ''; ?></span>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Outlined page render (sits BELOW the chat) -->
<iframe class="ai-edit-iframe" id="page-frame" src="/cms/admin/edit-ai-render.php?page_id=<?php echo urlencode($pageId); ?>&draft=1" loading="eager"></iframe>

<script>
const PAGE_ID = <?php echo json_encode($pageId); ?>;
const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
let conversation = [];
let attachedMedia = []; // [{url, name, thumb}]
let libraryCache = null;
let sending = false;

const log = document.getElementById('chat-log');
const input = document.getElementById('chat-msg');
const form = document.getElementById('chat-form');
const sendBtn = document.getElementById('chat-send');
const frame = document.getElementById('page-frame');

// --- Media drawer ---
const mediaToggle = document.getElementById('media-toggle');
const mediaPanel = document.getElementById('media-panel');
const mediaChev = document.getElementById('media-chev');
const mediaCount = document.getElementById('media-count');
const mediaUpload = document.getElementById('media-upload');
const mediaStatus = document.getElementById('media-status');
const mediaBrowseToggle = document.getElementById('media-browse-toggle');
const mediaLibrary = document.getElementById('media-library');
const mediaSearch = document.getElementById('media-search');
const mediaLibGrid = document.getElementById('media-library-grid');
const mediaLibEmpty = document.getElementById('media-library-empty');
const mediaAttached = document.getElementById('media-attached');
const mediaAttachedEmpty = document.getElementById('media-attached-empty');

mediaToggle.addEventListener('click', () => {
  const open = mediaPanel.classList.toggle('hidden');
  mediaChev.classList.toggle('rotate-180', !open);
});

function updateAttachedUI() {
  mediaCount.textContent = '(' + attachedMedia.length + ' attached)';
  // Clear and rebuild
  Array.from(mediaAttached.querySelectorAll('[data-attached-thumb]')).forEach(n => n.remove());
  if (attachedMedia.length === 0) {
    mediaAttachedEmpty.style.display = '';
  } else {
    mediaAttachedEmpty.style.display = 'none';
    attachedMedia.forEach((m, idx) => {
      const wrap = document.createElement('div');
      wrap.dataset.attachedThumb = '1';
      wrap.className = 'relative w-14 h-14 rounded overflow-hidden bg-surface-200 dark:bg-dark-200 border border-surface-200 dark:border-dark-200';
      wrap.innerHTML =
        '<img src="' + (m.thumb || m.url) + '" alt="" class="w-full h-full object-cover" loading="lazy">' +
        '<button type="button" class="absolute top-0.5 right-0.5 w-4 h-4 rounded-full bg-black/70 text-white text-xs leading-none flex items-center justify-center hover:bg-red-600" title="Detach">×</button>';
      wrap.querySelector('button').addEventListener('click', () => {
        attachedMedia.splice(idx, 1);
        updateAttachedUI();
      });
      wrap.title = m.name || m.url;
      mediaAttached.appendChild(wrap);
    });
  }
}

function attach(item) {
  if (!item || !item.url) return;
  if (attachedMedia.some(m => m.url === item.url)) return; // dedupe
  attachedMedia.push({ url: item.url, name: item.name || '', thumb: item.thumb || item.url });
  updateAttachedUI();
}

// Upload
mediaUpload.addEventListener('change', async () => {
  const files = Array.from(mediaUpload.files || []);
  if (!files.length) return;
  mediaStatus.textContent = 'Uploading ' + files.length + '…';
  let done = 0, failed = 0, lastErr = '';
  for (const file of files) {
    try {
      const fd = new FormData();
      fd.append('action', 'upload');
      fd.append('csrf_token', CSRF_TOKEN);
      fd.append('file', file, file.name);
      const r = await fetch('/cms/admin/media.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        redirect: 'error',
      });
      const data = await r.json();
      if (r.ok && data && data.success && data.url) {
        attach({ url: data.url, name: file.name });
        done++;
      } else {
        failed++;
        lastErr = (data && data.error) || ('HTTP ' + r.status);
      }
    } catch (e) {
      failed++;
      lastErr = e && e.message ? e.message : String(e);
      console.error('media drawer upload failed', e);
    }
  }
  mediaStatus.textContent = (done ? done + ' uploaded' : '') + (failed ? (' · ' + failed + ' failed: ' + lastErr) : '');
  mediaUpload.value = '';
  libraryCache = null; // force reload next time
});

// Browse library
mediaBrowseToggle.addEventListener('click', async () => {
  const open = mediaLibrary.classList.toggle('hidden');
  if (!open) await loadLibrary();
});
mediaSearch.addEventListener('input', () => renderLibrary(mediaSearch.value));

async function loadLibrary() {
  mediaStatus.textContent = 'Loading library…';
  try {
    const r = await fetch('/cms/admin/media.php?json=1', {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
      redirect: 'error',
    });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    const data = await r.json();
    libraryCache = (data && data.images) || [];
    mediaStatus.textContent = libraryCache.length + ' image(s) in library';
    renderLibrary('');
  } catch (e) {
    console.error('media drawer library load failed', e);
    mediaStatus.textContent = 'Could not load library: ' + e.message;
    libraryCache = [];
  }
}

function renderLibrary(filter) {
  if (!libraryCache) return;
  mediaLibGrid.innerHTML = '';
  const q = (filter || '').toLowerCase().trim();
  const items = libraryCache.filter(it => !q || (it.name || '').toLowerCase().includes(q));
  if (!items.length) {
    mediaLibEmpty.classList.remove('hidden');
    return;
  }
  mediaLibEmpty.classList.add('hidden');
  items.forEach(it => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'relative aspect-square rounded overflow-hidden bg-surface-100 dark:bg-dark-400 border-2 border-transparent hover:border-accent-500 transition';
    btn.innerHTML = '<img src="' + (it.thumb || it.url) + '" alt="" class="w-full h-full object-cover" loading="lazy">';
    btn.title = it.name || it.url;
    btn.addEventListener('click', () => attach(it));
    mediaLibGrid.appendChild(btn);
  });
}

updateAttachedUI();

function appendMsg(role, text, opts) {
  opts = opts || {};
  const div = document.createElement('div');
  div.className = 'ai-chat-msg ' + role;
  if (opts.toolName) {
    const head = document.createElement('div');
    head.className = 'tool-name';
    head.textContent = '⚙ ' + opts.toolName;
    div.appendChild(head);
  }
  const body = document.createElement('div');
  body.textContent = text;
  div.appendChild(body);
  log.appendChild(div);
  log.scrollTop = log.scrollHeight;
  return div;
}

function appendThinking() {
  const div = document.createElement('div');
  div.className = 'ai-chat-msg assistant ai-thinking';
  div.innerHTML = '<span></span><span></span><span></span>';
  log.appendChild(div);
  log.scrollTop = log.scrollHeight;
  return div;
}

// Reload the preview iframe in place, preserving the user's scroll
// position. A brief fade dampens the swap.
function softReloadFrame() {
  let savedY = 0;
  try { savedY = frame.contentWindow.scrollY || 0; } catch (e) {}
  frame.style.transition = 'opacity 120ms ease';
  frame.style.opacity = '0.55';
  const onLoad = () => {
    frame.removeEventListener('load', onLoad);
    const restore = () => {
      try { frame.contentWindow.scrollTo(0, savedY); } catch (e) {}
      try { frame.contentWindow.cmsAiRefresh && frame.contentWindow.cmsAiRefresh(); } catch (e) {}
      frame.style.opacity = '1';
    };
    // Wait a paint, then a moment for late layout (images, fonts).
    requestAnimationFrame(() => setTimeout(restore, 40));
    setTimeout(() => { try { frame.contentWindow.scrollTo(0, savedY); } catch (e) {} }, 500);
  };
  frame.addEventListener('load', onLoad);
  const cur = frame.src;
  frame.src = cur.includes('&_t=') ? cur.replace(/&_t=\d+/, '&_t=' + Date.now()) : cur + '&_t=' + Date.now();
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (sending) return;
  const text = input.value.trim();
  if (!text) return;
  input.value = '';
  appendMsg('user', text);
  conversation.push({ role: 'user', content: text });
  sending = true;
  sendBtn.disabled = true;
  const thinking = appendThinking();
  try {
    const r = await fetch('/cms/admin/edit-ai-chat.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        csrf_token: CSRF_TOKEN,
        page_id: PAGE_ID,
        messages: conversation,
        attached_media: attachedMedia.map(m => ({ url: m.url, name: m.name })),
      }),
    });
    thinking.remove();
    if (!r.ok) {
      const t = await r.text();
      appendMsg('error', 'HTTP ' + r.status + ': ' + t.slice(0, 500));
      sending = false; sendBtn.disabled = false; return;
    }
    const data = await r.json();
    if (Array.isArray(data.media_warnings) && data.media_warnings.length) {
      data.media_warnings.forEach((w) => appendMsg('error', w));
    }
    if (data.error) {
      appendMsg('error', data.error);
      sending = false; sendBtn.disabled = false; return;
    }
    // Clear attached strip on successful send — the server preserved them
    // inside the user turn it just persisted, so they remain in conversation
    // history. The drawer is for the NEXT message.
    attachedMedia = [];
    updateAttachedUI();
    mediaPanel.classList.add('hidden');
    mediaChev.classList.remove('rotate-180');
    (data.tool_calls || []).forEach((tc) => {
      const summary = tc.input ? JSON.stringify(tc.input) : '';
      appendMsg('tool', summary.length > 400 ? summary.slice(0, 400) + '…' : summary, { toolName: tc.name });
      if (tc.result_summary) {
        appendMsg('tool', tc.result_summary, { toolName: tc.name + ' →' });
      }
    });
    if (data.assistant) appendMsg('assistant', data.assistant);
    if (data.messages) conversation = data.messages;
    if (data.modified) {
      softReloadFrame();
      const pill = document.getElementById('draft-pill');
      if (pill) pill.style.display = '';
    }
  } catch (err) {
    thinking.remove();
    appendMsg('error', String(err));
  } finally {
    sending = false; sendBtn.disabled = false; input.focus();
  }
});

input.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    form.requestSubmit();
  }
});

document.getElementById('clear-chat').addEventListener('click', () => {
  conversation = [];
  attachedMedia = [];
  updateAttachedUI();
  log.innerHTML = '';
  appendMsg('assistant', 'Conversation cleared.');
});

// Debug toggle — show internal tool calls + their results.
// Default ON: most operators want to see what the assistant did.
// Users can untick to hide; state persists in localStorage.
const debugToggle = document.getElementById('debug-toggle');
const savedDebug = localStorage.getItem('cmsAiDebug');
const isDebug = savedDebug === null ? true : savedDebug === '1';
debugToggle.checked = isDebug;
if (isDebug) log.classList.add('show-debug');
debugToggle.addEventListener('change', () => {
  log.classList.toggle('show-debug', debugToggle.checked);
  localStorage.setItem('cmsAiDebug', debugToggle.checked ? '1' : '0');
});

// Outlines toggle — hide the dashed block outlines in the iframe while
// keeping the name labels. Default ON. Posts to the iframe; iframe sets
// a body class that switches outline visibility via CSS.
const outlinesToggle = document.getElementById('outlines-toggle');
const savedOutlines = localStorage.getItem('cmsAiOutlines');
const outlinesOn = savedOutlines === null ? true : savedOutlines === '1';
outlinesToggle.checked = outlinesOn;
function pushOutlinesState() {
  try {
    frame.contentWindow.postMessage({ type: 'cms-ai-set-outlines', on: outlinesToggle.checked }, '*');
  } catch (e) {}
}
outlinesToggle.addEventListener('change', () => {
  localStorage.setItem('cmsAiOutlines', outlinesToggle.checked ? '1' : '0');
  pushOutlinesState();
});
// Re-send state every time the iframe finishes loading (after a soft reload too)
frame.addEventListener('load', () => pushOutlinesState());

document.querySelectorAll('.block-chip').forEach((chip) => {
  chip.addEventListener('click', () => {
    document.querySelectorAll('.block-chip').forEach(c => c.classList.remove('active'));
    chip.classList.add('active');
    const name = chip.dataset.blockName;
    try { frame.contentWindow.cmsAiHighlight && frame.contentWindow.cmsAiHighlight(name); } catch (e) {}
    // Pre-fill the chat input with a hint, but don't steal focus from the page.
    input.value = 'In the ' + name + ' block, ';
  });
});

window.addEventListener('message', (e) => {
  if (e.data && e.data.type === 'cms-ai-select-block') {
    const name = e.data.name;
    document.querySelectorAll('.block-chip').forEach(c => c.classList.toggle('active', c.dataset.blockName === name));
    try { frame.contentWindow.cmsAiHighlight && frame.contentWindow.cmsAiHighlight(name); } catch (err) {}
    // Pre-fill the chat input with a hint, but don't steal focus from the page.
    input.value = 'In the ' + name + ' block, ';
  }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
