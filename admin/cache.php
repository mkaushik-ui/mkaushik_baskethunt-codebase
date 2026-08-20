<?php
/**
 * Admin — LiteSpeed Cache Management
 */
$pageTitle = 'Cache Management';
$activeNav = 'cache';
$pageContentClass = 'page-content--fluid';

if (!defined('SOI_ROOT')) define('SOI_ROOT', dirname(__DIR__));
require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';
spl_autoload_register(fn($c) => (fn($f) => file_exists($f) && require_once $f)(SOI_ROOT.'/core/'.str_replace(['SOI\\Core\\','\\'],['','/'],$c).'.php'));
use SOI\Core\{Database, Auth, Cache};

Database::connect(['host'=>SOI_DB_HOST,'name'=>SOI_DB_NAME,'user'=>SOI_DB_USER,'pass'=>SOI_DB_PASS,'port'=>SOI_DB_PORT,'prefix'=>SOI_DB_PREFIX]);
Auth::init();
Auth::requireAuth('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['_csrf'] ?? '')) die('CSRF');
    
    $action = $_POST['_action'] ?? '';

    if ($action === 'save_settings') {
        $enabled = isset($_POST['litespeed_cache_enabled']) ? '1' : '0';
        $ttl = (int)($_POST['litespeed_cache_ttl'] ?? 28800);
        
        Database::setOption('litespeed_cache_enabled', $enabled);
        Database::setOption('litespeed_cache_ttl', (string)$ttl);
        
        if ($enabled === '0') {
            // Purge everything if disabled to clear old caches
            header('X-LiteSpeed-Purge: *');
        }

        soi_flash('success', 'Cache settings saved successfully.');
    } elseif ($action === 'purge_all') {
        header('X-LiteSpeed-Purge: *');
        soi_flash('success', 'Purge All signal sent to LiteSpeed server.');
    }

    soi_redirect(SOI_ADMIN_URL . '/cache.php');
}

$isEnabled = (bool) Database::getOption('litespeed_cache_enabled', '0');
$ttl = (int) Database::getOption('litespeed_cache_ttl', '28800');

require_once __DIR__ . '/partials/header.php';
?>

<div class="workspace-grid workspace-grid--split">

  <div class="workspace-main">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">🚀 LiteSpeed Page Caching</h3>
            </div>
            <div class="card-body">
                <p style="color:var(--text-muted); margin-bottom:1.5rem;">
                    Server-level caching significantly improves the performance of your website by storing the generated HTML of your pages and serving them instantly from memory/disk, bypassing PHP and the database entirely.
                </p>

                <form method="POST">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="_action" value="save_settings">

                    <div class="form-section">
                        <label class="form-switch-wrap<?= $isEnabled ? ' form-switch-wrap--success' : '' ?>">
                            <input type="checkbox" id="litespeed_cache_enabled" name="litespeed_cache_enabled" value="1" <?= $isEnabled ? 'checked' : '' ?>>
                            <div>
                                <span class="form-switch-label">Enable LiteSpeed Caching</span>
                                <span class="form-switch-desc">Turn on caching for public visitors. Administrators are automatically bypassed.</span>
                            </div>
                        </label>

                        <div class="form-group">
                            <label class="form-label">Default Public Cache TTL (Seconds)</label>
                            <input type="number" name="litespeed_cache_ttl" class="form-input" value="<?= $ttl ?>" min="60">
                            <small class="form-hint">Time To Live. Default is 28800 (8 hours). 604800 is 1 week.</small>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="margin-top:1.5rem;">💾 Save Settings</button>
                </form>
            </div>
        </div>

  </div>

  <aside class="workspace-rail">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🧹 Purge Cache</h3>
        </div>
        <div class="card-body">
            <p style="font-size:0.85rem; color:var(--text-muted); margin-bottom:1.25rem;">
                If you made changes directly to the database or files outside of the CMS, you may need to manually purge the cache so visitors see the updated content.
            </p>

            <form method="POST">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="_action" value="purge_all">
                <button type="submit" class="btn btn-danger" style="width:100%; justify-content:center;" <?= !$isEnabled ? 'disabled' : '' ?>>
                    🗑️ Purge All Caches
                </button>
            </form>

            <?php if (!$isEnabled): ?>
            <p style="font-size:0.75rem; color:var(--warning); margin-top:1rem; text-align:center;">
                Caching is currently disabled. Purge is unavailable.
            </p>
            <?php endif; ?>
        </div>
    </div>

    <details class="help-disclosure">
      <summary>About LiteSpeed caching</summary>
      <div class="help-disclosure-body">
        <p>Server-level caching stores generated HTML for public visitors. Admin sessions bypass cache automatically.</p>
        <p style="margin-top:0.75rem;">Purge after direct database or file changes. Default TTL is 28800s (8h); 604800s = 1 week.</p>
      </div>
    </details>
  </aside>

</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
