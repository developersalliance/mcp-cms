<?php
/**
 * Partial: post byline (date + author) on a collection detail page.
 * Override at {site}/theme/collection-templates/partials/author-box.php.
 *
 * Vars: $author (?array) the resolved author record (name, ...), usually $post['_author']
 *       $post   (array)  the post (published_at/date, author fallback)
 */
$post       = $post ?? [];
$author     = $author ?? ($post['_author'] ?? null);
$published  = $post['published_at'] ?? ($post['date'] ?? '');
$authorName = $author['name'] ?? ($post['author'] ?? '');
?>
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
