<?php
/**
 * Partial: related-posts section under a collection detail page.
 * Override at {site}/theme/collection-templates/partials/related-posts.php.
 *
 * Vars: $relatedPosts (array) list of ['url','title','published_at','excerpt'] rows
 */
$relatedPosts = $relatedPosts ?? [];
?>
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
