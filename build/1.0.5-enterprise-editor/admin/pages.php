<?php
/**
 * Admin — Pages Management (List + Edit + New + Delete)
 */
$pageTitle = 'Pages';
$activeNav = 'pages';
$pageContentClass = 'page-content--fluid';

// Handle action before loading header
if (!defined('SOI_ROOT')) define('SOI_ROOT', dirname(__DIR__));
require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';
spl_autoload_register(fn($c) => (fn($f) => file_exists($f) && require_once $f)(SOI_ROOT.'/core/'.str_replace(['SOI\\Core\\','\\'],['','/'],$c).'.php'));
use SOI\Core\{Database, Auth};
use SOI\Core\Content\{ContentStore, Document, DocumentException, EditorSchema};
Database::connect(['host'=>SOI_DB_HOST,'name'=>SOI_DB_NAME,'user'=>SOI_DB_USER,'pass'=>SOI_DB_PASS,'port'=>SOI_DB_PORT,'prefix'=>SOI_DB_PREFIX]);
Auth::init();
Auth::requireAuth('author');
EditorSchema::ensure();

$action = soi_get('action', 'list');
$id     = (int) soi_get('id', 0);

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['_csrf'] ?? '')) die('CSRF check failed.');

    $postAction = $_POST['_action'] ?? '';

    if ($postAction === 'convert_structured') {
        $editId = (int) ($_POST['id'] ?? 0);
        try {
            if ($editId < 1) {
                throw new DocumentException('Save the page before converting it.');
            }
            ContentStore::convertLegacyToStructured('page', $editId);
            class_exists(\SOI\Core\Cache::class) && \SOI\Core\Cache::purgeAll();
            soi_flash('success', 'Opened in the structured editor. Existing HTML was preserved as a legacy block.');
            soi_redirect(SOI_ADMIN_URL . '/pages.php?action=edit&id=' . $editId);
        } catch (DocumentException $e) {
            soi_flash('error', $e->getMessage());
        }
    }

    if ($postAction === 'save') {
        $editId = (int) ($_POST['id'] ?? 0);
        $fields = [
            'title' => $_POST['title'] ?? '',
            'slug' => $_POST['slug'] ?? '',
            'status' => $_POST['status'] ?? 'draft',
            'meta_title' => $_POST['meta_title'] ?? '',
            'meta_desc' => $_POST['meta_desc'] ?? '',
            'expected_updated_at' => $_POST['expected_updated_at'] ?? '',
        ];
        try {
            if (($_POST['mode'] ?? '') === 'structured') {
                $document = Document::parse($_POST['document'] ?? '');
                $result = ContentStore::save('page', $editId, $fields, $document);
            } else {
                $result = ContentStore::saveLegacy('page', $editId, $fields, (string) ($_POST['legacy_html'] ?? $_POST['content'] ?? ''));
            }
            class_exists(\SOI\Core\Cache::class) && \SOI\Core\Cache::purgeAll();
            soi_flash('success', $editId ? 'Page updated successfully.' : 'Page created successfully.');
            soi_redirect(SOI_ADMIN_URL . '/pages.php?action=edit&id=' . $result['id']);
        } catch (DocumentException $e) {
            soi_flash('error', $e->getMessage());
        }
    }

    if ($postAction === 'delete') {
        $delId = (int) ($_POST['id'] ?? 0);
        if ($delId) {
            Database::delete('pages', 'id = ?', [$delId]);
            class_exists(\SOI\Core\Cache::class) && \SOI\Core\Cache::purgeAll();
            soi_flash('success', 'Page deleted.');
        }
        soi_redirect(SOI_ADMIN_URL . '/pages.php');
    }
}


// Load page for editing
$editPage = null;
if ($action === 'edit' && $id) {
    $editPage = Database::selectOne("SELECT * FROM `" . Database::prefix('pages') . "` WHERE id = ?", [$id]);
    if (!$editPage) { soi_flash('error', 'Page not found.'); soi_redirect(SOI_ADMIN_URL . '/pages.php'); }
    $pageTitle = 'Edit Page';
}
if ($action === 'new') {
    $pageTitle = 'New Page';
}

