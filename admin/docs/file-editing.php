<?php

require_once __DIR__ . '/../includes/auth-guard.php';

$pageTitle = 'Docs — AI File Editing';
$activePage = 'docs';

require __DIR__ . '/../includes/header.php';
?>

<style>
    .doc pre { background: #0f172a; color: #e2e8f0; padding: 1rem; border-radius: 0.5rem; overflow-x: auto; font-size: 0.85rem; line-height: 1.5; }
    .doc code { font-family: 'JetBrains Mono', ui-monospace, monospace; }
    .doc :not(pre) > code { background: #f1f5f9; color: #0f172a; padding: 0.1rem 0.35rem; border-radius: 0.25rem; font-size: 0.85em; }
    html.dark .doc :not(pre) > code { background: #1e293b; color: #e2e8f0; }
    .doc h2 { font-size: 1.5rem; font-weight: 700; margin-top: 2rem; margin-bottom: 0.75rem; }
    .doc h3 { font-size: 1.15rem; font-weight: 600; margin-top: 1.5rem; margin-bottom: 0.5rem; }
    .doc p, .doc ul, .doc ol { margin-bottom: 1rem; line-height: 1.65; }
    .doc ul { list-style: disc; padding-left: 1.5rem; }
    .doc ol { list-style: decimal; padding-left: 1.5rem; }
    .doc li { margin-bottom: 0.25rem; }
    .doc table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; }
    .doc th, .doc td { border: 1px solid #e2e8f0; padding: 0.5rem 0.75rem; text-align: left; font-size: 0.9rem; }
    html.dark .doc th, html.dark .doc td { border-color: #334155; }
    .doc th { background: #f8fafc; font-weight: 600; }
    html.dark .doc th { background: #1e293b; }
    .doc .tip { border-left: 4px solid #3b82f6; background: #eff6ff; padding: 0.75rem 1rem; border-radius: 0 0.25rem 0.25rem 0; margin: 1rem 0; }
    html.dark .doc .tip { background: #1e3a8a20; }
    .doc .warn { border-left: 4px solid #f59e0b; background: #fffbeb; padding: 0.75rem 1rem; border-radius: 0 0.25rem 0.25rem 0; margin: 1rem 0; }
    html.dark .doc .warn { background: #78350f20; }
</style>

<div class="doc max-w-4xl">
    <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100 mb-2">AI File Editing</h1>
    <p class="text-gray-600 dark:text-gray-400 mb-6">Chunked AI access to arbitrary text files (CSS, JS, JSON, etc.) with optimistic locking and automatic backups.</p>

    <h2>Why chunks?</h2>
    <p>
      Sending a whole 5000-line CSS file to the model every time it edits one rule is wasteful and slow. The MCP file tools work like Claude Code's <code>Edit</code> tool: the model reads a narrow line range, identifies what to change, and writes back only that range. The CMS verifies the range hasn't changed since the read (optimistic locking) and rejects the patch otherwise.
    </p>

    <h2>The 4 tools</h2>
    <table>
      <thead><tr><th>Tool</th><th>Purpose</th></tr></thead>
      <tbody>
        <tr><td><code>list_files</code></td><td>Discover paths in a directory. Whitelisted extensions only.</td></tr>
        <tr><td><code>read_file</code></td><td>Bounded read by line range. Default cap: 4000 chars, max 20000.</td></tr>
        <tr><td><code>search_in_file</code></td><td>Find text or regex matches. Returns line numbers + 240-char snippets.</td></tr>
        <tr><td><code>update_file_region</code></td><td>Patch a line range with optimistic locking + automatic backup.</td></tr>
      </tbody>
    </table>

    <h2>Workflow the model follows</h2>
    <pre><code>1. list_files(dir: "css", ext: "css")           # find the file
2. search_in_file(path: "css/style.css",
                  query: ".btn-primary")          # get line numbers
3. read_file(path: "css/style.css",
             start_line: 412, end_line: 430)     # read the surrounding region
4. update_file_region(
       path: "css/style.css",
       start_line: 412,
       end_line: 430,
       old_region: "&lt;exact 19 lines from step 3&gt;",
       new_region: "&lt;edited 19 lines&gt;")
</code></pre>

    <h2>Optimistic locking</h2>
    <p>
      <code>update_file_region</code> requires the model to send <code>old_region</code> — the exact bytes currently in [<code>start_line</code>, <code>end_line</code>]. The server re-reads the file and compares. If they differ, the patch is refused and the response includes <code>current_region</code> so the model can re-read and retry. Newlines are normalized to LF for the comparison, so CRLF-on-disk files don't fail clean LF patches.
    </p>

    <div class="warn">
      <strong>Spanning chunks?</strong> Not a thing. The model picks its own <code>start_line</code>/<code>end_line</code>, so if an edit needs more context the model reads (and patches) a wider range. There are no fixed chunk boundaries.
    </div>

    <h2>Automatic backups</h2>
    <p>
      Before every successful <code>update_file_region</code>, the CMS creates a backup. The logic mirrors <code>admin/file-edit.php</code> so manual and AI edits share one timeline:
    </p>
    <ul>
      <li>If the edited file IS a known CMS page (its path resolves to a registered <code>page_id</code>), the backup goes into <code>BackupManager</code> — the same per-page history shown in the page editor's Version History panel.</li>
      <li>Otherwise the backup lands at <code>backups_dir/_file_edits/&lt;relative-path&gt;/&lt;filename&gt;.&lt;YmdHis&gt;.bak</code>.</li>
    </ul>
    <p>
      If backup creation fails, the write is refused — no edits without a recovery path.
    </p>

    <h2>Safety</h2>
    <p>Path resolution and access rules mirror <code>admin/file-edit.php</code>:</p>
    <ul>
      <li><strong>Root sandbox.</strong> The resolved real path must live under <code>root_dir</code> — no <code>..</code> escapes.</li>
      <li><strong>Forbidden top-level dirs:</strong> <code>cms</code>, <code>.git</code>, <code>node_modules</code>, <code>vendor</code>.</li>
      <li><strong>No dotfiles.</strong> Any segment starting with <code>.</code> is rejected (no <code>.htaccess</code>, no <code>.env</code>).</li>
      <li><strong>Extension allowlist</strong> (narrower than the human file editor):
        <code>css</code>, <code>js</code>, <code>mjs</code>, <code>ts</code>, <code>jsx</code>, <code>tsx</code>,
        <code>html</code>, <code>htm</code>, <code>php</code>, <code>phtml</code>,
        <code>json</code>, <code>xml</code>, <code>yml</code>, <code>yaml</code>, <code>svg</code>,
        <code>md</code>, <code>markdown</code>, <code>txt</code>.
      </li>
      <li><strong>/cms tree is blocked</strong> even if symlinked in under a different name.</li>
    </ul>

    <h2>When to use blocks vs. file region tools</h2>
    <p>The AI editor's system prompt steers the model in this order:</p>
    <ol>
      <li><strong>Blocks first.</strong> If the change targets a CMS:BLOCK on a page (header, hero, CTA, etc.), use <code>list_blocks</code> / <code>search_blocks</code> / <code>update_block</code>. Block edits create a draft and go through Publish.</li>
      <li><strong>Page region fallback.</strong> If blocks don't cover the area but the file IS a CMS page, use <code>search_in_page</code> / <code>get_page_region</code> / <code>update_page_region</code>. Same draft/publish loop.</li>
      <li><strong>File region for everything else.</strong> Arbitrary CSS, JS, JSON, etc. — use the file tools described here. These write LIVE (no draft/publish loop).</li>
    </ol>

    <h2>Things that don't go through these tools</h2>
    <ul>
      <li><strong>Binaries</strong> (images, fonts, video) — use <code>upload_image</code> / <code>upload_file</code>.</li>
      <li><strong>Blog post body</strong> — use <code>update_post</code> on the collection item; it has its own draft/publish flow.</li>
      <li><strong>Page <code>&lt;head&gt;</code> meta</strong> — use <code>update_page_meta</code>; it handles JSON-LD and structured fields safely.</li>
    </ul>

    <h2>Manual editor</h2>
    <p>
      Humans still have <a href="/cms/admin/files.php" class="text-accent-600 dark:text-accent-400 hover:underline">/cms/admin/files.php</a> for direct edits via the file browser + ACE editor. The same backup rules apply there. Whichever tool you use — manual or AI — the per-page Version History (or <code>_file_edits/</code>) holds the previous state.
    </p>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
