<?php
/**
 * AI Editor — Outlined Page Render
 *
 * Loaded inside the edit-ai.php iframe. Reads the page file, swaps CMS:BLOCK
 * markers for wrapper elements, injects an overlay script that draws block
 * outlines + name labels over the rendered page.
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/PageManager.php';
require_once __DIR__ . '/../core/PageSettings.php';
require_once __DIR__ . '/../core/BlockParser.php';

$config = require __DIR__ . '/../config/config.php';
$reservedFolders = $config['reserved_folders'] ?? ['cms'];
$pageSettings = new PageSettings($config['cms_dir'] . '/settings');
$pageManager = new PageManager($config['root_dir'], $reservedFolders, $config['drafts_dir'] ?? null, null, null, $pageSettings);

$pageId = $_GET['page_id'] ?? '';
$showDraft = isset($_GET['draft']) && $_GET['draft'] === '1';

$pagePath = $pageManager->getPagePath($pageId);
if (!$pagePath) {
    http_response_code(404);
    echo '<!doctype html><html><body><p style="font:14px sans-serif;color:#666;padding:40px;text-align:center">Page not found.</p></body></html>';
    exit;
}

if ($showDraft) {
    $loaded = $pageManager->loadCurrentPageContent($pageId, $pagePath);
    $content = $loaded ? $loaded['content'] : (string)@file_get_contents($pagePath);
} else {
    $content = (string)@file_get_contents($pagePath);
}

/* Wrap each CMS:BLOCK region in a custom <cms-block> element so we can
 * outline it client-side. BlockParser owns the marker grammar; reuse
 * it. Walk in reverse so substring offsets stay valid as we splice. */
$blockParser = new BlockParser();
$blocks = $blockParser->parseBlocksFromString($content);
usort($blocks, fn($a, $b) => $b['start_pos'] <=> $a['start_pos']);
foreach ($blocks as $b) {
    $openTag = '<cms-block data-cms-block="' . htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8') . '">';
    $replacement = $openTag . $b['content'] . '</cms-block>';
    $content = substr($content, 0, $b['start_pos']) . $replacement . substr($content, $b['end_pos']);
}

// Overlay assets injected before </body>
$overlay = <<<'HTML'
<style>
  /* Force scroll-trigger animation classes to be visible in the editor so
     content below the fold (which the IntersectionObserver hasn't seen yet)
     still renders. Live sites rely on JS-added .visible classes; we don't
     wait for that here. */
  .fade, .reveal, .animate-on-scroll, .scroll-fade, .aos-init,
  [data-aos], [data-reveal], [data-animate], [data-scroll],
  [class*="fade-in"], [class*="scroll-fade"] {
    opacity: 1 !important;
    transform: none !important;
    visibility: visible !important;
    filter: none !important;
  }

  cms-block { display: contents; }
  .cms-ai-outline {
    position: absolute;
    pointer-events: none;
    box-sizing: border-box;
    border: 1.5px dashed rgba(249, 106, 77, 0.6);
    border-radius: 4px;
    z-index: 999999;
    transition: border-color 120ms ease, background 120ms ease;
  }
  .cms-ai-outline:hover {
    border-color: rgba(249, 106, 77, 1);
    background: rgba(249, 106, 77, 0.06);
  }
  /* Label sits just inside the outline's top edge (below the border). */
  .cms-ai-label {
    position: absolute;
    top: 0;
    left: 0;
    background: #f96a4d;
    color: #fff;
    font: 600 11px/1 ui-sans-serif, system-ui, -apple-system, sans-serif;
    padding: 3px 8px;
    border-radius: 0 0 4px 0;
    pointer-events: auto;
    cursor: pointer;
    white-space: nowrap;
    user-select: none;
  }
  .cms-ai-label:hover { background: #e64d2e; }
  .cms-ai-outline.is-selected { border-color: #f59e0b; background: rgba(245,158,11,0.08); }
  .cms-ai-outline.is-selected .cms-ai-label { background: #f59e0b; }
  /* When the parent toggles outlines off, hide the dashed border but
     keep the name label visible (it's still useful to identify regions). */
  body.cms-ai-no-outlines .cms-ai-outline {
    border-color: transparent !important;
    background: transparent !important;
  }
  body.cms-ai-no-outlines .cms-ai-outline:hover { background: transparent !important; }
</style>
<script>
(function () {
  var overlays = [];
  function build() {
    overlays.forEach(function (o) { o.remove(); });
    overlays = [];
    var blocks = document.querySelectorAll('cms-block[data-cms-block]');
    blocks.forEach(function (block) {
      var name = block.getAttribute('data-cms-block');
      var o = document.createElement('div');
      o.className = 'cms-ai-outline';
      o.setAttribute('data-cms-block-overlay', name);
      var label = document.createElement('div');
      label.className = 'cms-ai-label';
      label.textContent = name;
      label.addEventListener('click', function (e) {
        e.preventDefault();
        try { window.parent.postMessage({ type: 'cms-ai-select-block', name: name }, '*'); } catch (err) {}
      });
      o.appendChild(label);
      document.body.appendChild(o);
      overlays.push({ el: o, src: block });
    });
    position();
  }
  function position() {
    overlays.forEach(function (entry) {
      // Use the union of all child element rects so display:contents wrappers measure correctly
      var children = entry.src.children;
      if (!children.length) {
        entry.el.style.display = 'none';
        return;
      }
      var rect = null;
      for (var i = 0; i < children.length; i++) {
        var r = children[i].getBoundingClientRect();
        if (!rect) rect = { top: r.top, left: r.left, right: r.right, bottom: r.bottom };
        else {
          rect.top = Math.min(rect.top, r.top);
          rect.left = Math.min(rect.left, r.left);
          rect.right = Math.max(rect.right, r.right);
          rect.bottom = Math.max(rect.bottom, r.bottom);
        }
      }
      if (rect.bottom <= rect.top || rect.right <= rect.left) {
        entry.el.style.display = 'none';
        return;
      }
      entry.el.style.display = 'block';
      entry.el.style.top = (rect.top + window.scrollY) + 'px';
      entry.el.style.left = (rect.left + window.scrollX) + 'px';
      entry.el.style.width = (rect.right - rect.left) + 'px';
      entry.el.style.height = (rect.bottom - rect.top) + 'px';
    });
  }
  window.cmsAiRefresh = build;
  window.cmsAiHighlight = function (name) {
    overlays.forEach(function (entry) {
      entry.el.classList.toggle('is-selected', entry.el.getAttribute('data-cms-block-overlay') === name);
    });
  };
  // Parent posts an outline visibility preference; toggle the body class.
  window.addEventListener('message', function (e) {
    if (!e.data || e.data.type !== 'cms-ai-set-outlines') return;
    document.body.classList.toggle('cms-ai-no-outlines', !e.data.on);
  });
  document.addEventListener('DOMContentLoaded', build);
  if (document.readyState !== 'loading') build();
  window.addEventListener('resize', position);
  window.addEventListener('scroll', position, true);
  // Re-position after late-loading images / fonts settle layout
  setTimeout(position, 300);
  setTimeout(position, 1000);
})();
</script>
HTML;

if (stripos($content, '</body>') !== false) {
    $content = preg_replace('#</body>#i', $overlay . '</body>', $content, 1);
} else {
    $content .= $overlay;
}

// Render via a temp file so PHP in the source page still executes.
$tempFile = tempnam(sys_get_temp_dir(), 'cms_aiedit_');
file_put_contents($tempFile, $content);
include $tempFile;
@unlink($tempFile);
