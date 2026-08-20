<?php
/**
 * Admin — General Settings
 */
$pageTitle = 'General Settings';
$activeNav = 'settings';
$pageContentClass = 'page-content--fluid';

if (!defined('SOI_ROOT')) define('SOI_ROOT', dirname(__DIR__));
require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';
spl_autoload_register(fn($c) => (fn($f) => file_exists($f) && require_once $f)(SOI_ROOT.'/core/'.str_replace(['SOI\\Core\\','\\'],['','/'],$c).'.php'));
use SOI\Core\{Database, Auth, Blog};
Database::connect(['host'=>SOI_DB_HOST,'name'=>SOI_DB_NAME,'user'=>SOI_DB_USER,'pass'=>SOI_DB_PASS,'port'=>SOI_DB_PORT,'prefix'=>SOI_DB_PREFIX]);
Auth::init();
Auth::requireAuth('admin');
Blog::ensureMigrated();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['_csrf'] ?? '')) {
        soi_flash('error', 'CSRF check failed. Reload the page and try again.');
        soi_redirect(SOI_ADMIN_URL . '/settings.php');
    }

    $fields = [
        'site_name','site_tagline','site_url','site_domain','site_slug',
        'canonical_base_url','admin_base_url','frontend_base_url','environment_label',
        'admin_email','timezone',
        'date_format','time_format','posts_per_page','maintenance_mode',
        'maintenance_message','comments_enabled',
        'home_page','blog_page',
        'sidebar_logo_text', 'sidebar_logo_size', 'sidebar_logo_icon',
    ];

    foreach ($fields as $field) {
        $val = $_POST[$field] ?? '';
        if ($field === 'maintenance_mode') $val = isset($_POST[$field]) ? '1' : '0';
        if ($field === 'comments_enabled')  $val = isset($_POST[$field]) ? '1' : '0';

        if ($field === 'site_url' || $field === 'canonical_base_url' || $field === 'admin_base_url' || $field === 'frontend_base_url') {
            $val = function_exists('soi_site_validate_base_url') ? soi_site_validate_base_url($val) : rtrim(trim((string) $val), '/');
            // Keep previous value if invalid empty overwrite of existing URL would break site.
            if ($val === '' && $field === 'site_url') {
                $val = (string) Database::getOption('site_url', defined('SOI_HOME_URL') ? SOI_HOME_URL : '');
            }
        }
        if ($field === 'site_domain') {
            $val = function_exists('soi_site_normalize_hostname') ? soi_site_normalize_hostname($val) : trim((string) $val);
        }
        if ($field === 'site_slug') {
            $val = function_exists('soi_site_sanitize_slug') ? soi_site_sanitize_slug($val, 'cms') : preg_replace('/[^a-z0-9_-]/', '', strtolower(trim((string) $val)));
        }
        if ($field === 'environment_label') {
            $val = strtolower(trim((string) $val));
            if (!in_array($val, ['production', 'staging', 'development', 'local'], true)) {
                $val = 'production';
            }
        }
        Database::setOption($field, $val);
    }

    Database::setOption('blog_enabled', isset($_POST['blog_enabled']) ? '1' : '0');

    // User registration is handled by SOI Accounts SSO — keep disabled.
    Database::setOption('registration_open', '0');

    // Sync Files Service display helpers only when empty (never wipe credentials or domain if set).
    $fsDomain = trim((string) Database::getOption('fs_conn_domain', ''));
    $newDomain = trim((string) Database::getOption('site_domain', ''));
    if ($fsDomain === '' && $newDomain !== '') {
        Database::setOption('fs_conn_domain', $newDomain);
    }

    if (class_exists(\SOI\Core\Cache::class)) {
        \SOI\Core\Cache::purgeAll();
    }

    soi_flash('success', 'Settings saved successfully.');
    soi_redirect(SOI_ADMIN_URL . '/settings.php');
}

// Load all options
$opts = [];
$keys = ['site_name','site_tagline','site_url','site_domain','site_slug',
         'canonical_base_url','admin_base_url','frontend_base_url','environment_label',
         'admin_email','timezone','date_format','time_format',
         'posts_per_page','maintenance_mode','maintenance_message','comments_enabled',
         'home_page','blog_page', 'sidebar_logo_text', 'sidebar_logo_size', 'sidebar_logo_icon', 'blog_enabled'];
