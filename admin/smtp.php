<?php
/**
 * Admin — SMTP / Email Settings
 */
$pageTitle = 'SMTP / Email Settings';
$activeNav = 'smtp';
$pageContentClass = 'page-content--fluid';

if (!defined('SOI_ROOT')) define('SOI_ROOT', dirname(__DIR__));
require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';
spl_autoload_register(fn($c) => (fn($f) => file_exists($f) && require_once $f)(SOI_ROOT.'/core/'.str_replace(['SOI\\Core\\','\\'],['','/'],$c).'.php'));
use SOI\Core\{Database, Auth, Mailer};
Database::connect(['host'=>SOI_DB_HOST,'name'=>SOI_DB_NAME,'user'=>SOI_DB_USER,'pass'=>SOI_DB_PASS,'port'=>SOI_DB_PORT,'prefix'=>SOI_DB_PREFIX]);
Auth::init();
Auth::requireAuth('admin');

// Handle SMTP test via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test') {
    header('Content-Type: application/json');
    $host  = trim($_POST['smtp_host'] ?? '');
    $port  = (int)($_POST['smtp_port'] ?? 587);
    $user  = trim($_POST['smtp_user'] ?? '');
    $pass  = $_POST['smtp_pass'] ?? '';
    $enc   = $_POST['smtp_encryption'] ?? 'tls';
    $to    = trim($_POST['smtp_from_email'] ?? $user);

    if (empty($host) || empty($user)) {
        echo json_encode(['success' => false, 'error' => 'Host and username are required.']);
        exit;
    }

    $result = Mailer::test($host, $port, $user, $pass, $enc, $to);
    echo json_encode($result);
    exit;
}

// Save SMTP settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'test') {
    if (!Auth::verifyCsrf($_POST['_csrf'] ?? '')) die('CSRF');

    $fields = ['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_encryption','smtp_from_name','smtp_from_email'];
    foreach ($fields as $f) Database::setOption($f, ($_POST[$f] ?? ''));
    soi_flash('success', 'SMTP settings saved.');
    soi_redirect(SOI_ADMIN_URL . '/smtp.php');
}

$opts = [];
$keys = ['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_encryption','smtp_from_name','smtp_from_email'];
foreach ($keys as $k) $opts[$k] = Database::getOption($k, '');

require_once __DIR__ . '/partials/header.php';
?>

<div class="settings-tabs">
  <a class="settings-tab" href="settings.php">General</a>
  <a class="settings-tab active" href="smtp.php">SMTP / Email</a>
  <a class="settings-tab" href="appearance.php">Appearance</a>
</div>

<form method="POST" id="smtp-form">
  <?= Auth::csrfField() ?>
  <div style="display:flex;flex-direction:column;gap:1.5rem;">

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">SMTP Configuration</h3>
        <span style="font-size:0.78rem;color:var(--text-muted);">Used for all outgoing emails (password reset, notifications, etc.)</span>
      </div>
      <div class="card-body">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">SMTP Host</label>
            <input class="form-input" type="text" name="smtp_host" id="smtp_host" value="<?= esc($opts['smtp_host']) ?>" placeholder="smtp.gmail.com">
          </div>
          <div class="form-group">
            <label class="form-label">SMTP Port</label>
            <input class="form-input" type="number" name="smtp_port" id="smtp_port" value="<?= esc($opts['smtp_port'] ?: '587') ?>" placeholder="587">
          </div>
          <div class="form-group">
            <label class="form-label">Username / Email</label>
            <input class="form-input" type="text" name="smtp_user" id="smtp_user" value="<?= esc($opts['smtp_user']) ?>" placeholder="you@gmail.com" autocomplete="off">
          </div>
          <div class="form-group">
            <label class="form-label">Password</label>
            <input class="form-input" type="password" name="smtp_pass" id="smtp_pass" value="<?= esc($opts['smtp_pass']) ?>" autocomplete="new-password">
            <span class="form-hint">Saved securely in database.</span>
          </div>
          <div class="form-group full">
            <label class="form-label">Encryption</label>
            <div style="display:flex;gap:1rem;margin-top:0.25rem;">
              <?php foreach(['tls' => 'TLS (Port 587 — Recommended)', 'ssl' => 'SSL (Port 465)', 'none' => 'None (Port 25 — Not Recommended)'] as $val => $label): ?>
              <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;font-size:0.85rem;">
                <input type="radio" name="smtp_encryption" id="smtp_enc_<?= $val ?>" value="<?= $val ?>" <?= ($opts['smtp_encryption']??'tls')===$val?'checked':'' ?>>
                <?= $label ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">✉️ From Address</h3></div>
      <div class="card-body">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">From Name</label>
            <input class="form-input" type="text" name="smtp_from_name" id="smtp_from_name" value="<?= esc($opts['smtp_from_name']) ?>" placeholder="Your Website Name">
          </div>
          <div class="form-group">
            <label class="form-label">From Email</label>
            <input class="form-input" type="email" name="smtp_from_email" id="smtp_from_email" value="<?= esc($opts['smtp_from_email']) ?>" placeholder="no-reply@yoursite.com">
          </div>
        </div>
      </div>
    </div>

    <details class="help-disclosure">
      <summary>Common SMTP provider settings</summary>
      <div class="help-disclosure-body">
        <div class="table-wrap">
          <table class="table--dense">
            <thead><tr><th>Provider</th><th>Host</th><th>Port</th><th>Encryption</th><th>Notes</th></tr></thead>
            <tbody>
              <tr><td>Gmail</td><td>smtp.gmail.com</td><td>587</td><td>TLS</td><td>App Password if 2FA</td></tr>
              <tr><td>Outlook</td><td>smtp.office365.com</td><td>587</td><td>TLS</td><td>Microsoft account</td></tr>
              <tr><td>Mailgun</td><td>smtp.mailgun.org</td><td>587</td><td>TLS</td><td>Transactional</td></tr>
              <tr><td>SendGrid</td><td>smtp.sendgrid.net</td><td>587</td><td>TLS</td><td>Username: apikey</td></tr>
              <tr><td>Amazon SES</td><td>email-smtp.us-east-1.amazonaws.com</td><td>587</td><td>TLS</td><td>IAM credentials</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </details>

    <!-- Test Result Placeholder -->
    <div id="smtp-test-result" class="alert" style="display:none;"></div>

    <div class="btn-row" style="border:none;padding-top:0;margin-top:0;">
      <button type="submit" class="btn btn-primary">💾 Save SMTP Settings</button>
      <button type="button" id="smtp-test-btn" class="btn btn-ghost">📨 Send Test Email</button>
    </div>

  </div>
</form>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
