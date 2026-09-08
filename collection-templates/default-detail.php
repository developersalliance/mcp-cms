<?php
/* Default blog detail template. Copy to {collection}-detail.php to customize per-collection.
 * Building blocks live in partials/ and render through Theme::partial(), so a site can
 * override one partial at {site}/theme/collection-templates/partials/ and keep the rest.
 * See docs/theming.md. */

if (!class_exists('Theme')) {
    require_once __DIR__ . '/../core/Theme.php';
}

$post         = $post         ?? [];
$collection   = $collection   ?? [];
$siteName     = $siteName     ?? '';
$baseUrl      = $baseUrl      ?? '';
$relatedPosts = $relatedPosts ?? [];

$title       = $post['title']          ?? 'Untitled';
$excerpt     = $post['excerpt']        ?? '';
$content     = $post['content']        ?? '';
$image       = $post['featured_image'] ?? '';
$published   = $post['published_at']   ?? ($post['date'] ?? '');
$authorName  = $post['_author']['name'] ?? ($post['author'] ?? '');
$basePath    = $collection['base_path'] ?? 'blog';

$pageTitle   = trim($title . ($siteName ? ' — ' . $siteName : ''));
$canonical   = $baseUrl ? rtrim($baseUrl, '/') . '/' . trim($basePath, '/') . '/' . ($post['slug'] ?? '') . '/' : '';
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <?php if ($excerpt): ?>
  <meta name="description" content="<?= htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8') ?>">
  <?php endif; ?>
  <?php if ($canonical): ?>
  <link rel="canonical" href="<?= htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') ?>">
  <?php endif; ?>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-white text-slate-800">

  <main class="max-w-3xl mx-auto px-6 py-12">
    <article class="prose prose-slate max-w-none">
      <h1 class="text-4xl font-bold text-slate-900 mb-3"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>

      <?php Theme::partial('author-box', ['author' => $post['_author'] ?? null, 'post' => $post]); ?>

      <?php if ($image): ?>
      <img src="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>"
           alt="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
           class="w-full h-auto rounded-lg mb-8">
      <?php endif; ?>

      <div class="prose prose-slate max-w-none">
        <?= $content /* sanitized at write time */ ?>
      </div>
    </article>

    <?php Theme::partial('related-posts', ['relatedPosts' => $relatedPosts]); ?>

    <div class="mt-12 pt-6 border-t border-slate-200">
      <a href="/<?= htmlspecialchars(trim($basePath, '/'), ENT_QUOTES, 'UTF-8') ?>/"
         class="text-slate-600 hover:text-slate-900 text-sm">&larr; Back to <?= htmlspecialchars($collection['label'] ?? 'blog', ENT_QUOTES, 'UTF-8') ?></a>
    </div>
  </main>

</body>
</html>
