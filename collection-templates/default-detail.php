<?php
/* Default blog detail template. Copy to {collection}-detail.php to customize per-collection. */

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

      <?php if ($published || $authorName): ?>
      <p class="text-sm text-slate-500 mb-8">
        <?php if ($published): ?>
          <time datetime="<?= htmlspecialchars($published, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars(date('F j, Y', strtotime($published) ?: time()), ENT_QUOTES, 'UTF-8') ?>
          </time>
        <?php endif; ?>
        <?php if ($published && $authorName): ?> &middot; <?php endif; ?>
        <?php if ($authorName): ?>
          by <?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?>
        <?php endif; ?>
      </p>
      <?php endif; ?>

      <?php if ($image): ?>
      <img src="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>"
           alt="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
           class="w-full h-auto rounded-lg mb-8">
      <?php endif; ?>

      <div class="prose prose-slate max-w-none">
        <?= $content /* sanitized at write time */ ?>
      </div>
    </article>

    <?php if (!empty($relatedPosts)): ?>
    <section class="mt-12 pt-8 border-t border-slate-200">
      <h2 class="text-xl font-semibold text-slate-900 mb-6">Related posts</h2>
      <div class="space-y-6">
        <?php foreach ($relatedPosts as $rel): ?>
          <article>
            <h3 class="text-lg font-medium text-slate-900">
              <a href="<?= htmlspecialchars($rel['url'] ?? '#', ENT_QUOTES, 'UTF-8') ?>"
                 class="hover:text-slate-600">
                <?= htmlspecialchars($rel['title'] ?? 'Untitled', ENT_QUOTES, 'UTF-8') ?>
              </a>
            </h3>
            <?php if (!empty($rel['published_at'])): ?>
              <p class="text-xs text-slate-500 mt-1">
                <time datetime="<?= htmlspecialchars($rel['published_at'], ENT_QUOTES, 'UTF-8') ?>">
                  <?= htmlspecialchars(date('F j, Y', strtotime($rel['published_at']) ?: time()), ENT_QUOTES, 'UTF-8') ?>
                </time>
              </p>
            <?php endif; ?>
            <?php if (!empty($rel['excerpt'])): ?>
              <p class="text-sm text-slate-600 mt-1"><?= htmlspecialchars($rel['excerpt'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <div class="mt-12 pt-6 border-t border-slate-200">
      <a href="/<?= htmlspecialchars(trim($basePath, '/'), ENT_QUOTES, 'UTF-8') ?>/"
         class="text-slate-600 hover:text-slate-900 text-sm">&larr; Back to <?= htmlspecialchars($collection['label'] ?? 'blog', ENT_QUOTES, 'UTF-8') ?></a>
    </div>
  </main>

</body>
</html>
