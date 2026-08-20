<?php
/**
 * Admin — Appearance (Theme Selector)
 */
$pageTitle = 'Appearance';
$activeNav = 'appearance';

if (!defined('SOI_ROOT')) define('SOI_ROOT', dirname(__DIR__));
require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';
spl_autoload_register(fn($c) => (fn($f) => file_exists($f) && require_once $f)(SOI_ROOT.'/core/'.str_replace(['SOI\\Core\\','\\'],['','/'],$c).'.php'));
use SOI\Core\{Database, Auth};
Database::connect(['host'=>SOI_DB_HOST,'name'=>SOI_DB_NAME,'user'=>SOI_DB_USER,'pass'=>SOI_DB_PASS,'port'=>SOI_DB_PORT,'prefix'=>SOI_DB_PREFIX]);
Auth::init();
Auth::requireAuth('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['_csrf'] ?? '')) die('CSRF');
    $theme = trim($_POST['theme'] ?? '');
    if ($theme && is_dir(SOI_ROOT . '/themes/' . $theme)) {
        Database::setOption('active_theme', $theme);
        soi_flash('success', 'Theme activated: ' . $theme);
    } else {
        soi_flash('error', 'Invalid theme.');
    }
    soi_redirect(SOI_ADMIN_URL . '/appearance.php');
}

// Scan themes directory
$activeTheme = Database::getOption('active_theme', 'default');
$themes = [];
$themesDir = SOI_ROOT . '/themes';
if (is_dir($themesDir)) {
    foreach (scandir($themesDir) as $dir) {
        if ($dir === '.' || $dir === '..') continue;
        if (!is_dir("$themesDir/$dir")) continue;
        $info = ['slug' => $dir, 'name' => ucwords(str_replace(['-','_'], ' ', $dir)), 'version' => '1.0.0', 'author' => '', 'description' => ''];
        // Parse style.css for metadata
        $styleFile = "$themesDir/$dir/style.css";
        if (file_exists($styleFile)) {
            $css = file_get_contents($styleFile);
            $fields = ['Theme Name' => 'name', 'Version' => 'version', 'Author' => 'author', 'Description' => 'description'];
            foreach ($fields as $meta => $key) {
                if (preg_match('/\*\s+' . preg_quote($meta) . ':\s+(.+)/i', $css, $m)) {
                    $info[$key] = trim($m[1]);
                }
            }
        }
        $themes[] = $info;
    }
}

require_once __DIR__ . '/partials/header.php';
?>

<div class="settings-tabs">
  <a class="settings-tab" href="settings.php">General</a>
  <a class="settings-tab" href="smtp.php">SMTP / Email</a>
  <a class="settings-tab active" href="appearance.php">Appearance</a>
</div>

<p class="page-toolbar-meta">Active theme: <strong><?= esc($activeTheme) ?></strong></p>

<div class="theme-grid">
  <?php foreach ($themes as $theme):
    $isCurrent = $theme['slug'] === $activeTheme;
    $previewEmoji = ['default'=>'🖥️','dark'=>'🌑','minimal'=>'⬜','blog'=>'📰','magazine'=>'🗞️'][$theme['slug']] ?? '🎨';
  ?>
  <div class="theme-card <?= $isCurrent ? 'active-theme' : '' ?>">
    <div class="theme-preview">
      <span style="font-size:3rem;"><?= $previewEmoji ?></span>
    </div>
    <div class="theme-info">
      <div class="theme-name"><?= esc($theme['name']) ?></div>
      <div class="theme-meta">
        v<?= esc($theme['version']) ?>
        <?php if ($theme['author']): ?> · <?= esc($theme['author']) ?><?php endif; ?>
      </div>
      <?php if ($theme['description']): ?>
      <div style="font-size:0.77rem;color:var(--text-muted);margin-top:0.3rem;"><?= esc($theme['description']) ?></div>
      <?php endif; ?>
      <?php if ($isCurrent): ?>
        <div class="theme-active-badge">✓ Active Theme</div>
      <?php else: ?>
        <form method="POST" style="margin-top:0.6rem;">
          <?= Auth::csrfField() ?>
          <input type="hidden" name="theme" value="<?= esc($theme['slug']) ?>">
          <button type="submit" class="btn btn-primary btn-sm">Activate</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if (!$themes): ?>
<div class="empty-state">
  <div class="empty-state-icon">🎨</div>
  <div class="empty-state-title">No themes found</div>
  <div class="empty-state-text">Drop theme folders in <code>/themes/</code> directory.</div>
</div>
<?php endif; ?>

<details class="help-disclosure">
  <summary>Theme installation &amp; file structure</summary>
  <div class="help-disclosure-body">
    <p>Install via <a href="<?= SOI_ADMIN_URL ?>/updates.php" class="admin-link">Updates</a> or add folders under <code>/themes/</code>.</p>
    <pre>themes/
└── my-theme/
    ├── style.css
    ├── functions.php
    ├── index.php
    ├── page.php
    ├── post.php
    └── header.php / footer.php</pre>
    <p style="margin-top:0.5rem;">style.css header:</p>
    <pre>/*
 * Theme Name: My Beautiful Theme
 * Version: 1.0.0
 * Author: Your Name
 * Description: Theme description
 */</pre>
  </div>
</details>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
