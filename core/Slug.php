<?php
/**
 * Slug — single source of truth for slugification.
 *
 * Used by BlogManager, CategoryManager, AuthorManager, UploadManager (and
 * the rest, eventually). Each had a slightly different regex pre-audit
 * (some allowed underscores, some didn't; some capped length, some
 * didn't). Slug::make is the canonical form: lowercase, [a-z0-9] only,
 * non-conforming chars collapse to "-", repeated/edge "-" trimmed, capped
 * at $maxLen (default 60). Empty input + the "" result both fall back to
 * the caller-provided $fallback.
 */

class Slug
{
    public static function make(string $input, int $maxLen = 60, string $fallback = 'item'): string
    {
        $s = strtolower(trim($input));
        // Drop anything not alnum or "-" / "_"; "_" is rare on the web but
        // some legacy slugs use it, so allow + collapse afterwards.
        $s = preg_replace('/[^a-z0-9_\-]+/', '-', $s);
        $s = preg_replace('/-+/', '-', (string)$s);
        $s = trim((string)$s, '-_');
        if ($s === '') $s = $fallback;
        if ($maxLen > 0 && strlen($s) > $maxLen) {
            $s = rtrim(substr($s, 0, $maxLen), '-_');
        }
        return $s;
    }

    /**
     * Choose a slug that doesn't collide with any already in $taken.
     * Appends "-2", "-3", … until unique. $excludeId lets you skip a
     * specific row when re-slugging (so a record doesn't collide with
     * itself).
     */
    public static function unique(array $taken, string $slug, ?string $excludeId = null, string $idKey = 'id', string $slugKey = 'slug'): string
    {
        $used = [];
        foreach ($taken as $row) {
            if (is_array($row)) {
                if ($excludeId !== null && ($row[$idKey] ?? null) === $excludeId) continue;
                $used[$row[$slugKey] ?? ''] = true;
            } elseif (is_string($row)) {
                $used[$row] = true;
            }
        }
        if (!isset($used[$slug]) && $slug !== '') return $slug;
        $i = 2;
        while (isset($used[$slug . '-' . $i])) $i++;
        return $slug . '-' . $i;
    }
}
