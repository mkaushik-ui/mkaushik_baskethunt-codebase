<?php
/**
 * Admin — Posts Management (List + Edit + New + Delete)
 */
$pageTitle = 'Posts';
$activeNav = 'posts';
$pageContentClass = 'page-content--fluid';

if (!defined('SOI_ROOT')) define('SOI_ROOT', dirname(__DIR__));
require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';
spl_autoload_register(fn($c) => (fn($f) => file_exists($f) && require_once $f)(SOI_ROOT.'/core/'.str_replace(['SOI\\Core\\','\\'],['','/'],$c).'.php'));
use SOI\Core\{Database, Auth, Blog};
use SOI\Core\Content\{ContentStore, Document, DocumentException, EditorSchema};
Database::connect(['host'=>SOI_DB_HOST,'name'=>SOI_DB_NAME,'user'=>SOI_DB_USER,'pass'=>SOI_DB_PASS,'port'=>SOI_DB_PORT,'prefix'=>SOI_DB_PREFIX]);
Auth::init();
Auth::requireAuth('author');
EditorSchema::ensure();
Blog::ensureMigrated();
if (!Blog::isEnabled()) {
    soi_flash('error', 'Blog features are disabled. Enable them in General Settings → Blog Module.');
    soi_redirect(SOI_ADMIN_URL . '/settings.php');
}

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
                throw new DocumentException('Save the post before converting it.');
            }
            ContentStore::convertLegacyToStructured('post', $editId);
            class_exists(\SOI\Core\Cache::class) && \SOI\Core\Cache::purgeAll();
            soi_flash('success', 'Opened in the structured editor. Existing HTML was preserved as a legacy block.');
            soi_redirect(SOI_ADMIN_URL . '/posts.php?action=edit&id=' . $editId);
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
            'excerpt' => $_POST['excerpt'] ?? '',
            'categories' => $_POST['categories'] ?? [],
            'expected_updated_at' => $_POST['expected_updated_at'] ?? '',
        ];
        try {
            if (($_POST['mode'] ?? '') === 'structured') {
                $document = Document::parse($_POST['document'] ?? '');
                $result = ContentStore::save('post', $editId, $fields, $document);
            } else {
                $result = ContentStore::saveLegacy('post', $editId, $fields, (string) ($_POST['legacy_html'] ?? $_POST['content'] ?? ''));
            }
            class_exists(\SOI\Core\Cache::class) && \SOI\Core\Cache::purgeAll();
            soi_flash('success', $editId ? 'Post updated.' : 'Post created.');
            soi_redirect(SOI_ADMIN_URL . '/posts.php?action=edit&id=' . $result['id']);
        } catch (DocumentException $e) {
            soi_flash('error', $e->getMessage());
        }
    }

    if ($postAction === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        if ($delId) {
            Database::delete('posts', 'id = ?', [$delId]);
            class_exists(\SOI\Core\Cache::class) && \SOI\Core\Cache::purgeAll();
            soi_flash('success', 'Post deleted.');
        }
        soi_redirect(SOI_ADMIN_URL . '/posts.php');
    }
}

$editPost = null;
if ($action === 'edit' && $id) {
    $editPost = Database::selectOne("SELECT * FROM `" . Database::prefix('posts') . "` WHERE id = ?", [$id]);
    if (!$editPost) { soi_flash('error', 'Post not found.'); soi_redirect(SOI_ADMIN_URL . '/posts.php'); }
    $pageTitle = 'Edit Post';
    // Get assigned categories
    $assignedCats = array_column(Database::select(
        "SELECT category_id FROM `" . Database::prefix('post_categories') . "` WHERE post_id = ?", [$id]
    ), 'category_id');
}
if ($action === 'new') { $pageTitle = 'New Post'; $assignedCats = []; }

$allCategories = Database::select("SELECT * FROM `" . Database::prefix('categories') . "` ORDER BY name");

