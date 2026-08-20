<?php
/**
 * Admin — Server Security & Hotlink Protection
 */
$pageTitle = 'Server Security';
$activeNav = 'security';
$pageContentClass = 'page-content--fluid';

if (!defined('SOI_ROOT')) define('SOI_ROOT', dirname(__DIR__));
require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';
spl_autoload_register(fn($c) => (fn($f) => file_exists($f) && require_once $f)(SOI_ROOT.'/core/'.str_replace(['SOI\\Core\\','\\'],['','/'],$c).'.php'));
use SOI\Core\{Database, Auth, Security};

Database::connect(['host'=>SOI_DB_HOST,'name'=>SOI_DB_NAME,'user'=>SOI_DB_USER,'pass'=>SOI_DB_PASS,'port'=>SOI_DB_PORT,'prefix'=>SOI_DB_PREFIX]);
Auth::init();
Auth::requireAuth('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['_csrf'] ?? '')) die('CSRF');
    
    $enabled = isset($_POST['hotlink_enabled']);
    $blockDirect = isset($_POST['hotlink_block_direct']);
    $domains = $_POST['hotlink_domains'] ?? '';
    $extensions = $_POST['hotlink_extensions'] ?? '';

    if (Security::updateHotlinkSettings($enabled, $blockDirect, $domains, $extensions)) {
        soi_flash('success', 'Security settings updated successfully. Server configuration applied.');
    } else {
        soi_flash('error', 'Settings saved, but failed to write to .htaccess. Check file permissions.');
    }
    soi_redirect(SOI_ADMIN_URL . '/security.php');
}

$settings = Security::getHotlinkSettings();
$nginxRules = Security::getNginxRules();

require_once __DIR__ . '/partials/header.php';
?>

<div class="workspace-grid workspace-grid--split">
  <div class="workspace-main">
    <div class="card">
      <div class="card-header">
        <h2 class="card-title">Hotlink Protection</h2>
      </div>
      <div class="card-body">
        <form method="POST">
          <?= Auth::csrfField() ?>
          <div class="form-section">
            <label class="form-switch-wrap form-switch-wrap--success">
              <input type="checkbox" id="hotlink_enabled" name="hotlink_enabled" value="1" <?= $settings['enabled'] ? 'checked' : '' ?>>
              <div>
                <span class="form-switch-label">Enable Hotlink Protection</span>
                <span class="form-switch-desc">Writes server configuration to block unauthorized external requests to static uploads.</span>
              </div>
            </label>

            <label class="form-switch-wrap">
              <input type="checkbox" name="hotlink_block_direct" value="1" <?= $settings['block_direct'] ? 'checked' : '' ?>>
              <div>
                <span class="form-switch-label">Strict Mode: Block Direct Access (Empty Referers)</span>
                <span class="form-switch-desc">Blocks direct URL entry in browsers. Only allowed domains can load assets.</span>
              </div>
            </label>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="hotlink_domains">Allowed Domains (Whitelist)</label>
                <input type="text" id="hotlink_domains" name="hotlink_domains" class="form-input" value="<?= esc($settings['allowed_domains']) ?>" placeholder="e.g. example.soi.co.in, www.example.com">
                <small class="form-hint">Include <code><?= esc($_SERVER['HTTP_HOST'] ?? '') ?></code> and any CDN domains.</small>
              </div>
              <div class="form-group">
                <label class="form-label" for="hotlink_extensions">Protected File Extensions</label>
                <input type="text" id="hotlink_extensions" name="hotlink_extensions" class="form-input" value="<?= esc($settings['extensions']) ?>" placeholder="e.g. jpg, png, mp4, zip">
                <small class="form-hint">Comma-separated extensions for server-level hotlink rules.</small>
              </div>
            </div>
          </div>
          <button type="submit" class="btn btn-primary" style="margin-top:1rem;">Save &amp; Apply Configuration</button>
        </form>
      </div>
    </div>
  </div>

  <aside class="workspace-rail">
    <?php if ($settings['enabled']): ?>
    <div class="card">
      <div class="card-header"><h2 class="card-title">Server Status</h2></div>
      <div class="card-body">
        <div class="status-ok">Rules applied to <code>.htaccess</code></div>
      </div>
    </div>
    <details class="help-disclosure">
      <summary>Platform configuration reference</summary>
      <div class="help-disclosure-body">
        <p><strong>OpenLiteSpeed / CyberPanel</strong> — Virtual Host → Rewrite → Rewrite Rules:</p>
        <pre>RewriteRule ^/uploads/ - [F,L]
RewriteRule ^/updates/ - [F,L]</pre>
        <p style="margin-top:0.75rem;"><strong>NGINX</strong> — inside <code>server { }</code>:</p>
        <pre><?= esc($nginxRules) ?></pre>
      </div>
    </details>
    <?php endif; ?>

    <details class="help-disclosure">
      <summary>About hotlink protection</summary>
      <div class="help-disclosure-body">
        <p>Blocks unauthorized external embedding of static uploads. Media is served via <code>/media/view/{hash}</code>. This is bandwidth control, not authentication.</p>
      </div>
    </details>
  </aside>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>