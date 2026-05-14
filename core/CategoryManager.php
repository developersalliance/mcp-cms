<?php
/**
 * CategoryManager — hierarchical blog categories per collection.
 *
 * Storage: cms/content/{collection}/_categories.json — a flat array of
 * category records. Tree structure is derived from parent_id at read.
 *
 * Record shape:
 *   { id, slug, name: { default, locales: { lc: name } },
 *     description, parent_id, sort_order }
 *
 * - id   = opaque "cat_" + 6 hex chars. Immutable. Posts reference id.
 * - slug = mutable (URL-facing). Unique within the collection.
 * - name = locale-keyed; "default" is the canonical, "locales.{lc}" optional.
 *
 * Writes use flock() to serialise concurrent edits. read() returns an etag
 * (sha1 of the raw JSON); write() requires If-Match equal to the etag of
 * the version the caller saw — mismatch raises CategoryConflictException so
 * the admin UI can surface "tree changed, refresh."
 */

class CategoryConflictException extends Exception {}

class CategoryManager
{
    private string $contentDir;

    public function __construct(string $cmsDir)
    {
        $this->contentDir = rtrim($cmsDir, '/') . '/content';
    }

    private function pathFor(string $collectionId): string
    {
        return $this->contentDir . '/' . $collectionId . '/_categories.json';
    }

    /** Read raw list + etag. Creates the file if missing. */
    public function read(string $collectionId): array
    {
        $path = $this->pathFor($collectionId);
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        if (!file_exists($path)) {
            file_put_contents($path, "[]\n");
        }
        $raw = (string)file_get_contents($path);
        $list = json_decode($raw, true);
        if (!is_array($list)) $list = [];
        return ['list' => $list, 'etag' => sha1($raw)];
    }

    public function list(string $collectionId): array
    {
        return $this->read($collectionId)['list'];
    }

    /** Build a nested tree: each node gets a `children` key. */
    public function tree(string $collectionId): array
    {
        $list = $this->list($collectionId);
        return $this->treeOf($list);
    }

    private function treeOf(array $list): array
    {
        $byParent = [];
        foreach ($list as $cat) {
            $byParent[$cat['parent_id'] ?? ''][] = $cat;
        }
        foreach ($byParent as &$bucket) {
            usort($bucket, fn($a, $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));
        }
        unset($bucket);

        $build = function ($parentId) use (&$build, $byParent) {
            $key = $parentId ?? '';
            $out = [];
            foreach ($byParent[$key] ?? [] as $node) {
                $node['children'] = $build($node['id']);
                $out[] = $node;
            }
            return $out;
        };
        return $build(null);
    }

    /**
     * Create a new category. Returns the new record.
     *
     * @throws CategoryConflictException on etag mismatch
     */
    public function create(string $collectionId, array $fields, string $ifMatch = ''): array
    {
        return $this->mutate($collectionId, $ifMatch, function (array &$list) use ($fields) {
            $name = is_array($fields['name'] ?? null)
                ? $fields['name']
                : ['default' => (string)($fields['name'] ?? 'Untitled'), 'locales' => new stdClass()];
            $parentId = isset($fields['parent_id']) && $fields['parent_id'] !== '' ? (string)$fields['parent_id'] : null;
            if ($parentId !== null && !$this->existsIn($list, $parentId)) {
                throw new Exception('Parent category not found: ' . $parentId);
            }
            $slugBase = $this->slugify($fields['slug'] ?? ($name['default'] ?? 'item'));
            $slug = $this->uniqueSlug($list, $slugBase);
            $sortOrder = $this->maxSortOrder($list, $parentId) + 1;
            $record = [
                'id'          => $this->randomId(),
                'slug'        => $slug,
                'name'        => $name,
                'description' => (string)($fields['description'] ?? ''),
                'parent_id'   => $parentId,
                'sort_order'  => $sortOrder,
            ];
            $list[] = $record;
            return $record;
        });
    }

