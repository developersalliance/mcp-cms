<?php

require_once __DIR__ . '/../includes/auth-guard.php';

$pageTitle = 'Docs — Blocks';
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
</style>

<div class="max-w-4xl doc text-gray-800 dark:text-gray-200">

    <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100 mb-2">Blocks</h1>
    <p class="text-gray-600 dark:text-gray-400 mb-6">How editable regions work in this CMS.</p>

    <h2>What is a block?</h2>
    <p>A <strong>block</strong> is a named, editable region in a PHP or HTML file, delimited by marker comments. Everything between the start and end markers is what the CMS reads and writes. The rest of the file is never touched by the editor — blocks let you keep your template's structure, CSS, and scripts in code while letting non-developers edit the content.</p>

    <h2>Two equivalent marker syntaxes</h2>
    <p>Use PHP-style markers in <code>.php</code> files (invisible to the browser) or HTML-comment markers in <code>.html</code> files. Both forms parse identically.</p>

    <h3>PHP form</h3>
<pre><code>&lt;?php /* CMS:BLOCK name=hero start */ ?&gt;
  &lt;section class="hero"&gt;
    &lt;h1&gt;Welcome&lt;/h1&gt;
  &lt;/section&gt;
&lt;?php /* CMS:BLOCK name=hero end */ ?&gt;</code></pre>

    <h3>HTML form</h3>
<pre><code>&lt;!-- CMS:BLOCK name=hero start --&gt;
  &lt;section class="hero"&gt;
    &lt;h1&gt;Welcome&lt;/h1&gt;
  &lt;/section&gt;
&lt;!-- CMS:BLOCK name=hero end --&gt;</code></pre>

    <h2>Block attributes</h2>
    <table>
        <thead>
            <tr><th>Attribute</th><th>Required</th><th>Effect</th></tr>
        </thead>
        <tbody>
            <tr><td><code>name</code></td><td>Yes</td><td>Unique per page. Used everywhere: admin UI, MCP tools, backups, global sync.</td></tr>
            <tr><td><code>role</code></td><td>No</td><td>Optional semantic tag (e.g. <code>role=meta</code>). Currently informational; reserved for future filtering.</td></tr>
            <tr><td><code>custom=1</code></td><td>No</td><td>Locks this page's copy. Global sync skips it. Use for a page-specific variation of an otherwise shared block.</td></tr>
            <tr><td><code>system=1</code></td><td>No</td><td>Marks CMS-managed infrastructure. Parsed but not shown in normal edit flows.</td></tr>
        </tbody>
    </table>

    <p>The end marker only needs <code>name</code>:</p>
<pre><code>&lt;?php /* CMS:BLOCK name=hero end */ ?&gt;</code></pre>

    <h2>Global vs custom blocks</h2>
    <p>By default, blocks with the same <code>name</code> across pages are <strong>global</strong> — editing one propagates to all. Add <code>custom=1</code> to opt a page out of global sync.</p>

    <h3>Global (default)</h3>
    <p>When you edit a block named <code>footer</code> on <code>about.php</code>, the CMS rewrites the same <code>footer</code> block on every other page that has one (and isn't marked custom). One footer, N pages, one edit.</p>

    <h3>Custom</h3>
    <p>Mark a block <code>custom=1</code> to keep its content isolated from global sync. The admin and the MCP <code>find_and_replace_block_content</code> tool both honor this flag.</p>

<pre><code>&lt;?php /* CMS:BLOCK name=hero custom=1 start */ ?&gt;
  &lt;section&gt;Page-specific hero for this page only&lt;/section&gt;
&lt;?php /* CMS:BLOCK name=hero end */ ?&gt;</code></pre>

    <h2>Rules and gotchas</h2>
    <ol>
        <li><strong>Block names must be unique within a file.</strong> The parser treats duplicate names as an error.</li>
        <li><strong>No nesting.</strong> A block inside another block will parse as one block with malformed content. Keep blocks at sibling level.</li>
        <li><strong>No PHP control flow inside a block.</strong> <code>&lt;?php if ... ?&gt;</code> inside a block is treated as literal text when round-tripped through the admin editor. Put conditionals outside blocks.</li>
        <li><strong>Whitespace is preserved.</strong> Indentation and blank lines inside a block round-trip exactly.</li>
        <li><strong>Blocks are plain HTML strings.</strong> The CMS doesn't validate markup — malformed HTML in means malformed HTML out.</li>
    </ol>

    <h2>Worked example</h2>
<pre><code>&lt;!DOCTYPE html&gt;
&lt;html&gt;
&lt;body&gt;

  &lt;?php /* CMS:BLOCK name=header start */ ?&gt;
    &lt;header&gt;&lt;h1&gt;Site Name&lt;/h1&gt;&lt;/header&gt;
  &lt;?php /* CMS:BLOCK name=header end */ ?&gt;

  &lt;?php /* CMS:BLOCK name=hero custom=1 start */ ?&gt;
    &lt;section class="hero"&gt;Page-specific hero&lt;/section&gt;
  &lt;?php /* CMS:BLOCK name=hero end */ ?&gt;

  &lt;?php /* CMS:BLOCK name=footer start */ ?&gt;
    &lt;footer&gt;&amp;copy; 2026&lt;/footer&gt;
  &lt;?php /* CMS:BLOCK name=footer end */ ?&gt;

&lt;/body&gt;
&lt;/html&gt;</code></pre>
    <p>In this file: <code>header</code> and <code>footer</code> sync across every page that has them; <code>hero</code> stays local to this page.</p>

    <h2>Managing blocks</h2>

    <h3>Adding a block</h3>
    <p>Wrap any region of your template in matching start/end markers. Save the file and reload the admin — the block appears automatically in the page editor.</p>

    <h3>Removing a block</h3>
    <p>Delete both markers and the content between them. The CMS will forget about it on the next load.</p>

    <h3>Renaming a block</h3>
    <p>Currently a manual find/replace across your templates. Remember to rename both start and end markers, everywhere the block appears. A dedicated MCP tool is on the roadmap.</p>

    <h3>Finding the block list for a page</h3>
    <p>Open the page in the admin editor — every block is listed. Or, via MCP: call <code>list_blocks</code> with the page ID.</p>

    <h2>Backups and drafts</h2>
    <ul>
        <li>Every time you save a block edit, the CMS creates a per-page backup (see Settings → Backups).</li>
        <li>Global sync operations create a separate global backup so you can roll back a multi-page change in one step.</li>
        <li>Page edits are saved as drafts first; publishing writes to the live file.</li>
    </ul>

    <div class="tip">
        <strong>Tip:</strong> If you're converting an existing template, use the Import tool (coming soon) to wrap sections automatically, or add markers by hand using the syntax above.
    </div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
