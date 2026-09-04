<?php
/**
 * AI Editor — Blog Post.
 *
 * Mirror of edit-ai.php for collection items. Renders the post in a
 * preview iframe and exposes the same chat panel that edit-ai.php uses.
 * The chat backend (edit-ai-chat.php) accepts collection_id + slug
 * alongside the page_id flow and switches to a blog-aware system
 * prompt.
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/BlogManager.php';
require_once __DIR__ . '/../core/CSRF.php';

$config       = require __DIR__ . '/../config/config.php';
$blogManager  = new BlogManager($config['root_dir'], $config['cms_dir']);

$collectionId = $_GET['collection'] ?? 'blog';
$slug         = $_GET['slug'] ?? '';

if ($slug === '') {
    header('Location: /cms/admin/blog.php?collection=' . urlencode($collectionId));
    exit;
}

$post = $blogManager->getPost($collectionId, $slug);
if (!$post) {
    header('Location: /cms/admin/blog.php?collection=' . urlencode($collectionId) . '&error=' . urlencode('Post not found: ' . $slug));
    exit;
}

$csrfToken   = CSRF::getToken();
$pageTitle   = 'Edit with AI: ' . ($post['title'] ?? $slug);
$activePage  = 'blog';

$aiProvider  = $config['ai_provider'] ?? '';
$aiReady     = $aiProvider !== '' && !empty($config['ai_api_key']);

require __DIR__ . '/includes/header.php';
?>

<style>
  .ai-edit-iframe { width: 100%; height: 65vh; min-height: 460px; border: 1px solid rgb(229 231 235); border-radius: 12px; background: #fff; }
  .dark .ai-edit-iframe { border-color: rgb(55 65 81); }
  .ai-chat-log { max-height: 360px; overflow-y: auto; }
  .ai-chat-msg { padding: 10px 14px; border-radius: 12px; white-space: pre-wrap; word-break: break-word; }
  .ai-chat-msg.user { background: rgb(244 246 248); color: rgb(55 65 81); margin-left: 60px; }
  .dark .ai-chat-msg.user { background: rgb(38 43 53); color: rgb(209 213 219); }
  .ai-chat-msg.assistant { background: #fff5f3; color: #843220; margin-right: 60px; }
  .dark .ai-chat-msg.assistant { background: rgba(249,106,77,0.12); color: #ffd5cd; }
  .ai-chat-msg.tool { background: rgb(236 253 245); color: rgb(6 95 70); font-family: ui-monospace, monospace; font-size: 12px; border: 1px solid rgb(167 243 208); margin-right: 60px; }
  .ai-chat-log:not(.show-debug) .ai-chat-msg.tool { display: none; }
  .ai-chat-msg.error { background: rgb(254 242 242); color: rgb(153 27 27); border: 1px solid rgb(254 202 202); margin-right: 60px; }
  .ai-chat-msg .tool-name { font-weight: 700; margin-bottom: 4px; }
  .ai-thinking { display: inline-flex; gap: 4px; align-items: center; padding: 12px 14px; }
  .ai-thinking span { width: 7px; height: 7px; border-radius: 50%; background: currentColor; opacity: 0.35; animation: ai-thinking-pulse 1.1s infinite ease-in-out; }
  .ai-thinking span:nth-child(2) { animation-delay: 0.15s; }
  .ai-thinking span:nth-child(3) { animation-delay: 0.3s; }
  @keyframes ai-thinking-pulse { 0%, 80%, 100% { opacity: 0.3; transform: scale(0.85); } 40% { opacity: 1; transform: scale(1); } }
</style>

<!-- Header bar -->
<div class="flex items-center justify-between mb-4 px-6 pt-6">
  <div class="flex items-center gap-4 flex-wrap">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
      <?php echo htmlspecialchars($post['title'] ?? $slug); ?>
    </h1>
    <span class="text-sm text-gray-500 dark:text-gray-400">
      <code><?php echo htmlspecialchars($collectionId . '/' . $slug); ?></code>
    </span>
    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-400">
      Blog post · AI mode
    </span>
  </div>
  <div class="flex items-center gap-4 text-sm">
    <a href="/cms/admin/blog-edit.php?collection=<?php echo urlencode($collectionId); ?>&slug=<?php echo urlencode($slug); ?>" class="text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white">Form editor</a>
    <a href="/cms/admin/blog-preview.php?collection=<?php echo urlencode($collectionId); ?>&slug=<?php echo urlencode($slug); ?>" target="_blank" class="text-emerald-600 dark:text-emerald-400 hover:text-emerald-700">Preview</a>
    <a href="/cms/admin/blog.php?collection=<?php echo urlencode($collectionId); ?>" class="text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white">Back to posts</a>
  </div>
</div>

<?php if (!$aiReady): ?>
<div class="mx-6 mb-4 p-4 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 text-amber-800 dark:text-amber-200">
  AI provider is not configured. Set it up in <a href="/cms/admin/ai-settings.php" class="underline">AI Settings</a> before chatting.
</div>
<?php endif; ?>

<div class="px-6 pb-6">
  <!-- Chat panel (above iframe; same pattern as edit-ai.php) -->
  <div class="mb-4 bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 p-5">
    <div id="chat-log" class="ai-chat-log space-y-3 mb-3">
      <div class="ai-chat-msg assistant">
        Hi — I can edit this post. Tell me what to change. Examples:
        • "Rewrite the intro to focus on AI buyers."
        • "Add a callout box after the third paragraph saying X."
        • "Replace the featured image with the latest in the library."
        • "Tighten the FAQ section to 3 questions."
      </div>
    </div>

    <form id="chat-form" class="flex gap-2 items-start">
      <textarea id="chat-msg" rows="2" placeholder="Tell the AI what to change…" class="flex-1 px-4 py-2.5 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white focus:border-accent-500 transition-all resize-y" <?php echo $aiReady ? '' : 'disabled'; ?>></textarea>
      <button type="submit" id="chat-send" class="btn-primary px-5 py-3 text-white rounded-xl font-medium shadow-lg shadow-accent-500/25 disabled:opacity-50 disabled:cursor-not-allowed" <?php echo $aiReady ? '' : 'disabled'; ?>>Send</button>
    </form>

    <label class="flex items-center gap-2 mt-3 text-xs text-gray-500 dark:text-gray-400">
      <input type="checkbox" id="debug-toggle" class="rounded">
      Show tool calls (debug)
    </label>
  </div>

  <!-- Post preview iframe -->
  <iframe class="ai-edit-iframe" id="page-frame" src="/cms/admin/blog-preview.php?collection=<?php echo urlencode($collectionId); ?>&slug=<?php echo urlencode($slug); ?>" loading="eager"></iframe>
</div>

<script>
const COLLECTION_ID = <?php echo json_encode($collectionId); ?>;
const POST_SLUG = <?php echo json_encode($slug); ?>;
const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
let conversation = [];
let sending = false;

const log = document.getElementById('chat-log');
const input = document.getElementById('chat-msg');
const form = document.getElementById('chat-form');
const sendBtn = document.getElementById('chat-send');
const frame = document.getElementById('page-frame');
const debugToggle = document.getElementById('debug-toggle');

debugToggle.addEventListener('change', () => {
  log.classList.toggle('show-debug', debugToggle.checked);
});

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

function softReloadFrame() {
  let savedY = 0;
  try { savedY = frame.contentWindow.scrollY || 0; } catch (e) {}
  frame.style.transition = 'opacity 120ms ease';
  frame.style.opacity = '0.55';
  const onLoad = () => {
    frame.removeEventListener('load', onLoad);
    const restore = () => {
      try { frame.contentWindow.scrollTo(0, savedY); } catch (e) {}
      frame.style.opacity = '1';
    };
    requestAnimationFrame(() => setTimeout(restore, 40));
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
        collection_id: COLLECTION_ID,
        slug: POST_SLUG,
        messages: conversation,
      }),
    });
    thinking.remove();
    if (!r.ok) {
      const t = await r.text();
      appendMsg('error', 'HTTP ' + r.status + ': ' + t.slice(0, 500));
      sending = false; sendBtn.disabled = false; return;
    }
    const data = await r.json();
    if (data.error) {
      appendMsg('error', data.error);
      sending = false; sendBtn.disabled = false; return;
    }
    // Surface tool calls when debug is on
    if (Array.isArray(data.tool_calls)) {
      data.tool_calls.forEach(tc => {
        appendMsg('tool', JSON.stringify(tc.input || {}) + '\n→ ' + (tc.result_summary || ''), { toolName: tc.name });
      });
    }
    if (data.assistant) {
      appendMsg('assistant', data.assistant);
      conversation.push({ role: 'assistant', content: data.assistant });
    }
    if (data.modified) {
      softReloadFrame();
    }
  } catch (e) {
    thinking.remove();
    appendMsg('error', e && e.message ? e.message : String(e));
  } finally {
    sending = false;
    sendBtn.disabled = false;
  }
});

// Ctrl/Cmd+Enter sends
input.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
    e.preventDefault();
    form.requestSubmit();
  }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