$topbarActions = $action === 'list'
    ? '<a href="?action=new" class="topbar-btn topbar-btn-primary">+ New Post</a>'
    : '<a href="' . SOI_ADMIN_URL . '/posts.php" class="topbar-btn topbar-btn-ghost">← All Posts</a>';

$extraHead = '';
if ($action === 'new' || $action === 'edit') {
    $pageContentClass = 'page-content--fluid page-content--editor';
    $bodyClass = 'kc-authoring kc-authoring-fullscreen';
    $extraHead = '<link rel="stylesheet" href="' . esc(soi_admin_asset_url('editor/kc-editor.css')) . '?v=1.0.8">';
}

require_once __DIR__ . '/partials/header.php';

if ($action === 'list'):
    $filter = soi_get('status','');
    $search = soi_get('q','');
    $where = '1=1'; $params = [];
    if ($filter) { $where .= " AND p.status = ?"; $params[] = $filter; }
    if ($search) { $where .= " AND p.title LIKE ?"; $params[] = "%$search%"; }
    $posts = Database::select("SELECT p.*, u.display_name as author_name FROM `".Database::prefix('posts')."` p LEFT JOIN `".Database::prefix('users')."` u ON p.author_id=u.id WHERE $where ORDER BY p.created_at DESC", $params);
?>

<form method="GET" class="toolbar">
  <input class="search-input" type="text" name="q" placeholder="Search posts…" value="<?= esc($search) ?>">
  <button type="submit" class="btn btn-ghost">Search</button>
  <?php foreach(['','published','draft'] as $s): ?>
  <a href="?status=<?= $s ?>" class="btn btn-ghost btn-sm <?= $filter===$s?'btn-primary':'' ?>"><?= $s?ucfirst($s):'All' ?></a>
  <?php endforeach; ?>
</form>

<div class="card">
  <?php if (!$posts): ?>
  <div class="empty-state">
    <div class="empty-state-icon">📝</div>
    <div class="empty-state-title">No posts yet</div>
    <div class="empty-state-text"><a href="?action=new" class="admin-link">Write your first post →</a></div>
  </div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table--dense">
      <thead><tr>
        <th><input type="checkbox" id="select-all"></th>
        <th>Title</th><th>Author</th><th>Status</th><th>Date</th><th>Actions</th>
      </tr></thead>
      <tbody>
        <?php foreach ($posts as $post): ?>
        <tr>
          <td><input type="checkbox" class="row-checkbox"></td>
          <td class="td-title">
            <a href="?action=edit&id=<?= $post['id'] ?>" class="table-link"><?= esc($post['title']) ?></a>
            <div class="table-meta">/<?= esc($post['slug']) ?></div>
          </td>
          <td><?= esc($post['author_name'] ?? '—') ?></td>
          <td>
            <?php $sc = ['published'=>'badge-success','draft'=>'badge-warning','private'=>'badge-info'][$post['status']] ?? 'badge-muted'; ?>
            <span class="badge <?= $sc ?>"><?= esc($post['status']) ?></span>
          </td>
          <td class="text-muted nowrap"><?= date('M j, Y', strtotime($post['created_at'])) ?></td>
          <td>
            <div class="td-actions">
              <a href="?action=edit&id=<?= $post['id'] ?>" class="btn btn-ghost btn-sm">✏️ Edit</a>
              <a href="<?= SOI_HOME_URL ?>/<?= esc($post['slug']) ?>" target="_blank" class="btn btn-ghost btn-sm">🌐</a>
              <form method="POST" class="form-inline">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="id" value="<?= $post['id'] ?>">
                <button class="btn btn-danger btn-sm" data-confirm="Delete this post?">🗑️</button>
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

<?php else:
    $editorEntity = 'post';
    $editorRecord = $editPost ?? [];
    $editorIsNew = ($action === 'new');
    $editorListUrl = SOI_ADMIN_URL . '/posts.php';
    $editorShowExcerpt = true;
    $editorCategories = $allCategories ?? [];
    $editorAssignedCats = $assignedCats ?? [];
    require __DIR__ . '/partials/structured-editor.php';
endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
