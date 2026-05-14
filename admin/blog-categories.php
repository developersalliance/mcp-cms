<?php
require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/CSRF.php';
require_once __DIR__ . '/../core/BlogManager.php';
require_once __DIR__ . '/../core/CategoryManager.php';

$config = require __DIR__ . '/../config/config.php';
$blog = new BlogManager($config['root_dir'], $config['cms_dir']);
$cm   = new CategoryManager($config['cms_dir']);

$collections = $blog->getCollections();
$collectionId = (string)($_GET['collection'] ?? ($collections[0]['id'] ?? ''));
$collection = $collectionId ? $blog->getCollection($collectionId) : null;

$pageTitle = 'Categories' . ($collection ? ' — ' . $collection['label'] : '');
$activePage = 'blog';
$csrf = CSRF::getToken() ?? CSRF::generateToken();

require __DIR__ . '/includes/header.php';

if (!$collection) {
    echo '<div class="bg-yellow-50 border-l-4 border-yellow-500 p-4 mb-6"><p class="text-yellow-800">No collection found. Create one first in <a href="/cms/admin/collections.php" class="underline">Manage Collections</a>.</p></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$initial = $cm->read($collectionId);
?>

<div class="mb-6 flex items-center justify-between">
    <div>
        <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100"><?php echo htmlspecialchars($collection['label']); ?> — Categories</h1>
        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Drag to reorder. Drop onto a node to re-parent. Depth cap: 3 levels — use the Move dropdown for deeper rearrangements.</p>
    </div>
    <?php if (count($collections) > 1): ?>
    <select onchange="location.href='/cms/admin/blog-categories.php?collection=' + this.value"
            class="px-3 py-2 bg-white dark:bg-dark-300 border border-gray-300 dark:border-dark-200 rounded-md text-sm">
        <?php foreach ($collections as $c): ?>
        <option value="<?php echo htmlspecialchars($c['id']); ?>" <?php echo $c['id'] === $collectionId ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['label']); ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
</div>

<div x-data="categoriesPage()" x-init="init()" class="space-y-6">
    <!-- Add new -->
    <div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 p-5">
        <div class="flex gap-2 items-end">
            <div class="flex-1">
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">New category name</label>
                <input type="text" x-model="newName" @keydown.enter.prevent="addNew()" placeholder="e.g. Engineering"
                       class="w-full px-3 py-2 bg-surface-50 dark:bg-dark-300 border border-surface-200 dark:border-dark-200 rounded-lg text-sm focus:border-accent-500 transition">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">Parent</label>
                <select x-model="newParent" class="px-3 py-2 bg-surface-50 dark:bg-dark-300 border border-surface-200 dark:border-dark-200 rounded-lg text-sm">
                    <option value="">(top level)</option>
                    <template x-for="opt in flatOptions()" :key="opt.id">
                        <option :value="opt.id" x-text="opt.label"></option>
                    </template>
                </select>
            </div>
            <button type="button" @click="addNew()" :disabled="busy"
                    class="px-4 py-2 bg-accent-600 hover:bg-accent-700 text-white text-sm font-medium rounded-lg disabled:opacity-50">Add</button>
        </div>
        <p x-show="error" x-text="error" x-cloak class="text-sm text-red-600 mt-2"></p>
    </div>

    <!-- Tree -->
    <div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 p-5">
        <ul id="cat-root" data-parent-id="" class="space-y-1"></ul>
        <p x-show="tree.length === 0" class="text-sm text-gray-500 dark:text-gray-400">No categories yet. Add one above.</p>
    </div>
</div>

<!-- Edit modal -->
<div x-data="{}" x-show="$store.cat.editing" x-cloak @keydown.escape.window="$store.cat.closeEdit()"
     class="fixed inset-0 z-50 bg-black/50 flex items-start justify-center p-6 overflow-y-auto">
    <div class="bg-white dark:bg-dark-400 rounded-2xl shadow-2xl w-full max-w-lg my-8" @click.outside="$store.cat.closeEdit()">
        <div class="flex items-center justify-between p-5 border-b border-surface-200 dark:border-dark-200">
            <h3 class="font-semibold text-gray-900 dark:text-white">Edit category</h3>
            <button type="button" @click="$store.cat.closeEdit()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <div class="p-5 space-y-4">
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">Name</label>
                <input type="text" x-model="$store.cat.edit.name"
                       class="w-full px-3 py-2 bg-surface-50 dark:bg-dark-300 border border-surface-200 dark:border-dark-200 rounded-lg text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">Slug</label>
                <input type="text" x-model="$store.cat.edit.slug"
                       class="w-full px-3 py-2 bg-surface-50 dark:bg-dark-300 border border-surface-200 dark:border-dark-200 rounded-lg text-sm font-mono">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">Parent (move to)</label>
                <select x-model="$store.cat.edit.parent_id"
                        class="w-full px-3 py-2 bg-surface-50 dark:bg-dark-300 border border-surface-200 dark:border-dark-200 rounded-lg text-sm">
                    <option value="">(top level)</option>
                    <template x-for="opt in $store.cat.flatOptions($store.cat.edit.id)" :key="opt.id">
                        <option :value="opt.id" x-text="opt.label"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">Description</label>
                <textarea x-model="$store.cat.edit.description" rows="3"
                          class="w-full px-3 py-2 bg-surface-50 dark:bg-dark-300 border border-surface-200 dark:border-dark-200 rounded-lg text-sm"></textarea>
            </div>
            <p x-show="$store.cat.editError" x-text="$store.cat.editError" x-cloak class="text-sm text-red-600"></p>
            <div class="flex justify-between pt-2">
                <button type="button" @click="$store.cat.deleteEdited()" class="px-3 py-2 bg-red-50 hover:bg-red-100 text-red-700 text-sm rounded-lg">Delete…</button>
                <div class="flex gap-2">
                    <button type="button" @click="$store.cat.closeEdit()" class="px-4 py-2 bg-gray-100 dark:bg-dark-300 text-sm rounded-lg">Cancel</button>
                    <button type="button" @click="$store.cat.saveEdit()" class="px-4 py-2 bg-accent-600 hover:bg-accent-700 text-white text-sm rounded-lg">Save</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
