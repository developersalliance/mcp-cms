<?php
/**
 * Partial: newer/older pagination links under a collection list.
 * Override at {site}/theme/collection-templates/partials/pagination.php.
 *
 * Vars: $pagination (?object) paginator exposing getPreviousPage()/getNextPage(), or null
 *       $listUrl    (string)  collection list URL
 */
$pagination = $pagination ?? null;
$listUrl    = $listUrl    ?? '/';
?>
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