    /**
     * Patch a category's mutable fields (slug, name, description, parent_id).
     * On parent change, performs a cycle check. On name change, sweeps the
     * collection's posts to refresh name_snapshot for matching id refs.
     */
    public function update(string $collectionId, string $id, array $fields, string $ifMatch = '', ?BlogManager $blogManager = null): array
    {
        return $this->mutate($collectionId, $ifMatch, function (array &$list) use ($id, $fields, $collectionId, $blogManager) {
            $idx = $this->indexOf($list, $id);
            if ($idx === null) throw new Exception('Category not found: ' . $id);
            $cat = $list[$idx];
            $changedName = false;

            if (array_key_exists('slug', $fields)) {
                $newSlug = $this->slugify((string)$fields['slug']);
                if ($newSlug === '') throw new Exception('Slug cannot be empty');
                if ($newSlug !== $cat['slug']) {
                    $cat['slug'] = $this->uniqueSlug($list, $newSlug, $id);
                }
            }
            if (array_key_exists('name', $fields)) {
                $name = is_array($fields['name']) ? $fields['name'] : ['default' => (string)$fields['name'], 'locales' => new stdClass()];
                if (!isset($name['default']) || trim((string)$name['default']) === '') {
                    throw new Exception('Default name cannot be empty');
                }
                if (json_encode($name) !== json_encode($cat['name'])) $changedName = true;
                $cat['name'] = $name;
            }
            if (array_key_exists('description', $fields)) {
                $cat['description'] = (string)$fields['description'];
            }
            if (array_key_exists('parent_id', $fields)) {
                $newParent = $fields['parent_id'] === '' || $fields['parent_id'] === null ? null : (string)$fields['parent_id'];
                if ($newParent !== null) {
                    if (!$this->existsIn($list, $newParent)) throw new Exception('Parent category not found: ' . $newParent);
                    if ($this->wouldCycle($list, $id, $newParent)) {
                        throw new Exception('Re-parent would create a cycle');
                    }
                }
                if ($cat['parent_id'] !== $newParent) {
                    $cat['parent_id'] = $newParent;
                    $cat['sort_order'] = $this->maxSortOrder($list, $newParent) + 1;
                }
            }
            $list[$idx] = $cat;

            // Sweep posts to refresh name_snapshot on rename
            if ($changedName && $blogManager) {
                $this->sweepNameSnapshot($collectionId, $id, $cat, $blogManager);
            }
            return $cat;
        });
    }

    /**
     * Delete a category; children promote to deleted node's parent
     * (sort_order appended). Also strips the deleted category from every
     * post in the collection.
     *
     * @return array{deleted: array, promoted: int, posts_touched: int}
     */
    public function delete(string $collectionId, string $id, string $ifMatch = '', ?BlogManager $blogManager = null): array
    {
        $result = $this->mutate($collectionId, $ifMatch, function (array &$list) use ($id) {
            $idx = $this->indexOf($list, $id);
            if ($idx === null) throw new Exception('Category not found: ' . $id);
            $deleted = $list[$idx];
            $promoted = 0;
            $newParent = $deleted['parent_id'];
            $maxOrder = $this->maxSortOrder($list, $newParent);
            foreach ($list as &$cat) {
                if (($cat['parent_id'] ?? null) === $id) {
                    $maxOrder++;
                    $cat['parent_id'] = $newParent;
                    $cat['sort_order'] = $maxOrder;
                    $promoted++;
                }
            }
            unset($cat);
            array_splice($list, $idx, 1);
            return ['deleted' => $deleted, 'promoted' => $promoted];
        });

        $touched = 0;
        if ($blogManager) {
            $touched = $this->stripFromPosts($collectionId, $id, $blogManager);
        }
        $result['posts_touched'] = $touched;
        return $result;
    }

    /**
     * Reorder siblings under a parent. Caller passes the full ordered id
     * list for that parent; mismatched ids are ignored.
     */
    public function reorder(string $collectionId, ?string $parentId, array $orderedIds, string $ifMatch = ''): array
    {
        return $this->mutate($collectionId, $ifMatch, function (array &$list) use ($parentId, $orderedIds) {
            $orderMap = array_flip($orderedIds);
            $maxKnown = count($orderedIds);
            foreach ($list as &$cat) {
                if (($cat['parent_id'] ?? null) === $parentId) {
                    if (isset($orderMap[$cat['id']])) {
                        $cat['sort_order'] = $orderMap[$cat['id']];
                    } else {
                        $cat['sort_order'] = $maxKnown++; // unknown siblings to the end
                    }
                }
            }
            unset($cat);
            return null;
        });
    }

    /**
     * Move a node to a new parent at a specific insert index. Cycle check.
     */
    public function move(string $collectionId, string $id, ?string $newParentId, int $insertIndex, string $ifMatch = ''): array
    {
        return $this->mutate($collectionId, $ifMatch, function (array &$list) use ($id, $newParentId, $insertIndex) {
            $idx = $this->indexOf($list, $id);
            if ($idx === null) throw new Exception('Category not found: ' . $id);
            if ($newParentId !== null) {
                if (!$this->existsIn($list, $newParentId)) throw new Exception('Parent category not found: ' . $newParentId);
                if ($this->wouldCycle($list, $id, $newParentId)) throw new Exception('Move would create a cycle');
            }
            $list[$idx]['parent_id'] = $newParentId;
            // Build new sibling order: current siblings minus the moved node, then insert it at index
            $siblings = [];
            foreach ($list as $cat) {
                if ($cat['id'] === $id) continue;
                if (($cat['parent_id'] ?? null) === $newParentId) $siblings[] = $cat;
            }
            usort($siblings, fn($a, $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));
            $insertIndex = max(0, min($insertIndex, count($siblings)));
            $sortedIds = array_column($siblings, 'id');
            array_splice($sortedIds, $insertIndex, 0, $id);
            $orderMap = array_flip($sortedIds);
            foreach ($list as &$cat) {
                if (($cat['parent_id'] ?? null) === $newParentId && isset($orderMap[$cat['id']])) {
                    $cat['sort_order'] = $orderMap[$cat['id']];
                }
            }
            unset($cat);
            return $list[$idx];
        });
    }

