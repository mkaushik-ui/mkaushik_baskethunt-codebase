<?php
/**
 * Admin — My Profile
 * Local CMS preferences only; identity and password are managed via SOI Accounts.
 */
$pageTitle = 'My Profile';
$activeNav = 'profile';

if (!defined('SOI_ROOT')) define('SOI_ROOT', dirname(__DIR__));
require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';
spl_autoload_register(fn($c) => (fn($f) => file_exists($f) && require_once $f)(SOI_ROOT.'/core/'.str_replace(['SOI\\Core\\','\\'],['','/'],$c).'.php'));
use SOI\Core\{Database, Auth, Accounts};
Database::connect(['host'=>SOI_DB_HOST,'name'=>SOI_DB_NAME,'user'=>SOI_DB_USER,'pass'=>SOI_DB_PASS,'port'=>SOI_DB_PORT,'prefix'=>SOI_DB_PREFIX]);
Auth::init();
Auth::requireAuth('subscriber');

$userId = Auth::id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['_csrf'] ?? '')) die('CSRF');

    $displayName = trim($_POST['display_name'] ?? '');
    $bio         = trim($_POST['bio'] ?? '');

    Database::update('users', [
        'display_name' => $displayName,
        'bio'          => $bio,
    ], 'id = ?', [$userId]);

    $_SESSION['soi_user']['display_name'] = $displayName;

    soi_flash('success', 'Local profile preferences saved.');
    soi_redirect(SOI_ADMIN_URL . '/profile.php');
}

$user = Database::selectOne("SELECT * FROM `" . Database::prefix('users') . "` WHERE id = ?", [$userId]);
$accountsLinked = Accounts::isLinked();
$accountsCreds = Accounts::getStoredCredentials();
$hasMyAccountWidget = $accountsLinked && !empty($accountsCreds['api_key']);

require_once __DIR__ . '/partials/header.php';
?>
<div style="display:grid;grid-template-columns:2fr 1fr;gap:1.5rem;align-items:start;">
  <div class="card">
    <div class="card-header"><h3 class="card-title">Local CMS Profile</h3></div>
    <div class="card-body">
      <details class="help-disclosure help-disclosure--inline">
        <summary>Identity managed by SOI Accounts</summary>
        <div class="help-disclosure-body">
          Your name, email, and password are managed through SOI Accounts.
          <?php if ($hasMyAccountWidget): ?>
            Use <strong>My Account</strong> in the top-right to update your identity or password.
          <?php else: ?>
            Visit <a href="https://accounts.soi.co.in" target="_blank" rel="noopener" class="admin-link">accounts.soi.co.in</a> to update your identity or password.
          <?php endif; ?>
        </div>
      </details>

      <form method="POST">
        <?= Auth::csrfField() ?>
        <div class="form-section">
          <h4 style="font-size:0.78rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:0.75rem;">From SOI Accounts (read-only)</h4>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Email</label>
              <input class="form-input" type="email" value="<?= esc($user['email'] ?? '') ?>" disabled style="opacity:0.7;">
              <span class="form-hint">Email is managed by your administrator in SOI Accounts.</span>
            </div>
            <div class="form-group">
              <label class="form-label">Name</label>
              <input class="form-input" type="text" value="<?= esc($user['display_name'] ?? '') ?>" disabled style="opacity:0.7;">
              <span class="form-hint">Synced on sign-in. Change via My Account.</span>
            </div>
            <?php if (!empty($user['accounts_user_id'])): ?>
            <div class="form-group full">
              <label class="form-label">Accounts User ID</label>
              <input class="form-input" type="text" value="<?= esc($user['accounts_user_id']) ?>" disabled style="opacity:0.7;font-family:monospace;font-size:0.82rem;">
            </div>
            <?php endif; ?>
          </div>

          <h4 style="font-size:0.78rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin:1.25rem 0 0.75rem;">Local CMS preferences</h4>
          <div class="form-row">
            <div class="form-group full">
              <label class="form-label">CMS Display Name</label>
              <input class="form-input" type="text" name="display_name" value="<?= esc($user['display_name'] ?? '') ?>" placeholder="How your name appears in this CMS">
              <span class="form-hint">Used for author bylines on this site. Identity changes still go through SOI Accounts.</span>
            </div>
            <div class="form-group full">
              <label class="form-label">Bio</label>
              <textarea class="form-textarea" name="bio" rows="3" placeholder="A short bio for this CMS site…"><?= esc($user['bio'] ?? '') ?></textarea>
            </div>
          </div>

          <div class="btn-row">
            <button type="submit" class="btn btn-primary">💾 Save Local Preferences</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3 class="card-title">Account Details</h3></div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:0.75rem;">
      <div style="text-align:center;padding:1rem 0;">
        <div style="width:72px;height:72px;border-radius:50%;background:rgba(245,148,31,0.1);border:3px solid var(--brand);display:flex;align-items:center;justify-content:center;font-size:1.8rem;font-weight:700;color:var(--brand);margin:0 auto;">
          <?= strtoupper(substr($user['display_name'] ?: ($user['username'] ?? 'U'), 0, 1)) ?>
        </div>
        <div style="margin-top:0.75rem;font-weight:700;"><?= esc($user['display_name'] ?: ($user['username'] ?? '')) ?></div>
        <div style="font-size:0.78rem;color:var(--text-muted);text-transform:capitalize;"><?= esc($user['role'] ?? '') ?></div>
      </div>
      <?php
      $details = [
          'Email'          => $user['email'] ?? '—',
          'Member Since'   => !empty($user['created_at']) ? date('M j, Y', strtotime($user['created_at'])) : '—',
          'Last Login'     => !empty($user['last_login']) ? time_ago($user['last_login']) : 'Never',
          'Account Status' => !empty($user['status']) ? 'Active' : 'Inactive',
          'CMS Role'       => ucfirst($user['role'] ?? ''),
      ];
      foreach ($details as $k => $v):
      ?>
      <div style="display:flex;justify-content:space-between;font-size:0.8rem;padding:0.35rem 0;border-bottom:1px solid var(--border);">
        <span style="color:var(--text-muted);"><?= esc($k) ?></span>
        <span style="font-weight:600;"><?= esc($v) ?></span>
      </div>
      <?php endforeach; ?>

      <a href="<?= SOI_ADMIN_URL ?>/logout.php" class="btn btn-danger btn-sm" style="margin-top:0.5rem;justify-content:center;" data-confirm="Are you sure you want to sign out?">
        🚪 Sign Out
      </a>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>