foreach ($keys as $k) $opts[$k] = Database::getOption($k, '');
$identityPreview = function_exists('soi_site_identity') ? soi_site_identity() : [];
$perfChecklist = [
    'lazy' => true,
    'skeleton' => true,
    'admin_media_lazy' => is_file(SOI_ROOT . '/admin/assets/admin-performance.js'),
    'frontend_perf_js' => is_file((defined('SOI_ROOT') ? SOI_ROOT : dirname(__DIR__)) . '/themes/default/performance.js'),
    'frontend_perf_css' => is_file((defined('SOI_ROOT') ? SOI_ROOT : dirname(__DIR__)) . '/themes/default/performance.css'),
    'content_filter' => function_exists('soi_perf_enhance_media_html'),
];

$blogEnabled = Blog::isEnabled();

$pages = Database::select("SELECT id, title FROM `" . Database::prefix('pages') . "` WHERE status='published' ORDER BY title");

require_once __DIR__ . '/partials/header.php';
?>

<div class="settings-tabs">
  <a class="settings-tab active" href="settings.php">General</a>
  <a class="settings-tab" href="smtp.php">SMTP / Email</a>
  <a class="settings-tab" href="appearance.php">Appearance</a>
</div>

<form method="POST">
  <?= Auth::csrfField() ?>
  <div style="display:flex;flex-direction:column;gap:1.5rem;">

    <!-- Site Identity -->
    <div class="card">
      <div class="card-header"><h3 class="card-title">Site Identity</h3></div>
      <div class="card-body">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Site Name</label>
            <input class="form-input" type="text" name="site_name" value="<?= esc($opts['site_name']) ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">Tagline</label>
            <input class="form-input" type="text" name="site_tagline" value="<?= esc($opts['site_tagline']) ?>" placeholder="Just another great website">
          </div>
          <div class="form-group">
            <label class="form-label">Site Slug</label>
            <input class="form-input" type="text" name="site_slug" value="<?= esc($opts['site_slug'] !== '' ? $opts['site_slug'] : (string) ($identityPreview['site_slug'] ?? '')) ?>" pattern="[a-z0-9][a-z0-9_-]{0,63}" placeholder="e.g. search, doctors, school">
            <span class="form-hint">Used as Files Service requested_app_id for new connections. Do not reuse another site’s secrets.</span>
          </div>
          <div class="form-group">
            <label class="form-label">Site Domain</label>
            <input class="form-input" type="text" name="site_domain" value="<?= esc($opts['site_domain'] !== '' ? $opts['site_domain'] : (string) ($identityPreview['site_domain'] ?? '')) ?>" placeholder="e.g. example.soi.co.in">
            <span class="form-hint">Hostname only (no https:// or path).</span>
          </div>
          <div class="form-group full">
            <label class="form-label">Site URL</label>
            <input class="form-input" type="url" name="site_url" value="<?= esc($opts['site_url']) ?>" required>
            <span class="form-hint">The public URL of your website (no trailing slash).</span>
          </div>
          <div class="form-group">
            <label class="form-label">Canonical Base URL</label>
            <input class="form-input" type="url" name="canonical_base_url" value="<?= esc($opts['canonical_base_url']) ?>" placeholder="Leave blank to use Site URL">
          </div>
          <div class="form-group">
            <label class="form-label">Admin Base URL</label>
            <input class="form-input" type="url" name="admin_base_url" value="<?= esc($opts['admin_base_url']) ?>" placeholder="Leave blank to use Site URL + /admin">
          </div>
          <div class="form-group">
            <label class="form-label">Frontend Base URL</label>
            <input class="form-input" type="url" name="frontend_base_url" value="<?= esc($opts['frontend_base_url']) ?>" placeholder="Leave blank to use Site URL">
          </div>
          <div class="form-group">
            <label class="form-label">Environment</label>
            <select class="form-input" name="environment_label">
              <?php foreach (['production','staging','development','local'] as $env): ?>
              <option value="<?= esc($env) ?>" <?= ($opts['environment_label'] ?: 'production') === $env ? 'selected' : '' ?>><?= esc(ucfirst($env)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group full">
            <label class="form-label">Admin Email</label>
            <input class="form-input" type="email" name="admin_email" value="<?= esc($opts['admin_email']) ?>">
          </div>
        </div>
        <?php if ($identityPreview): ?>
        <p class="form-hint" style="margin-top:0.75rem;">Resolved identity: <strong><?= esc((string) ($identityPreview['site_name'] ?? '')) ?></strong> · <code><?= esc((string) ($identityPreview['site_domain'] ?? '')) ?></code> · slug <code><?= esc((string) ($identityPreview['site_slug'] ?? '')) ?></code></p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Frontend performance checklist (v1.2.10.1) -->
    <div class="card">
      <div class="card-header"><h3 class="card-title">Performance Checklist</h3></div>
      <div class="card-body">
        <p class="form-hint" style="margin-top:0;">Lightweight progressive loading status. Does not change Files Service access rules or rewrite content.</p>
        <ul class="perf-check-list" style="list-style:none;padding:0;margin:0;display:grid;gap:0.45rem;">
          <li><span class="<?= !empty($perfChecklist['lazy']) ? 'perf-check-ok' : 'perf-check-note' ?>"><?= !empty($perfChecklist['lazy']) ? '✓' : '·' ?></span> Lazy loading enabled for frontend media content filter</li>
          <li><span class="<?= !empty($perfChecklist['skeleton']) && !empty($perfChecklist['frontend_perf_css']) ? 'perf-check-ok' : 'perf-check-note' ?>"><?= !empty($perfChecklist['frontend_perf_css']) ? '✓' : '·' ?></span> Skeleton loading CSS present (theme)</li>
          <li><span class="<?= !empty($perfChecklist['frontend_perf_js']) ? 'perf-check-ok' : 'perf-check-note' ?>"><?= !empty($perfChecklist['frontend_perf_js']) ? '✓' : '·' ?></span> Progressive media JS present (deferred)</li>
          <li><span class="<?= !empty($perfChecklist['admin_media_lazy']) ? 'perf-check-ok' : 'perf-check-note' ?>"><?= !empty($perfChecklist['admin_media_lazy']) ? '✓' : '·' ?></span> Admin Media Library lazy thumbnails enabled</li>
          <li><span class="perf-check-note">·</span> Server recommendations: OPcache, Brotli/Gzip, HTTP/2+, static cache headers (manual — see release docs)</li>
        </ul>
        <style>.perf-check-ok{color:#15803d;font-weight:600;margin-right:0.35rem}.perf-check-note{color:#64748b;margin-right:0.35rem}</style>
      </div>
    </div>

    <!-- Sidebar Customization -->
    <div class="card">
      <div class="card-header"><h3 class="card-title">Sidebar Logo</h3></div>
      <div class="card-body">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Sidebar Logo Text</label>
            <input class="form-input" type="text" name="sidebar_logo_text" value="<?= esc($opts['sidebar_logo_text']) ?>" placeholder="Leave blank to use Site Name">
          </div>
          <div class="form-group">
            <label class="form-label">Sidebar Logo Size</label>
            <select class="form-input" name="sidebar_logo_size">
              <option value="1.05rem" <?= $opts['sidebar_logo_size'] === '1.05rem' || empty($opts['sidebar_logo_size']) ? 'selected' : '' ?>>Default</option>
              <option value="0.9rem" <?= $opts['sidebar_logo_size'] === '0.9rem' ? 'selected' : '' ?>>Small</option>
              <option value="1.25rem" <?= $opts['sidebar_logo_size'] === '1.25rem' ? 'selected' : '' ?>>Large</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Sidebar Logo Icon</label>
            <select class="form-input" name="sidebar_logo_icon">
              <option value="brand" <?= $opts['sidebar_logo_icon'] === 'brand' || empty($opts['sidebar_logo_icon']) ? 'selected' : '' ?>>Brand Icon</option>
              <option value="layers" <?= $opts['sidebar_logo_icon'] === 'layers' ? 'selected' : '' ?>>Layers</option>
              <option value="search" <?= $opts['sidebar_logo_icon'] === 'search' ? 'selected' : '' ?>>Search</option>
              <option value="dashboard" <?= $opts['sidebar_logo_icon'] === 'dashboard' ? 'selected' : '' ?>>Dashboard</option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <!-- Modules -->
    <div class="card">
      <div class="card-header"><h3 class="card-title">CMS Modules</h3></div>
      <div class="card-body">
        <label class="toggle-wrap">
          <span class="toggle-switch"><input type="checkbox" name="blog_enabled" value="1" <?= ($opts['blog_enabled'] ?? '0') === '1' ? 'checked' : '' ?>><span class="toggle-slider"></span></span>
          <span class="toggle-label">Enable Blog Module</span>
        </label>
        <p style="font-size:0.8rem;color:var(--text-muted);margin-top:0.75rem;line-height:1.6;">
          When disabled, the CMS runs as a blank vanilla core: no Posts menu, no blog routes, and no post listing on the homepage.
          Existing post data is preserved and becomes available again when you enable this setting.
        </p>
      </div>
    </div>

    <!-- Reading -->
    <div class="card">
      <div class="card-header"><h3 class="card-title">Reading Settings</h3></div>
      <div class="card-body">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Homepage</label>
            <select class="form-select" name="home_page">
              <?php if ($blogEnabled): ?>
              <option value="">Latest Posts</option>
              <?php else: ?>
              <option value="">Application landing (default)</option>
              <?php endif; ?>
              <?php foreach ($pages as $page): ?>
              <option value="<?= $page['id'] ?>" <?= $opts['home_page'] == $page['id'] ? 'selected' : '' ?>><?= esc($page['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if ($blogEnabled): ?>
          <div class="form-group">
            <label class="form-label">Posts Per Page</label>
            <input class="form-input" type="number" name="posts_per_page" value="<?= esc($opts['posts_per_page'] ?: '10') ?>" min="1" max="100">
          </div>
          <?php else: ?>
          <input type="hidden" name="posts_per_page" value="<?= esc($opts['posts_per_page'] ?: '10') ?>">
          <?php endif; ?>
        </div>
        <?php if ($blogEnabled): ?>
        <div style="display:flex;flex-direction:column;gap:0.75rem;margin-top:1rem;">
          <label class="toggle-wrap">
            <span class="toggle-switch"><input type="checkbox" name="comments_enabled" value="1" <?= $opts['comments_enabled']==='1'?'checked':'' ?>><span class="toggle-slider"></span></span>
            <span class="toggle-label">Enable comments on posts</span>
          </label>
        </div>
        <?php else: ?>
        <input type="hidden" name="comments_enabled" value="<?= esc($opts['comments_enabled'] ?: '0') ?>">
        <?php endif; ?>
        <p style="font-size:0.8rem;color:var(--text-muted);margin-top:0.75rem;">
          User access is managed through SOI Accounts SSO. Local self-registration is disabled.
        </p>
      </div>
    </div>

    <!-- Date & Time -->
    <div class="card">
      <div class="card-header"><h3 class="card-title">Date & Time</h3></div>
      <div class="card-body">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Timezone</label>
            <select class="form-select" name="timezone">
              <?php foreach (timezone_identifiers_list() as $tz): ?>
              <option value="<?= $tz ?>" <?= $opts['timezone']===$tz?'selected':'' ?>><?= $tz ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Date Format</label>
            <input class="form-input" type="text" name="date_format" value="<?= esc($opts['date_format'] ?: 'F j, Y') ?>" placeholder="F j, Y">
            <span class="form-hint">PHP date format. Current: <?= date($opts['date_format'] ?: 'F j, Y') ?></span>
          </div>
          <div class="form-group">
            <label class="form-label">Time Format</label>
            <input class="form-input" type="text" name="time_format" value="<?= esc($opts['time_format'] ?: 'H:i') ?>" placeholder="H:i">
            <span class="form-hint">Current: <?= date($opts['time_format'] ?: 'H:i') ?></span>
          </div>
        </div>
      </div>
    </div>

    <!-- Maintenance Mode -->
    <div class="card" style="border-color:rgba(245,158,11,0.2);">
      <div class="card-header"><h3 class="card-title">Maintenance Mode</h3></div>
      <div class="card-body">
        <label class="toggle-wrap" style="margin-bottom:1rem;">
          <span class="toggle-switch"><input type="checkbox" name="maintenance_mode" value="1" <?= $opts['maintenance_mode']==='1'?'checked':'' ?>><span class="toggle-slider"></span></span>
          <span class="toggle-label" style="color:<?= $opts['maintenance_mode']==='1'?'var(--warning)':'inherit' ?>;">
            Enable Maintenance Mode <?= $opts['maintenance_mode']==='1' ? '(Public site hidden from visitors)' : '' ?>
          </span>
        </label>
        <p style="font-size:0.8rem;color:var(--text-muted);margin-bottom:1rem;line-height:1.6;">
          Admin Center, SAML login, Update Center, and required admin assets remain accessible so administrators can sign in and apply fixes.
        </p>
        <div class="form-group">
          <label class="form-label">Maintenance Message</label>
          <textarea class="form-textarea" name="maintenance_message" rows="2"><?= esc($opts['maintenance_message'] ?: 'We are under maintenance. Please check back soon.') ?></textarea>
        </div>
      </div>
    </div>

    <div class="btn-row" style="border:none;margin-top:0;padding-top:0;">
      <button type="submit" class="btn btn-primary">💾 Save Settings</button>
    </div>
  </div>
</form>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
