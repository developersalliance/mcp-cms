# Theming: site overrides without forking the engine

The engine stays a clean git checkout on every install. Anything visual you
want to change lives in your site's `theme/` directory, next to the engine
directory, never inside it.

## The resolve chain

Every themed include goes through `Theme::resolve($relPath)`:

1. `{site}/theme/{relPath}` (your override, wins if the file exists)
2. `{engine}/{relPath}` (the shipped default)

`Theme::init($rootDir, $cmsDir)` is called by the engine bootstrap; templates
also self-load `core/Theme.php`, so rendering works even from a bare harness
(with the engine directory inferred and no site overrides).

## Partial overrides (recommended)

The default collection templates are assembled from small partials in
`{engine}/collection-templates/partials/`:

| Partial | Renders | Vars |
|---|---|---|
| `search-form` | list page search box + result count | `$searchQuery`, `$resultCount`, `$listUrl` |
| `post-row` | one post card on the list page | `$post` (with `_author`), `$collection`, `$baseUrl` |
| `pagination` | newer/older links under the list | `$pagination`, `$listUrl` |
| `author-box` | byline (date + author) on the detail page | `$author`, `$post` |
| `related-posts` | related posts section on the detail page | `$relatedPosts` |

To restyle one of them, copy it into your theme and edit the copy:

```sh
mkdir -p theme/collection-templates/partials
cp cms/collection-templates/partials/post-row.php theme/collection-templates/partials/
```

(`cms/` here is whatever your engine directory is called.) Only that partial
changes; every other partial and the surrounding template keep receiving
engine improvements on the next `git pull`. Partials receive everything they
need through their vars; do not reach for globals in an override.

### The @default-md5 marker

Stamp the engine default's md5 into the first lines of your copy so the drift
doctor can tell you when the default has moved on:

```php
<?php /* @default-md5:d9f6ccd07fab2d24007c5c7b164af26a */ ?>
```

The line renders nothing. Get the hash from `php tests/theme-diff.php` output
(the engine md5 of the file you copied), or stamp it at copy time:

```sh
{ printf '<?php /* @default-md5:%s */ ?>\n' "$(md5 -q cms/collection-templates/partials/post-row.php)";
  cat cms/collection-templates/partials/post-row.php; } > theme/collection-templates/partials/post-row.php
```

(Linux: `md5sum file | cut -d' ' -f1` instead of `md5 -q file`.)

## Full-file overrides (still supported)

Dropping a whole template at `theme/collection-templates/default-list.php`
(or `{collection}-list.php` / `-detail.php`) still works and wins over the
engine copy. It also freezes that page at the feature level of the day you
copied it: when the engine default later gains something, your copy does not.
Prefer partial overrides; keep full-file overrides for layouts that truly
share nothing with the default.

## The drift doctor

```sh
php tests/theme-diff.php            # site root assumed to be the engine dir's parent
php tests/theme-diff.php /path/to/site-root
```

For every file under `{site}/theme/` it prints the engine file it shadows,
both mtimes and md5s, and flags:

- `DRIFT`: the engine default is newer than your override; diff them and port
  what you want.
- `DEFAULT CHANGED since copy`: your `@default-md5` marker no longer matches
  the engine file, so the default has changed since you copied it (regardless
  of mtimes, which git checkouts often reset).

Output is informational and the script always exits 0.
