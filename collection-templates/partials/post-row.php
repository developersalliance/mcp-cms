<?php
/**
 * Partial: one post's row on a collection list page.
 * Override at {site}/theme/collection-templates/partials/post-row.php.
 *
 * Vars: $post       (array)  the post, with '_author' populated
 *       $collection (array)  the collection config (base_path, label, ...)
 *       $baseUrl    (string) site base URL
 */
$post       = $post       ?? [];
$collection = $collection ?? [];
$baseUrl    = $baseUrl    ?? '';
$basePath   = trim($collection['base_path'] ?? 'blog', '/');
?>
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
