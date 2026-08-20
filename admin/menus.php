<?php
/**
 * Admin — Navigation Menus
 */
$pageTitle = 'Menus';
$activeNav = 'menus';

if (!defined('SOI_ROOT')) define('SOI_ROOT', dirname(__DIR__));
require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';
spl_autoload_register(fn($c) => (fn($f) => file_exists($f) && require_once $f)(SOI_ROOT.'/core/'.str_replace(['SOI\\Core\\','\\'],['','/'],$c).'.php'));
use SOI\Core\{Database, Auth, Blog};
Database::connect(['host'=>SOI_DB_HOST,'name'=>SOI_DB_NAME,'user'=>SOI_DB_USER,'pass'=>SOI_DB_PASS,'port'=>SOI_DB_PORT,'prefix'=>SOI_DB_PREFIX]);
Auth::init();
Auth::requireAuth('editor');
Blog::ensureMigrated();

$menuId = (int) soi_get('menu_id', 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['_csrf'] ?? '')) die('CSRF');
    $action = $_POST['_action'] ?? '';

    if ($action === 'create_menu') {
        $name = trim($_POST['menu_name'] ?? '');
        $loc  = trim($_POST['menu_location'] ?? '');
        if ($name) {
            $id = Database::insert('menus', ['name' => $name, 'location' => $loc]);
            class_exists(\SOI\Core\Cache::class) && \SOI\Core\Cache::purgeAll();
            soi_flash('success', 'Menu created.');
            soi_redirect(SOI_ADMIN_URL . '/menus.php?menu_id=' . $id);
        }
    }

    if ($action === 'delete_menu') {
        $mid = (int)($_POST['menu_id'] ?? 0);
        if ($mid) {
            Database::delete('menu_items', 'menu_id = ?', [$mid]);
            Database::delete('menus', 'id = ?', [$mid]);
            class_exists(\SOI\Core\Cache::class) && \SOI\Core\Cache::purgeAll();
            soi_flash('success', 'Menu deleted.');
        }
        soi_redirect(SOI_ADMIN_URL . '/menus.php');
    }

    if ($action === 'add_item') {
        $mid   = (int)($_POST['menu_id'] ?? 0);
        $title = trim($_POST['item_title'] ?? '');
        $url   = trim($_POST['item_url'] ?? '');
        if ($mid && $title) {
            $maxOrder = Database::selectOne("SELECT MAX(sort_order) as m FROM `".Database::prefix('menu_items')."` WHERE menu_id = ?", [$mid]);
            Database::insert('menu_items', [
                'menu_id'    => $mid,
                'parent_id'  => 0,
                'title'      => $title,
                'url'        => $url,
                'type'       => 'custom',
                'sort_order' => (($maxOrder['m'] ?? 0) + 1),
            ]);
            class_exists(\SOI\Core\Cache::class) && \SOI\Core\Cache::purgeAll();
            soi_flash('success', 'Menu item added.');
        }
        soi_redirect(SOI_ADMIN_URL . '/menus.php?menu_id=' . $mid);
    }

    if ($action === 'save_order') {
        $mid   = (int)($_POST['menu_id'] ?? 0);
        $items = $_POST['items'] ?? [];
        foreach ($items as $order => $itemId) {
            Database::update('menu_items', ['sort_order' => (int)$order], 'id = ?', [(int)$itemId]);
        }
        class_exists(\SOI\Core\Cache::class) && \SOI\Core\Cache::purgeAll();
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $mid    = (int)($_POST['menu_id'] ?? 0);
        if ($itemId) Database::delete('menu_items', 'id = ?', [$itemId]);
        class_exists(\SOI\Core\Cache::class) && \SOI\Core\Cache::purgeAll();
        soi_redirect(SOI_ADMIN_URL . '/menus.php?menu_id=' . $mid);
    }
}

$menus    = Database::select("SELECT * FROM `" . Database::prefix('menus') . "` ORDER BY id");
$pages    = Database::select("SELECT id, title, slug FROM `" . Database::prefix('pages') . "` WHERE status='published' ORDER BY title");
$posts    = Blog::isEnabled()
    ? Database::select("SELECT id, title, slug FROM `" . Database::prefix('posts') . "` WHERE status='published' ORDER BY title LIMIT 50")
    : [];
$curMenu  = $menuId ? Database::selectOne("SELECT * FROM `" . Database::prefix('menus') . "` WHERE id = ?", [$menuId]) : null;
$curItems = $menuId ? Database::select("SELECT * FROM `" . Database::prefix('menu_items') . "` WHERE menu_id = ? ORDER BY sort_order", [$menuId]) : [];

