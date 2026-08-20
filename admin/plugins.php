<?php
/**
 * Admin — Plugins Manager
 */
$pageTitle = 'Plugins';
$activeNav = 'plugins';

if (!defined('SOI_ROOT')) define('SOI_ROOT', dirname(__DIR__));
require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';
spl_autoload_register(fn($c) => (fn($f) => file_exists($f) && require_once $f)(SOI_ROOT.'/core/'.str_replace(['SOI\\Core\\','\\'],['','/'],$c).'.php'));
use SOI\Core\{Database, Auth, Plugin};
Database::connect(['host'=>SOI_DB_HOST,'name'=>SOI_DB_NAME,'user'=>SOI_DB_USER,'pass'=>SOI_DB_PASS,'port'=>SOI_DB_PORT,'prefix'=>SOI_DB_PREFIX]);
Auth::init();
Auth::requireAuth('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['_csrf'] ?? '')) die('CSRF');
    $postAction = $_POST['_action'] ?? '';
    $slug = trim($_POST['slug'] ?? '');

    if ($postAction === 'activate' && $slug) {
        $info = Plugin::getPluginInfo($slug);
        // Register in DB if not present
        $existing = Database::selectOne("SELECT id FROM `" . Database::prefix('plugins') . "` WHERE slug = ?", [$slug]);
        if ($existing) {
            Database::update('plugins', ['active' => 1], 'slug = ?', [$slug]);
        } else {
            Database::insert('plugins', [
                'slug'    => $slug,
                'name'    => $info['plugin_name'] ?? $slug,
                'version' => $info['version'] ?? '1.0.0',
                'active'  => 1,
            ]);
        }
        soi_flash('success', 'Plugin activated: ' . ($info['plugin_name'] ?? $slug));
        soi_redirect(SOI_ADMIN_URL . '/plugins.php');
    }

    if ($postAction === 'deactivate' && $slug) {
        Database::update('plugins', ['active' => 0], 'slug = ?', [$slug]);
        soi_flash('success', 'Plugin deactivated.');
        soi_redirect(SOI_ADMIN_URL . '/plugins.php');
    }

    if ($postAction === 'delete' && $slug) {
        Database::delete('plugins', 'slug = ?', [$slug]);
        // Delete plugin directory
        $pluginDir = SOI_ROOT . '/plugins/' . $slug;
        if (is_dir($pluginDir)) {
            // Recursively delete
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($pluginDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iter as $file) {
                $file->isDir() ? rmdir($file) : unlink($file);
            }
            rmdir($pluginDir);
        }
        soi_flash('success', 'Plugin deleted.');
        soi_redirect(SOI_ADMIN_URL . '/plugins.php');
    }
}

// Scan filesystem for plugins
$allPlugins = Plugin::scanAll();
$dbPlugins  = Database::select("SELECT slug, active FROM `" . Database::prefix('plugins') . "`");
$activeMap  = array_column($dbPlugins, 'active', 'slug');

require_once __DIR__ . '/partials/header.php';
?>

<?php if (!$allPlugins): ?>
<div class="card">
  <div class="empty-state" style="padding:2.5rem 1.5rem;">
    <div class="empty-state-title">No plugins found</div>
    <div class="empty-state-text">Install via <a href="<?= SOI_ADMIN_URL ?>/updates.php" class="admin-link">Updates</a> or add folders under <code>/plugins/</code>.</div>
  </div>
</div>

<?php else: ?>
<div class="plugin-grid">
  <?php foreach ($allPlugins as $plugin):
    $slug      = $plugin['slug'];
    $isActive  = ($activeMap[$slug] ?? 0) == 1;
  ?>
  <div class="plugin-card <?= $isActive ? 'active' : '' ?>">
    <div class="plugin-card-header">
      <div>
        <div class="plugin-card-name"><?= esc($plugin['plugin_name'] ?? $slug) ?></div>
        <div class="plugin-card-version">v<?= esc($plugin['version'] ?? '1.0.0') ?> <?php if ($plugin['author'] ?? ''): ?>· <?= esc($plugin['author']) ?><?php endif; ?></div>
      </div>
      <?php if ($isActive): ?>
        <span class="badge badge-success">Active</span>
      <?php else: ?>
        <span class="badge badge-muted">Inactive</span>
      <?php endif; ?>
    </div>
    <div class="plugin-card-desc"><?= esc($plugin['description'] ?? 'No description provided.') ?></div>
    <div class="plugin-card-footer">
      <form method="POST" style="display:inline;">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="slug" value="<?= esc($slug) ?>">
        <input type="hidden" name="_action" value="<?= $isActive ? 'deactivate' : 'activate' ?>">
        <button type="submit" class="btn <?= $isActive ? 'btn-ghost' : 'btn-success' ?> btn-sm">
          <?= $isActive ? '⏸ Deactivate' : '▶ Activate' ?>
        </button>
      </form>
      <?php if (!$isActive): ?>
      <form method="POST" style="display:inline;">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="slug" value="<?= esc($slug) ?>">
        <input type="hidden" name="_action" value="delete">
        <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete the plugin &quot;<?= esc($plugin['plugin_name'] ?? $slug) ?>&quot;? This cannot be undone.">🗑️ Delete</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<details class="help-disclosure">
  <summary>Plugin installation &amp; file structure</summary>
  <div class="help-disclosure-body">
    <p>Upload a plugin zip via <a href="<?= SOI_ADMIN_URL ?>/updates.php" class="admin-link">Updates</a>, or place folders in <code>/plugins/plugin-name/</code>.</p>
    <pre>plugins/
└── my-plugin/
    ├── plugin.php
    ├── README.md
    └── assets/</pre>
    <p style="margin-top:0.5rem;">plugin.php header:</p>
    <pre><?php echo esc("<?php
/**
 * Plugin Name: My Awesome Plugin
 * Version: 1.0.0
 * Description: Adds features to SOI CMS.
 * Author: Your Name
 */"); ?></pre>
  </div>
</details>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
