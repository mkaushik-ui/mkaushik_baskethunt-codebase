<?php
/**
 * Admin — Users & Roles (SOI Accounts)
 * Manage CMS roles and access for users who have signed in via Accounts.
 */
$pageTitle = 'Users & Roles';
$activeNav = 'users';
$pageContentClass = 'page-content--fluid';

if (!defined('SOI_ROOT')) define('SOI_ROOT', dirname(__DIR__));
require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';
spl_autoload_register(fn($c) => (fn($f) => file_exists($f) && require_once $f)(SOI_ROOT.'/core/'.str_replace(['SOI\\Core\\','\\'],['','/'],$c).'.php'));
use SOI\Core\{Database, Auth};
Database::connect(['host'=>SOI_DB_HOST,'name'=>SOI_DB_NAME,'user'=>SOI_DB_USER,'pass'=>SOI_DB_PASS,'port'=>SOI_DB_PORT,'prefix'=>SOI_DB_PREFIX]);
Auth::init();
Auth::requireAuth('admin');

$usersTable = Database::prefix('users');
$action     = soi_get('action', 'list');
$id         = (int) soi_get('id', 0);

if ($action === 'new') {
    soi_redirect(SOI_ADMIN_URL . '/users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['_csrf'] ?? '')) die('CSRF');
    $postAction = $_POST['_action'] ?? '';

    if ($postAction === 'save') {
        $editId = (int) ($_POST['id'] ?? 0);
        $role   = $_POST['role'] ?? 'subscriber';
        $status = isset($_POST['status']) ? 1 : 0;

        $target = Database::selectOne(
            "SELECT * FROM `$usersTable` WHERE id = ? AND accounts_user_id IS NOT NULL AND accounts_user_id != ''",
            [$editId]
        );

        if (!$target) {
            soi_flash('error', 'User not found or not linked to SOI Accounts.');
            soi_redirect(SOI_ADMIN_URL . '/users.php');
        }

        if (!in_array($role, ['admin', 'editor', 'author', 'subscriber'], true)) {
            soi_flash('error', 'Invalid role selected.');
            soi_redirect(SOI_ADMIN_URL . '/users.php?action=edit&id=' . $editId);
        }

        if ($role !== 'admin') {
            $adminCount = Database::count('users', "role = 'admin' AND id != ?", [$editId]);
            if ($adminCount < 1) {
                soi_flash('error', 'There must be at least one admin user.');
                soi_redirect(SOI_ADMIN_URL . '/users.php?action=edit&id=' . $editId);
            }
        }

        if ($editId === Auth::id() && !$status) {
            soi_flash('error', 'You cannot deactivate your own account.');
            soi_redirect(SOI_ADMIN_URL . '/users.php?action=edit&id=' . $editId);
        }

        Database::update('users', ['role' => $role, 'status' => $status], 'id = ?', [$editId]);
        soi_flash('success', 'User access updated.');
        soi_redirect(SOI_ADMIN_URL . '/users.php');
    }

    if ($postAction === 'delete') {
        $delId = (int) ($_POST['id'] ?? 0);
        if ($delId === Auth::id()) {
            soi_flash('error', 'Cannot remove your own CMS access.');
        } elseif ($delId) {
            $targetUser = Database::selectOne(
                "SELECT role, accounts_user_id FROM `$usersTable` WHERE id = ?",
                [$delId]
            );
            if (empty($targetUser['accounts_user_id'])) {
                soi_flash('error', 'Only Accounts-linked users can be managed here.');
            } elseif ($targetUser['role'] === 'admin' && Database::count('users', "role = 'admin'") <= 1) {
                soi_flash('error', 'Cannot remove the last admin user.');
            } else {
                Database::delete('users', 'id = ?', [$delId]);
                soi_flash('success', 'CMS access removed. The user can sign in again via SOI Accounts.');
            }
        }
        soi_redirect(SOI_ADMIN_URL . '/users.php');
    }

    if ($postAction === 'toggle_status') {
        $toggleId  = (int) ($_POST['id'] ?? 0);
        $newStatus = (int) ($_POST['status'] ?? 1);
        if ($toggleId && $toggleId !== Auth::id()) {
            $targetUser = Database::selectOne(
                "SELECT accounts_user_id FROM `$usersTable` WHERE id = ?",
                [$toggleId]
            );
            if (!empty($targetUser['accounts_user_id'])) {
                Database::update('users', ['status' => $newStatus], 'id = ?', [$toggleId]);
                soi_flash('success', 'User status updated.');
            }
        }
        soi_redirect(SOI_ADMIN_URL . '/users.php');
    }
}

$editUser = null;
if ($action === 'edit' && $id) {
    $editUser = Database::selectOne(
        "SELECT * FROM `$usersTable` WHERE id = ? AND accounts_user_id IS NOT NULL AND accounts_user_id != ''",
        [$id]
    );
    if (!$editUser) {
        soi_flash('error', 'User not found or not linked to SOI Accounts.');
        soi_redirect(SOI_ADMIN_URL . '/users.php');
    }
    $pageTitle = 'Edit Access';
}

$topbarActions = $action === 'edit'
    ? '<a href="' . SOI_ADMIN_URL . '/users.php" class="topbar-btn topbar-btn-ghost">← All Users</a>'
    : '';

require_once __DIR__ . '/partials/header.php';

if ($action === 'list'):
    $users = Database::select(
        "SELECT * FROM `$usersTable` WHERE accounts_user_id IS NOT NULL AND accounts_user_id != '' ORDER BY last_login DESC, created_at DESC"
    );
?>
<details class="help-disclosure help-disclosure--inline">
  <summary>About user access</summary>
  <div class="help-disclosure-body">
    Users appear after signing in through SOI Accounts. Assign CMS roles and enable or disable admin access here. Identity is managed centrally at accounts.soi.co.in.
  </div>
</details>

<div class="card">
  <?php if (empty($users)): ?>
  <div class="empty-state" style="padding:2.5rem 1.5rem;">
    <div class="empty-state-title">No Accounts users yet</div>
    <div class="empty-state-text">Users appear after their first sign-in via SOI Accounts.</div>
  </div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table--dense">
      <thead><tr>
        <th>User</th><th>Email</th><th>Role</th><th>Status</th><th>Last Login</th><th>Actions</th>
      </tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td>
            <div class="user-cell">
              <div class="user-cell-avatar"><?= strtoupper(substr($u['display_name'] ?: $u['username'], 0, 1)) ?></div>
              <div>
                <div class="user-cell-name"><a href="?action=edit&id=<?= $u['id'] ?>" class="table-link"><?= esc($u['display_name'] ?: $u['username']) ?></a></div>
                <div class="user-cell-sub">SOI Accounts</div>
              </div>
            </div>
          </td>
          <td><?= esc($u['email']) ?></td>
          <td><span class="badge <?= $u['role']==='admin'?'badge-error':($u['role']==='editor'?'badge-info':'badge-muted') ?>" style="text-transform:capitalize;"><?= esc($u['role']) ?></span></td>
          <td><span class="badge <?= $u['status'] ? 'badge-success' : 'badge-muted' ?>"><?= $u['status'] ? 'Active' : 'Inactive' ?></span></td>
          <td class="text-muted nowrap"><?= $u['last_login'] ? time_ago($u['last_login']) : 'Never' ?></td>
          <td>
            <div class="td-actions">
              <a href="?action=edit&id=<?= $u['id'] ?>" class="btn btn-ghost btn-sm">✏️ Edit Access</a>
              <?php if ($u['id'] !== Auth::id()): ?>
              <form method="POST" class="form-inline">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="_action" value="toggle_status">
                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                <input type="hidden" name="status" value="<?= $u['status'] ? 0 : 1 ?>">
                <button class="btn btn-ghost btn-sm"><?= $u['status'] ? '🚫 Disable' : '✅ Enable' ?></button>
              </form>
              <form method="POST" class="form-inline">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button class="btn btn-danger btn-sm" data-confirm="Remove CMS access for <?= esc($u['display_name'] ?: $u['username']) ?>? Their SOI Accounts identity will not be deleted.">🗑️</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php else: $u = $editUser; ?>
<form method="POST">
  <?= Auth::csrfField() ?>
  <input type="hidden" name="_action" value="save">
  <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">

  <div style="display:grid;grid-template-columns:2fr 1fr;gap:1.5rem;align-items:start;">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Access Settings</h3></div>
      <div class="card-body form-section">
        <p style="font-size:0.83rem;color:var(--text-muted);margin-bottom:1rem;line-height:1.5;">
          Identity details are synced from SOI Accounts and cannot be edited here.
        </p>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Display Name</label>
            <input class="form-input" type="text" value="<?= esc($u['display_name'] ?? '') ?>" disabled style="opacity:0.7;">
          </div>
          <div class="form-group">
            <label class="form-label">Email</label>
            <input class="form-input" type="email" value="<?= esc($u['email']) ?>" disabled style="opacity:0.7;">
          </div>
          <div class="form-group full">
            <label class="form-label">Accounts User ID</label>
            <input class="form-input" type="text" value="<?= esc($u['accounts_user_id']) ?>" disabled style="opacity:0.7;font-family:monospace;font-size:0.82rem;">
          </div>
          <div class="form-group">
            <label class="form-label">CMS Role</label>
            <select class="form-select" name="role">
              <?php foreach (['admin','editor','author','subscriber'] as $r): ?>
              <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="display:flex;align-items:center;padding-top:1.6rem;">
            <label class="toggle-wrap">
              <span class="toggle-switch">
                <input type="checkbox" name="status" value="1" <?= $u['status'] ? 'checked' : '' ?> <?= $u['id'] === Auth::id() ? 'disabled' : '' ?>>
                <span class="toggle-slider"></span>
              </span>
              <span class="toggle-label">Active (can access CMS when permitted by role)</span>
            </label>
          </div>
        </div>
        <div class="btn-row">
          <button type="submit" class="btn btn-primary">💾 Save Access</button>
          <a href="<?= SOI_ADMIN_URL ?>/users.php" class="btn btn-ghost">Cancel</a>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Role Permissions</h3></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:0.6rem;">
        <?php
        $roleInfo = [
            'admin'      => 'Full access to everything.',
            'editor'     => 'Create/edit/delete all posts & pages.',
            'author'     => 'Create and manage own posts only.',
            'subscriber' => 'No admin access. Frontend only.',
        ];
        foreach ($roleInfo as $r => $desc):
        ?>
        <div style="padding:0.6rem 0.75rem;background:var(--surface2);border-radius:7px;border:1px solid var(--border);">
          <div style="font-weight:700;font-size:0.8rem;text-transform:capitalize;margin-bottom:0.2rem;"><?= $r ?></div>
          <div style="font-size:0.75rem;color:var(--text-muted);"><?= $desc ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</form>
<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>