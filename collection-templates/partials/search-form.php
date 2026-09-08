<?php
/**
 * Partial: search form + result-count line for collection list pages.
 * Override at {site}/theme/collection-templates/partials/search-form.php.
 *
 * Vars: $searchQuery (?string) active ?q= query, null when not searching
 *       $resultCount (int)     number of posts matched
 *       $listUrl     (string)  collection list URL the form submits to
 */
$searchQuery = $searchQuery ?? null;
$resultCount = $resultCount ?? 0;
$listUrl     = $listUrl     ?? '/';
?>
      <form method="get" action="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="mt-6 flex gap-2">
        <input type="search" name="q" value="<?= htmlspecialchars((string)($searchQuery ?? ''), ENT_QUOTES, 'UTF-8') ?>"
               placeholder="Search posts…"
               class="flex-1 px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:border-slate-400">
        <button type="submit" class="px-4 py-2 bg-slate-900 text-white rounded-lg text-sm hover:bg-slate-700">Search</button>
      </form>

      <?php if ($searchQuery !== null): ?>
        <p class="mt-4 text-sm text-slate-500">
          <?= $resultCount ?> result<?= $resultCount === 1 ? '' : 's' ?> for
          &lsquo;<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>&rsquo;
          &middot; <a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="underline hover:text-slate-700">Clear</a>
        </p>
      <?php endif; ?>
