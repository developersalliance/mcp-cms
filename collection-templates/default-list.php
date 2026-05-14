<?php
/* Default blog list template. Copy to {collection}-list.php to customize per-collection. */

$collection  = $collection  ?? [];
$siteName    = $siteName    ?? '';
$baseUrl     = $baseUrl     ?? '';
$pagedPosts  = $pagedPosts  ?? [];
$pagination  = $pagination  ?? null;

$label       = $collection['label']     ?? 'Blog';
$basePath    = trim($collection['base_path'] ?? 'blog', '/');
$pageTitle   = $label . ($siteName ? ' — ' . $siteName : '');
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-white text-slate-800">

  <main class="max-w-3xl mx-auto px-6 py-12">
    <header class="mb-10">
      <h1 class="text-4xl font-bold text-slate-900"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></h1>
    </header>

    <?php if (empty($pagedPosts)): ?>
      <p class="text-slate-500">No posts yet.</p>
    <?php else: ?>
      <div class="space-y-8">
        <?php foreach ($pagedPosts as $post): ?>
          <?php
            $pTitle   = $post['title']        ?? 'Untitled';
            $pSlug    = $post['slug']         ?? '';
            $pDate    = $post['published_at'] ?? ($post['date'] ?? '');
            $pExcerpt = $post['excerpt']      ?? '';
            $pUrl     = '/' . $basePath . '/' . $pSlug . '/';
          ?>
          <article class="border-b border-slate-100 pb-8 last:border-0">
            <h2 class="text-2xl font-semibold text-slate-900 mb-2">
              <a href="<?= htmlspecialchars($pUrl, ENT_QUOTES, 'UTF-8') ?>"
                 class="hover:text-slate-600">
                <?= htmlspecialchars($pTitle, ENT_QUOTES, 'UTF-8') ?>
              </a>
            </h2>
            <?php if ($pDate): ?>
              <p class="text-sm text-slate-500 mb-3">
                <time datetime="<?= htmlspecialchars($pDate, ENT_QUOTES, 'UTF-8') ?>">
                  <?= htmlspecialchars(date('F j, Y', strtotime($pDate) ?: time()), ENT_QUOTES, 'UTF-8') ?>
                </time>
              </p>
            <?php endif; ?>
            <?php if ($pExcerpt): ?>
              <p class="text-slate-600"><?= htmlspecialchars($pExcerpt, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>

      <?php if ($pagination): ?>
        <?php
          $prev = method_exists($pagination, 'getPreviousPage') ? $pagination->getPreviousPage() : null;
          $next = method_exists($pagination, 'getNextPage') ? $pagination->getNextPage() : null;
        ?>
        <?php if ($prev || $next): ?>
          <nav class="mt-10 flex justify-between text-sm">
            <div>
              <?php if ($prev): ?>
                <a href="<?= htmlspecialchars($prev, ENT_QUOTES, 'UTF-8') ?>"
                   class="text-slate-600 hover:text-slate-900">&larr; Newer</a>
              <?php endif; ?>
            </div>
            <div>
              <?php if ($next): ?>
                <a href="<?= htmlspecialchars($next, ENT_QUOTES, 'UTF-8') ?>"
                   class="text-slate-600 hover:text-slate-900">Older &rarr;</a>
              <?php endif; ?>
            </div>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </main>

</body>
</html>