const CSRF = <?php echo json_encode($csrf); ?>;
const COLLECTION_ID = <?php echo json_encode($collectionId); ?>;
let etag = <?php echo json_encode($initial['etag']); ?>;
let categories = <?php echo json_encode($initial['list']); ?>;

function categoriesPage() {
  return {
    tree: [],
    newName: '',
    newParent: '',
    busy: false,
    error: '',
    init() {
      Alpine.store('cat', {
        editing: false,
        edit: { id: '', name: '', slug: '', parent_id: '', description: '' },
        editError: '',
        open: (cat) => { this.openEdit ? this.openEdit(cat) : null; },
        flatOptions: (excludeId) => flatOptions(excludeId),
        saveEdit: () => this.saveEdit(),
        deleteEdited: () => this.deleteEdited(),
        closeEdit: () => { Alpine.store('cat').editing = false; Alpine.store('cat').editError = ''; },
      });
      Alpine.store('cat').open = (cat) => this.openEdit(cat);
      this.render();
    },
    flatOptions(excludeId = null) { return flatOptions(excludeId); },
    render() {
      this.tree = buildTree(categories);
      const root = document.getElementById('cat-root');
      root.innerHTML = '';
      renderList(root, this.tree, 1, (cat) => this.openEdit(cat));
      attachSortable();
    },
    async addNew() {
      const name = this.newName.trim();
      if (!name) return;
      this.busy = true; this.error = '';
      try {
        const fd = new FormData();
        fd.append('action', 'create'); fd.append('collection_id', COLLECTION_ID);
        fd.append('csrf_token', CSRF); fd.append('if_match', etag);
        fd.append('name', name);
        if (this.newParent) fd.append('parent_id', this.newParent);
        const r = await fetch('/cms/admin/blog-categories-api.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (!j.success) throw new Error(j.error || 'Create failed');
        if (j.etag) etag = j.etag;
        if (j.list) categories = j.list;
        this.newName = '';
        this.render();
      } catch (e) { this.error = String(e.message || e); }
      finally { this.busy = false; }
    },
    openEdit(cat) {
      Alpine.store('cat').edit = {
        id: cat.id,
        name: cat.name?.default || '',
        slug: cat.slug,
        parent_id: cat.parent_id || '',
        description: cat.description || '',
      };
      Alpine.store('cat').editError = '';
      Alpine.store('cat').editing = true;
    },
    async saveEdit() {
      const e = Alpine.store('cat').edit;
      try {
        const fd = new FormData();
        fd.append('action', 'update'); fd.append('collection_id', COLLECTION_ID);
        fd.append('csrf_token', CSRF); fd.append('if_match', etag);
        fd.append('id', e.id);
        fd.append('name', JSON.stringify({ default: e.name, locales: {} }));
        fd.append('slug', e.slug);
        fd.append('parent_id', e.parent_id);
        fd.append('description', e.description);
        const r = await fetch('/cms/admin/blog-categories-api.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (!j.success) throw new Error(j.error || 'Update failed');
        if (j.etag) etag = j.etag;
        if (j.list) categories = j.list;
        Alpine.store('cat').editing = false;
        this.render();
      } catch (err) { Alpine.store('cat').editError = String(err.message || err); }
    },
    async deleteEdited() {
      const e = Alpine.store('cat').edit;
      if (!confirm('Delete this category? Children will move up one level. Posts in this collection lose this category.')) return;
      try {
        const fd = new FormData();
        fd.append('action', 'delete'); fd.append('collection_id', COLLECTION_ID);
        fd.append('csrf_token', CSRF); fd.append('if_match', etag);
        fd.append('id', e.id);
        const r = await fetch('/cms/admin/blog-categories-api.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (!j.success) throw new Error(j.error || 'Delete failed');
        if (j.etag) etag = j.etag;
        if (j.list) categories = j.list;
        Alpine.store('cat').editing = false;
        this.render();
      } catch (err) { Alpine.store('cat').editError = String(err.message || err); }
    },
  };
}

function buildTree(list) {
  const byParent = {};
  list.forEach(c => {
    const k = c.parent_id || '';
    (byParent[k] = byParent[k] || []).push(c);
  });
  Object.values(byParent).forEach(b => b.sort((a,b) => (a.sort_order || 0) - (b.sort_order || 0)));
  const build = (pid) => (byParent[pid || ''] || []).map(n => ({ ...n, children: build(n.id) }));
  return build(null);
}

function depthCap(node, depth) {
  // For drag-drop, we don't render droppable UL beyond level 3 (1-indexed)
  return depth >= 3;
}

function flatOptions(excludeId = null) {
  // Build all options except excludeId and its descendants (to prevent cycle in dropdown)
  const tree = buildTree(categories);
  const out = [];
  const banned = new Set();
  if (excludeId) {
    const mark = (id) => {
      banned.add(id);
      (categories.filter(c => c.parent_id === id)).forEach(c => mark(c.id));
    };
    mark(excludeId);
  }
  const walk = (nodes, prefix) => {
    nodes.forEach(n => {
      if (banned.has(n.id)) return;
      out.push({ id: n.id, label: prefix + (n.name?.default || n.slug) });
      walk(n.children, prefix + '— ');
    });
  };
  walk(tree, '');
  return out;
}

function renderList(parent, nodes, depth, onEdit) {
  parent.innerHTML = '';
  nodes.forEach(n => {
    const li = document.createElement('li');
    li.dataset.id = n.id;
    li.className = 'cat-node bg-surface-50 dark:bg-dark-300 border border-surface-200 dark:border-dark-200 rounded-lg';
    const row = document.createElement('div');
    row.className = 'flex items-center gap-2 p-2';
    row.innerHTML = `
      <span class="cat-handle cursor-grab text-gray-400 select-none" title="Drag to reorder">⠿</span>
      <span class="font-medium text-sm text-gray-800 dark:text-gray-100"></span>
      <code class="text-xs text-gray-500 dark:text-gray-400 font-mono"></code>
      <span class="ml-auto flex gap-1">
        <button type="button" class="edit-btn px-2 py-1 text-xs text-accent-700 hover:bg-accent-50 dark:hover:bg-accent-900/20 rounded">Edit</button>
      </span>`;
    row.querySelector('.font-medium').textContent = n.name?.default || '(unnamed)';
    row.querySelector('code').textContent = '/' + n.slug;
    row.querySelector('.edit-btn').onclick = () => onEdit(n);
    li.appendChild(row);
    if (!depthCap(n, depth)) {
      const ul = document.createElement('ul');
      ul.dataset.parentId = n.id;
      ul.className = 'cat-list pl-6 space-y-1 mt-1 min-h-[8px]';
      renderList(ul, n.children, depth + 1, onEdit);
      li.appendChild(ul);
    } else if (n.children.length) {
      // Past depth cap, show children read-only (use Move dropdown to rearrange)
      const note = document.createElement('div');
      note.className = 'pl-6 text-xs text-gray-500 dark:text-gray-400 italic mt-1';
      note.textContent = n.children.length + ' deeper categor' + (n.children.length === 1 ? 'y' : 'ies') + ' — open Edit to move them.';
      li.appendChild(note);
    }
    parent.appendChild(li);
  });
}

let sortables = [];
function attachSortable() {
  sortables.forEach(s => s.destroy());
  sortables = [];
  document.querySelectorAll('#cat-root, .cat-list').forEach(ul => {
    sortables.push(Sortable.create(ul, {
      group: 'categories',
      handle: '.cat-handle',
      animation: 150,
      fallbackOnBody: true,
      invertSwap: true,
      onEnd: onSortableEnd,
    }));
  });
}

async function onSortableEnd(evt) {
  const id = evt.item.dataset.id;
  const newParent = evt.to.dataset.parentId || '';
  const index = evt.newIndex;
  try {
    const fd = new FormData();
    fd.append('action', 'move'); fd.append('collection_id', COLLECTION_ID);
    fd.append('csrf_token', CSRF); fd.append('if_match', etag);
    fd.append('id', id); fd.append('parent_id', newParent); fd.append('index', index);
    const r = await fetch('/cms/admin/blog-categories-api.php', { method: 'POST', body: fd });
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'Move failed');
    if (j.etag) etag = j.etag;
    if (j.list) categories = j.list;
  } catch (e) {
    alert('Could not move: ' + (e.message || e) + '\nReloading.');
    location.reload();
  }
}
</script>

<style>
[x-cloak] { display: none !important; }
.cat-node { transition: background 0.1s; }
.sortable-ghost { opacity: 0.4; }
.sortable-chosen { background: rgba(249, 106, 77, 0.05); }
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
