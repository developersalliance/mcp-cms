<?php
/**
 * Categories AJAX API. All routes are POST + CSRF except `list`.
 *
 * Endpoints (?action=...):
 *  - list         GET/POST  → { categories: [...], etag }
 *  - create       POST      → { category, etag }
 *  - update       POST      → { category, etag }
 *  - delete       POST      → { deleted, promoted, posts_touched, etag }
 *  - reorder      POST      → { etag }   body: parent_id, ids[]
 *  - move         POST      → { category, etag }   body: id, parent_id, index
 *
 * Write routes require an `if_match` field carrying the etag the caller
 * just read. Mismatch responds with 409 + { error, etag } so the UI can
 * resync.
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/CSRF.php';
require_once __DIR__ . '/../core/CategoryManager.php';
require_once __DIR__ . '/../core/BlogManager.php';

$config = require __DIR__ . '/../config/config.php';
header('Content-Type: application/json');

function ok($body) { echo json_encode(['success' => true] + $body); exit; }
function fail($code, $msg, $extra = []) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg] + $extra);
    exit;
}

$action = (string)($_REQUEST['action'] ?? '');
$collectionId = (string)($_REQUEST['collection_id'] ?? '');
if ($collectionId === '' || !preg_match('/^[a-z][a-z0-9_\-]{0,40}$/', $collectionId)) {
    fail(400, 'Invalid collection_id');
}

$cm = new CategoryManager($config['cms_dir']);
$bm = new BlogManager($config['root_dir'], $config['cms_dir']);

// Reads are open
if ($action === 'list') {
    $read = $cm->read($collectionId);
    ok(['categories' => $read['list'], 'tree' => $cm->tree($collectionId), 'etag' => $read['etag']]);
}

// Everything below mutates
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'POST only');
CSRF::verifyOrDie();
$ifMatch = (string)($_POST['if_match'] ?? '');

try {
    if ($action === 'create') {
        $name = (string)($_POST['name'] ?? '');
        if (trim($name) === '') fail(400, 'name is required');
        $r = $cm->create($collectionId, [
            'name'        => ['default' => $name, 'locales' => new stdClass()],
            'parent_id'   => $_POST['parent_id'] ?? null,
            'description' => $_POST['description'] ?? '',
            'slug'        => $_POST['slug'] ?? '',
        ], $ifMatch);
        ok(['category' => $r['result'], 'etag' => $r['etag'], 'list' => $r['list']]);
    }

    if ($action === 'update') {
        $id = (string)($_POST['id'] ?? '');
        $fields = [];
        if (array_key_exists('name', $_POST)) {
            // Accept either flat string or JSON object
            $rawName = $_POST['name'];
            if (is_string($rawName) && str_starts_with(trim($rawName), '{')) {
                $decoded = json_decode($rawName, true);
                $fields['name'] = is_array($decoded) ? $decoded : ['default' => (string)$rawName, 'locales' => new stdClass()];
            } else {
                $fields['name'] = ['default' => (string)$rawName, 'locales' => new stdClass()];
            }
        }
        if (array_key_exists('slug', $_POST))        $fields['slug'] = $_POST['slug'];
        if (array_key_exists('description', $_POST)) $fields['description'] = $_POST['description'];
        if (array_key_exists('parent_id', $_POST))   $fields['parent_id'] = $_POST['parent_id'];
        $r = $cm->update($collectionId, $id, $fields, $ifMatch, $bm);
        ok(['category' => $r['result'], 'etag' => $r['etag'], 'list' => $r['list']]);
    }

    if ($action === 'delete') {
        $id = (string)($_POST['id'] ?? '');
        $r = $cm->delete($collectionId, $id, $ifMatch, $bm);
        ok(['deleted' => $r['result']['deleted'], 'promoted' => $r['result']['promoted'], 'posts_touched' => $r['posts_touched'], 'etag' => $r['etag'], 'list' => $r['list']]);
    }

    if ($action === 'reorder') {
        $parentId = $_POST['parent_id'] ?? null;
        if ($parentId === '') $parentId = null;
        $ids = json_decode((string)($_POST['ids'] ?? '[]'), true);
        if (!is_array($ids)) fail(400, 'ids must be a JSON array');
        $r = $cm->reorder($collectionId, $parentId, $ids, $ifMatch);
        ok(['etag' => $r['etag'], 'list' => $r['list']]);
    }

    if ($action === 'move') {
        $id = (string)($_POST['id'] ?? '');
        $parent = $_POST['parent_id'] ?? null;
        if ($parent === '') $parent = null;
        $index = (int)($_POST['index'] ?? 0);
        $r = $cm->move($collectionId, $id, $parent, $index, $ifMatch);
        ok(['category' => $r['result'], 'etag' => $r['etag'], 'list' => $r['list']]);
    }

    fail(400, 'Unknown action');
} catch (CategoryConflictException $e) {
    $read = $cm->read($collectionId);
    fail(409, $e->getMessage(), ['etag' => $read['etag'], 'list' => $read['list']]);
} catch (Throwable $e) {
    fail(400, $e->getMessage());
}