require_once __DIR__ . '/partials/header.php';
?>
<div style="display:grid;grid-template-columns:240px 1fr;gap:1.5rem;align-items:start;">
  <!-- Left: Menu List + Create -->
  <div>
    <div class="card">
      <div class="card-header"><h3 class="card-title">All Menus</h3></div>
      <div style="padding:0.5rem 0;">
        <?php foreach ($menus as $m): ?>
        <a href="?menu_id=<?= $m['id'] ?>" class="nav-item <?= $menuId==$m['id']?'active':'' ?>">
          🗂️ <?= esc($m['name']) ?>
          <?php if ($m['location']): ?><span style="font-size:0.68rem;color:var(--text-muted);margin-left:auto;"><?= esc($m['location']) ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php if (!$menus): ?>
        <div style="padding:1rem;font-size:0.8rem;color:var(--text-muted);">No menus yet.</div>
        <?php endif; ?>
      </div>
      <div style="padding:0.75rem;border-top:1px solid var(--border);">
        <form method="POST">
          <?= Auth::csrfField() ?>
          <input type="hidden" name="_action" value="create_menu">
          <input class="form-input" type="text" name="menu_name" placeholder="New menu name…" required style="margin-bottom:0.5rem;">
          <select class="form-select" name="menu_location" style="margin-bottom:0.5rem;">
            <option value="">No location</option>
            <option value="primary">Primary Navigation</option>
            <option value="footer">Footer Menu</option>
            <option value="sidebar">Sidebar Menu</option>
          </select>
          <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">+ Create Menu</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Right: Edit Current Menu -->
  <div>
    <?php if (!$curMenu): ?>
    <div class="card">
      <div class="empty-state">
        <div class="empty-state-icon">🗂️</div>
        <div class="empty-state-title">Select or create a menu</div>
        <div class="empty-state-text">Choose a menu from the left to edit it.</div>
      </div>
    </div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:1.25rem;">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">✏️ <?= esc($curMenu['name']) ?></h3>
          <form method="POST" style="display:inline;">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="_action" value="delete_menu">
            <input type="hidden" name="menu_id" value="<?= $curMenu['id'] ?>">
            <button class="btn btn-danger btn-sm" data-confirm="Delete this menu?">🗑️ Delete Menu</button>
          </form>
        </div>
        <div class="card-body">
          <?php if (!$curItems): ?>
          <div class="empty-state" style="padding:2rem;">
            <div class="empty-state-icon">📋</div>
            <div class="empty-state-title">No items yet</div>
            <div class="empty-state-text">Add items using the form below.</div>
          </div>
          <?php else: ?>
          <p style="font-size:0.8rem;color:var(--text-muted);margin-bottom:0.75rem;">Drag to reorder items. Changes are saved automatically.</p>
          <ul class="menu-item-list" id="menu-item-list">
            <?php foreach ($curItems as $item): ?>
            <li class="menu-item-row" draggable="true" data-id="<?= $item['id'] ?>">
              <input type="hidden" name="sort[]" value="<?= $item['sort_order'] ?>">
              <span class="menu-drag">⠿</span>
              <span class="menu-item-title"><?= esc($item['title']) ?></span>
              <span class="menu-item-type"><?= esc($item['url'] ?? '') ?></span>
              <form method="POST" style="margin-left:auto;flex-shrink:0;">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="_action" value="delete_item">
                <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                <input type="hidden" name="menu_id" value="<?= $curMenu['id'] ?>">
                <button class="btn btn-ghost btn-icon" style="font-size:0.75rem;" data-confirm="Remove this item?">✕</button>
              </form>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </div>
      </div>

      <!-- Add Items -->
      <div class="card">
        <div class="card-header"><h3 class="card-title">+ Add Items</h3></div>
        <div class="card-body">
          <!-- Custom Link -->
          <details open>
            <summary style="cursor:pointer;font-size:0.85rem;font-weight:600;margin-bottom:0.75rem;padding:0.4rem;border-radius:6px;">🔗 Custom Link</summary>
            <form method="POST" style="display:flex;flex-direction:column;gap:0.6rem;">
              <?= Auth::csrfField() ?>
              <input type="hidden" name="_action" value="add_item">
              <input type="hidden" name="menu_id" value="<?= $curMenu['id'] ?>">
              <input class="form-input" type="text" name="item_title" placeholder="Link text" required>
              <input class="form-input" type="text" name="item_url" placeholder="https://example.com">
              <button type="submit" class="btn btn-primary btn-sm">Add</button>
            </form>
          </details>

          <!-- Pages -->
          <?php if ($pages): ?>
          <details style="margin-top:1rem;">
            <summary style="cursor:pointer;font-size:0.85rem;font-weight:600;margin-bottom:0.75rem;padding:0.4rem;">📄 Pages</summary>
            <div style="display:flex;flex-direction:column;gap:0.35rem;max-height:180px;overflow-y:auto;">
              <?php foreach ($pages as $page): ?>
              <form method="POST" style="display:flex;gap:0.5rem;align-items:center;">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="_action" value="add_item">
                <input type="hidden" name="menu_id" value="<?= $curMenu['id'] ?>">
                <input type="hidden" name="item_title" value="<?= esc($page['title']) ?>">
                <input type="hidden" name="item_url" value="<?= SOI_HOME_URL ?>/<?= esc($page['slug']) ?>">
                <span style="flex:1;font-size:0.82rem;"><?= esc($page['title']) ?></span>
                <button type="submit" class="btn btn-ghost btn-sm">+</button>
              </form>
              <?php endforeach; ?>
            </div>
          </details>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
