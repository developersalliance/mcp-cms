<?php
/* Default blog list template. Copy to {collection}-list.php to customize per-collection.
 * Building blocks live in partials/ and render through Theme::partial(), so a site can
 * override one partial at {site}/theme/collection-templates/partials/ and keep the rest.
 * See docs/theming.md. */

if (!class_exists('Theme')) {
    require_once __DIR__ . '/../core/Theme.php';
}

$collection      = $collection      ?? [];
$siteName        = $siteName        ?? '';
$baseUrl         = $baseUrl         ?? '';
$pagedPosts      = $pagedPosts      ?? [];
$pagination      = $pagination      ?? null;
$searchQuery     = $searchQuery     ?? null;
$archiveCategory = $archiveCategory ?? null;

$label       = $collection['label']     ?? 'Blog';
$basePath    = trim($collection['base_path'] ?? 'blog', '/');
$listUrl     = '/' . $basePath . '/';
$heading     = $label;
if ($searchQuery !== null) {
    $heading = 'Search: ' . $searchQuery;
} elseif (!empty($archiveCategory['name'])) {
    $heading = 'Category: ' . $archiveCategory['name'];
}
$pageTitle   = $heading . ($siteName ? ' — ' . $siteName : '');
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
      <h1 class="text-4xl font-bold text-slate-900"><?= htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') ?></h1>

<?php Theme::partial('search-form', ['searchQuery' => $searchQuery, 'resultCount' => count($pagedPosts), 'listUrl' => $listUrl]); ?>
    </header>

    <?php if (empty($pagedPosts)): ?>
      <p class="text-slate-500"><?= $searchQuery !== null ? 'No matching posts.' : 'No posts yet.' ?></p>
    <?php else: ?>
      <div class="space-y-8">
        <?php foreach ($pagedPosts as $post): ?>
<?php Theme::partial('post-row', ['post' => $post, 'collection' => $collection, 'baseUrl' => $baseUrl]); ?>
        <?php endforeach; ?>
      </div>

      <?php Theme::partial('pagination', ['pagination' => $pagination, 'listUrl' => $listUrl]); ?>
    <?php endif; ?>
  </main>

</body>
</html>