$topbarActions = '';
if ($action === 'list') {
    $topbarActions = '<a href="?action=new" class="topbar-btn topbar-btn-primary">+ New Page</a>';
} else {
    $topbarActions = '<a href="' . SOI_ADMIN_URL . '/pages.php" class="topbar-btn topbar-btn-ghost">← All Pages</a>';
}

$extraHead = '';
if ($action === 'new' || $action === 'edit') {
    $pageContentClass = 'page-content--fluid page-content--editor';
    $extraHead = '<link rel="stylesheet" href="' . esc(soi_admin_asset_url('editor/kc-editor.css')) . '?v=1.0.0">';
}

require_once __DIR__ . '/partials/header.php';

// ===========================
// List View
// ===========================
if ($action === 'list'):
    $filter = soi_get('status', '');
    $search = soi_get('q', '');
    $where  = '1=1';
    $params = [];
    if ($filter) { $where .= " AND status = ?"; $params[] = $filter; }
    if ($search) { $where .= " AND title LIKE ?"; $params[] = "%$search%"; }
    $pages = Database::select("SELECT * FROM `" . Database::prefix('pages') . "` WHERE $where ORDER BY created_at DESC", $params);
?>
<form method="GET" class="toolbar">
  <input type="hidden" name="status" value="<?= esc($filter) ?>">
  <input class="search-input" type="text" name="q" placeholder="Search pages…" value="<?= esc($search) ?>">
  <button type="submit" class="btn btn-ghost">Search</button>
  <?php foreach(['','published','draft'] as $s): ?>
  <a href="?status=<?= $s ?>" class="btn btn-ghost btn-sm <?= $filter===$s?'btn-primary':'' ?>"><?= $s?ucfirst($s):'All' ?></a>
  <?php endforeach; ?>
</form>

<div class="card">
  <?php if (!$pages): ?>
  <div class="empty-state">
    <div class="empty-state-icon">📄</div>
    <div class="empty-state-title">No pages found</div>
    <div class="empty-state-text"><a href="?action=new" class="admin-link">Create your first page →</a></div>
  </div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table--dense">
      <thead><tr>
        <th><input type="checkbox" id="select-all"></th>
        <th>Title</th><th>Slug</th><th>Status</th><th>Date</th><th>Actions</th>
      </tr></thead>
      <tbody>
        <?php foreach ($pages as $page): ?>
        <tr>
          <td><input type="checkbox" class="row-checkbox" value="<?= $page['id'] ?>"></td>
          <td class="td-title"><a href="?action=edit&id=<?= $page['id'] ?>" class="table-link"><?= esc($page['title']) ?></a></td>
          <td><code class="table-meta">/<?= esc($page['slug']) ?></code></td>
          <td>
            <?php $sc = ['published'=>'badge-success','draft'=>'badge-warning','private'=>'badge-info'][$page['status']] ?? 'badge-muted'; ?>
            <span class="badge <?= $sc ?>"><?= esc($page['status']) ?></span>
          </td>
          <td class="text-muted nowrap"><?= date('M j, Y', strtotime($page['created_at'])) ?></td>
          <td>
            <div class="td-actions">
              <a href="?action=edit&id=<?= $page['id'] ?>" class="btn btn-ghost btn-sm">✏️ Edit</a>
              <a href="<?= SOI_HOME_URL ?>/<?= esc($page['slug']) ?>" target="_blank" class="btn btn-ghost btn-sm">🌐</a>
              <form method="POST" class="form-inline">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="id" value="<?= $page['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete this page?">🗑️</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php
// ===========================
// Edit / New Form
// ===========================
else:
    $editorEntity = 'page';
    $editorRecord = $editPage ?? [];
    $editorIsNew = ($action === 'new');
    $editorListUrl = SOI_ADMIN_URL . '/pages.php';
    $editorShowExcerpt = false;
    $editorCategories = [];
    $editorAssignedCats = [];
    require __DIR__ . '/partials/structured-editor.php';
endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