    /** Returns category record by id, or null. */
    public function getById(string $collectionId, string $id): ?array
    {
        foreach ($this->list($collectionId) as $cat) {
            if ($cat['id'] === $id) return $cat;
        }
        return null;
    }

    /** Returns category record by slug, or null. */
    public function getBySlug(string $collectionId, string $slug): ?array
    {
        foreach ($this->list($collectionId) as $cat) {
            if ($cat['slug'] === $slug) return $cat;
        }
        return null;
    }

    public function displayName(array $cat, string $locale = 'default'): string
    {
        if ($locale !== 'default' && !empty($cat['name']['locales'][$locale])) {
            return (string)$cat['name']['locales'][$locale];
        }
        return (string)($cat['name']['default'] ?? '');
    }

    // -- private --

    /**
     * Wraps a mutation in flock + etag check + atomic write.
     */
    private function mutate(string $collectionId, string $ifMatch, callable $fn)
    {
        $path = $this->pathFor($collectionId);
        if (!is_dir(dirname($path))) @mkdir(dirname($path), 0775, true);
        $fp = fopen($path, 'c+');
        if (!$fp) throw new Exception('Cannot open categories file');
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            throw new Exception('Cannot lock categories file');
        }
        try {
            rewind($fp);
            $raw = stream_get_contents($fp);
            $raw = $raw === false ? '' : $raw;
            $list = json_decode($raw, true);
            if (!is_array($list)) $list = [];
            $currentEtag = sha1($raw);
            if ($ifMatch !== '' && $ifMatch !== $currentEtag) {
                throw new CategoryConflictException('etag mismatch: tree changed, refresh and try again');
            }
            $result = $fn($list);
            $newRaw = json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($newRaw === false) throw new Exception('Failed to encode categories');
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $newRaw . "\n");
            fflush($fp);
            return ['result' => $result, 'etag' => sha1($newRaw . "\n"), 'list' => $list];
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function randomId(): string
    {
        return 'cat_' . bin2hex(random_bytes(3));
    }

    private function slugify(string $s): string
    {
        require_once __DIR__ . '/Slug.php';
        return Slug::make($s, 60, 'category');
    }

    private function uniqueSlug(array $list, string $slug, ?string $excludeId = null): string
    {
        $taken = [];
        foreach ($list as $cat) {
            if ($cat['id'] === $excludeId) continue;
            $taken[$cat['slug']] = true;
        }
        if (!isset($taken[$slug])) return $slug;
        $i = 2;
        while (isset($taken[$slug . '-' . $i])) $i++;
        return $slug . '-' . $i;
    }

    private function indexOf(array $list, string $id): ?int
    {
        foreach ($list as $i => $cat) {
            if ($cat['id'] === $id) return $i;
        }
        return null;
    }

    private function existsIn(array $list, string $id): bool
    {
        return $this->indexOf($list, $id) !== null;
    }

    private function maxSortOrder(array $list, ?string $parentId): int
    {
        $max = -1;
        foreach ($list as $cat) {
            if (($cat['parent_id'] ?? null) === $parentId) {
                $max = max($max, (int)($cat['sort_order'] ?? 0));
            }
        }
        return $max;
    }

    /** Does re-parenting $id under $newParent create a cycle? */
    private function wouldCycle(array $list, string $id, string $newParent): bool
    {
        if ($id === $newParent) return true;
        $cur = $newParent;
        $guard = 0;
        while ($cur !== null && $guard++ < 1000) {
            if ($cur === $id) return true;
            $idx = $this->indexOf($list, $cur);
            if ($idx === null) return false;
            $cur = $list[$idx]['parent_id'] ?? null;
        }
        return false;
    }

    private function sweepNameSnapshot(string $collectionId, string $catId, array $cat, BlogManager $blogManager): void
    {
        $newName = $this->displayName($cat);
        foreach ($blogManager->listPosts($collectionId, []) as $p) {
            $touched = false;
            $cats = $p['categories'] ?? [];
            foreach ($cats as &$c) {
                if (is_array($c) && ($c['id'] ?? '') === $catId && ($c['name_snapshot'] ?? '') !== $newName) {
                    $c['name_snapshot'] = $newName;
                    $c['slug'] = $cat['slug'];
                    $touched = true;
                }
            }
            unset($c);
            if ($touched) {
                $p['categories'] = $cats;
                $blogManager->savePost($collectionId, $p['slug'], $p);
            }
        }
    }

    private function stripFromPosts(string $collectionId, string $catId, BlogManager $blogManager): int
    {
        $count = 0;
        foreach ($blogManager->listPosts($collectionId, []) as $p) {
            $cats = $p['categories'] ?? [];
            $kept = [];
            $touched = false;
            foreach ($cats as $c) {
                if (is_array($c) && ($c['id'] ?? '') === $catId) {
                    $touched = true;
                    continue;
                }
                $kept[] = $c;
            }
            if ($touched) {
                $p['categories'] = $kept;
                $blogManager->savePost($collectionId, $p['slug'], $p);
                $count++;
            }
        }
        return $count;
    }
}
