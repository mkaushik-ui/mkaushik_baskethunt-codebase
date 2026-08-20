<?php
/**
 * Admin Panel Partials — Shared Header
 * Include at top of every admin page: require_once __DIR__ . '/partials/header.php';
 * Set $pageTitle and $activeNav before including.
 */
if (!defined('SOI_ROOT')) {
    define('SOI_ROOT', dirname(__DIR__, 2));
}
if (!defined('SOI_VERSION')) {
    define('SOI_VERSION', '1.0.3');
}
if (!defined('SOI_ADMIN_ASSET_VERSION')) {
    define('SOI_ADMIN_ASSET_VERSION', '1.0.12-live-session-sync');
}

if (!file_exists(SOI_ROOT . '/config/config.php')) {
    header('Location: ../install/index.php');
    exit;
}

require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';

// Autoload core classes
spl_autoload_register(function (string $class) {
    $file = SOI_ROOT . '/core/' . str_replace(['SOI\\Core\\', '\\'], ['', '/'], $class) . '.php';
    if (file_exists($file)) require_once $file;
});

use SOI\Core\{Database, Auth, Accounts, Plugin, SoiCentralAuth, Blog};

// Connect DB & start session
Database::connect([
    'host'   => SOI_DB_HOST,
    'name'   => SOI_DB_NAME,
    'user'   => SOI_DB_USER,
    'pass'   => SOI_DB_PASS,
    'port'   => SOI_DB_PORT,
    'prefix' => SOI_DB_PREFIX,
]);
Blog::ensureMigrated();
Auth::init();
SoiCentralAuth::install();
Auth::requireAccountsLinked();
Auth::requireAuth('author'); // All admin pages require at least 'author' role

$siteName  = Database::getOption('site_name', 'SOI (School Of Interns) CMS');
$displayVersion = (string) Database::getOption('cms_version', SOI_VERSION);
$pageTitle = $pageTitle ?? 'Dashboard';
$activeNav = $activeNav ?? '';
$pageContentClass = $pageContentClass ?? '';

$flashes   = soi_get_flash();
$user      = Auth::user() ?? [];
$adminSearchToken = class_exists(\SOI\Core\AdminSearch::class)
    ? \SOI\Core\AdminSearch::issueToken($user)
    : '';
$userDisplayName = trim((string) ($user['display_name'] ?? $user['username'] ?? 'Admin'));
$userEmail = trim((string) ($user['email'] ?? ''));
$userLabel = $userDisplayName !== '' ? $userDisplayName : ($userEmail !== '' ? $userEmail : 'Admin');
$userInitial = strtoupper(substr($userLabel !== '' ? $userLabel : 'A', 0, 1));
$userCentralProfile = is_array($user['soi_central_profile'] ?? null) ? $user['soi_central_profile'] : [];
$userAvatarUrl = '';
foreach (['avatar_url', 'profile_photo_url', 'photo_url', 'picture', 'image'] as $avatarKey) {
    $candidate = trim((string) ($userCentralProfile[$avatarKey] ?? ''));
    if ($candidate !== '' && (preg_match('#^(https?:)?//#i', $candidate) || str_starts_with($candidate, '/'))) {
        $userAvatarUrl = $candidate;
        break;
    }
}
$accountsCreds  = Accounts::getStoredCredentials();
$accountsApiKey = $accountsCreds['api_key'] ?? '';
$useCentralEmbed = class_exists(SoiCentralAuth::class) && SoiCentralAuth::shouldRenderEmbed();

$sidebarLogoutUrl = class_exists(SoiCentralAuth::class)
    ? SoiCentralAuth::cmsLogoutUrl()
    : SOI_ADMIN_URL . '/logout.php';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= esc($pageTitle) ?> — <?= esc($siteName) ?> Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= esc(soi_admin_asset_url('admin.css')) ?>?v=<?= SOI_ADMIN_ASSET_VERSION ?>">
<?php
    $myAccountAppId = class_exists(SoiCentralAuth::class) ? SoiCentralAuth::appId() : '';
    $myAccountEmbedKey = (string) Database::getOption('soi_central_public_embed_key', Database::getOption('my_account_public_key', ''));
    $myAccountReturn = class_exists(SoiCentralAuth::class) ? SoiCentralAuth::currentUrl() : SOI_ADMIN_URL;
?>
<script src="https://accounts.soi.co.in/public/embed/my-account.js" data-container="#soi-my-account-widget" data-app-id="<?= esc($myAccountAppId) ?>" data-embed-key="<?= esc($myAccountEmbedKey) ?>" data-return-url="<?= esc($myAccountReturn) ?>" defer></script>
<?php if (class_exists(SoiCentralAuth::class)) SoiCentralAuth::renderSyncScript(); ?>
<?php
$customWidgetCss = (string) Database::getOption('soi_central_widget_custom_css', '');
if ($customWidgetCss !== ''):
?>
<style>
/* SOI Central Widget Custom CSS */
<?= $customWidgetCss ?>
</style>
<?php endif; ?>
<?php if (isset($extraHead)) echo $extraHead; ?>
<?php
$isAuthoringMode = str_contains((string) ($bodyClass ?? ''), 'kc-authoring');
?>
</head>
<body class="<?= esc(trim((string) ($bodyClass ?? ''))) ?>" data-admin-assets-url="<?= esc(rtrim(soi_admin_asset_url(''), '/')) ?>">
<div class="admin-sidebar-overlay" aria-hidden="true"></div>
<div class="admin-layout">

<?php if (!$isAuthoringMode): ?>
<!-- Sidebar -->
<aside class="sidebar" id="admin-sidebar" role="navigation" aria-label="Admin navigation">
<?php
  $sidebarLogoText = Database::getOption('sidebar_logo_text', '');
  if ($sidebarLogoText === '') $sidebarLogoText = $siteName;
  $sidebarLogoSize = Database::getOption('sidebar_logo_size', '1.05rem');
  $sidebarLogoIcon = Database::getOption('sidebar_logo_icon', 'brand');
?>
  <a class="sidebar-brand" href="<?= SOI_HOME_URL ?>/admin/" style="position: sticky; top: 0; background: var(--sidebar-bg); z-index: 10;">
    <span class="sidebar-brand-icon"><?= soi_admin_icon($sidebarLogoIcon, 18) ?></span>
    <div>
      <div class="sidebar-brand-text" style="font-size: <?= esc($sidebarLogoSize) ?>; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 160px;" title="<?= esc($sidebarLogoText) ?>"><?= esc($sidebarLogoText) ?></div>
    </div>
  </a>

  <nav class="sidebar-nav">
    <div class="sidebar-section">Overview</div>
    <a class="nav-item <?= $activeNav === 'dashboard' ? 'active' : '' ?>" href="<?= SOI_ADMIN_URL ?>/" <?= $activeNav === 'dashboard' ? 'aria-current="page"' : '' ?>>
      <?= soi_admin_icon('dashboard') ?> Dashboard
    </a>

    <div class="sidebar-section">Content</div>
    <a class="nav-item <?= $activeNav === 'pages' ? 'active' : '' ?>" href="<?= SOI_ADMIN_URL ?>/pages.php" <?= $activeNav === 'pages' ? 'aria-current="page"' : '' ?>>
      <?= soi_admin_icon('pages') ?> Pages
    </a>
    <?php if (Blog::isEnabled()): ?>
    <a class="nav-item <?= $activeNav === 'posts' ? 'active' : '' ?>" href="<?= SOI_ADMIN_URL ?>/posts.php" <?= $activeNav === 'posts' ? 'aria-current="page"' : '' ?>>
      <?= soi_admin_icon('posts') ?> Posts
    </a>
    <?php endif; ?>
    <a class="nav-item <?= $activeNav === 'media' ? 'active' : '' ?>" href="<?= SOI_ADMIN_URL ?>/media.php" <?= $activeNav === 'media' ? 'aria-current="page"' : '' ?>>
      <?= soi_admin_icon('media') ?> Media
    </a>
    <a class="nav-item <?= $activeNav === 'menus' ? 'active' : '' ?>" href="<?= SOI_ADMIN_URL ?>/menus.php" <?= $activeNav === 'menus' ? 'aria-current="page"' : '' ?>>
      <?= soi_admin_icon('menus') ?> Menus
    </a>

    <div class="sidebar-section">Identity</div>
    <a class="nav-item <?= $activeNav === 'users' ? 'active' : '' ?>" href="<?= SOI_ADMIN_URL ?>/users.php" <?= $activeNav === 'users' ? 'aria-current="page"' : '' ?>>
      <?= soi_admin_icon('users') ?> Users
    </a>
    <a class="nav-item <?= $activeNav === 'profile' ? 'active' : '' ?>" href="<?= SOI_ADMIN_URL ?>/profile.php" <?= $activeNav === 'profile' ? 'aria-current="page"' : '' ?>>
      <?= soi_admin_icon('profile') ?> My Profile
    </a>

    <div class="sidebar-section">Site</div>
    <a class="nav-item <?= $activeNav === 'appearance' ? 'active' : '' ?>" href="<?= SOI_ADMIN_URL ?>/appearance.php" <?= $activeNav === 'appearance' ? 'aria-current="page"' : '' ?>>
      <?= soi_admin_icon('appearance') ?> Appearance
    </a>
    <a class="nav-item <?= $activeNav === 'plugins' ? 'active' : '' ?>" href="<?= SOI_ADMIN_URL ?>/plugins.php" <?= $activeNav === 'plugins' ? 'aria-current="page"' : '' ?>>
      <?= soi_admin_icon('plugins') ?> Plugins
    </a>
    <a class="nav-item <?= $activeNav === 'updates' ? 'active' : '' ?>" href="<?= SOI_ADMIN_URL ?>/updates.php" <?= $activeNav === 'updates' ? 'aria-current="page"' : '' ?>>
      <?= soi_admin_icon('updates') ?> Updates
    </a>

    <div class="sidebar-section">Integrations</div>
    <a class="nav-item <?= $activeNav === 'soi-central' ? 'active' : '' ?>" href="<?= SOI_ADMIN_URL ?>/soi-central.php" <?= $activeNav === 'soi-central' ? 'aria-current="page"' : '' ?>>
      <?= soi_admin_icon('soi-central') ?> SOI Central
    </a>
    <a class="nav-item <?= $activeNav === 'files-service-connector' ? 'active' : '' ?>" href="<?= SOI_ADMIN_URL ?>/files-service-connector.php" <?= $activeNav === 'files-service-connector' ? 'aria-current="page"' : '' ?>>
      <?= soi_admin_icon('plugins') ?> Files Service Connector
    </a>

    <div class="sidebar-section">Platform</div>
    <a class="nav-item <?= $activeNav === 'security' ? 'active' : '' ?>" href="<?= SOI_ADMIN_URL ?>/security.php" <?= $activeNav === 'security' ? 'aria-current="page"' : '' ?>>
      <?= soi_admin_icon('security') ?> Security
    </a>
    <?php if (class_exists(\SOI\Core\Cache::class)): ?>
    <a class="nav-item <?= $activeNav === 'cache' ? 'active' : '' ?>" href="<?= SOI_ADMIN_URL ?>/cache.php" <?= $activeNav === 'cache' ? 'aria-current="page"' : '' ?>>
      <?= soi_admin_icon('cache') ?> LiteSpeed Cache
    </a>
    <?php endif; ?>
    <a class="nav-item <?= $activeNav === 'api-keys' ? 'active' : '' ?>" href="<?= SOI_ADMIN_URL ?>/api-keys.php" <?= $activeNav === 'api-keys' ? 'aria-current="page"' : '' ?>>
      <?= soi_admin_icon('api-keys') ?> API Keys
    </a>
    <a class="nav-item <?= $activeNav === 'settings' ? 'active' : '' ?>" href="<?= SOI_ADMIN_URL ?>/settings.php" <?= $activeNav === 'settings' ? 'aria-current="page"' : '' ?>>
      <?= soi_admin_icon('settings') ?> General
    </a>
    <a class="nav-item <?= $activeNav === 'cms-readiness' ? 'active' : '' ?>" href="<?= SOI_ADMIN_URL ?>/cms-readiness.php" <?= $activeNav === 'cms-readiness' ? 'aria-current="page"' : '' ?>>
      <?= soi_admin_icon('security') ?> CMS Readiness
    </a>
    <a class="nav-item <?= $activeNav === 'smtp' ? 'active' : '' ?>" href="<?= SOI_ADMIN_URL ?>/smtp.php" <?= $activeNav === 'smtp' ? 'aria-current="page"' : '' ?>>
      <?= soi_admin_icon('smtp') ?> SMTP / Email
    </a>

    <?php
    Plugin::loadActive();
    foreach (Plugin::getAdminMenus() as $menu):
      if (($menu['slug'] ?? '') === 'files-service-connector') continue;
    ?>
    <a class="nav-item <?= $activeNav === $menu['slug'] ? 'active' : '' ?>" href="<?= esc($menu['url']) ?>" <?= $activeNav === $menu['slug'] ? 'aria-current="page"' : '' ?>>
      <?php if (preg_match('/^[a-z0-9-]+$/', $menu['icon'] ?? '')): ?>
        <?= soi_admin_icon($menu['icon'], 16) ?>
      <?php else: ?>
        <?= soi_admin_icon('plugins', 16) ?>
      <?php endif; ?>
      <?= esc($menu['title']) ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer" style="padding: 0.5rem 1rem; margin-top: auto; position: sticky; bottom: 0; background: var(--sidebar-bg); border-top: 1px solid var(--soi-nav-border); display: flex; align-items: center; gap: 0.5rem; color: var(--text-muted);">
    <?= soi_admin_icon('layers', 16) ?>
    <span style="font-weight:700;font-size:0.85rem;letter-spacing:-0.5px; opacity: 0.8;">SOI <span style="color:var(--brand);">CMS</span></span>
  </div>
</aside>
<?php endif; ?>

<!-- Main Content -->
<div class="admin-content">
  <?php if (!$isAuthoringMode): ?>
  <div class="topbar">
    <div class="topbar-left">
      <button type="button" class="mobile-toggle" aria-label="Toggle navigation" aria-controls="admin-sidebar" aria-expanded="false"><?= soi_admin_icon('menu', 18) ?></button>
      <h1 class="topbar-title"><?= esc($pageTitle) ?></h1>
      
      <div class="topbar-search-container" data-search-url="<?= esc(soi_public_path_prefix() . '/admin/ajax-search.php') ?>" data-search-token="<?= esc($adminSearchToken) ?>">
        <div class="topbar-search" role="search">
          <?= soi_admin_icon('search', 16) ?>
          <input class="topbar-search-input" type="search" id="admin-deep-search" placeholder="Search content and Admin Center..." autocomplete="off" spellcheck="false" role="combobox" aria-label="Search admin" aria-autocomplete="list" aria-haspopup="listbox" aria-controls="search-results-dropdown" aria-expanded="false">
          <button type="button" class="topbar-search-clear" id="admin-search-clear" aria-label="Clear search" title="Clear search" hidden><?= soi_admin_icon('close', 14, 'topbar-search-clear-icon') ?></button>
        </div>
        <div class="search-results-dropdown" id="search-results-dropdown" role="listbox" aria-label="Admin search results" hidden></div>
      </div>
    </div>
    <div class="topbar-actions">
      <?php if (class_exists(\SOI\Core\Cache::class) && \SOI\Core\Cache::isEnabled()): ?>
      <form method="POST" action="<?= SOI_ADMIN_URL ?>/cache.php" style="margin:0;">
        <?= \SOI\Core\Auth::csrfField() ?>
        <input type="hidden" name="_action" value="purge_all">
        <button type="submit" class="topbar-btn topbar-btn-ghost" title="Purge LiteSpeed Cache">🧹 Clear Cache</button>
      </form>
      <?php endif; ?>
      <?php if (isset($topbarActions)) echo $topbarActions; ?>
      <a href="<?= SOI_HOME_URL ?>" target="_blank" rel="noopener" class="topbar-btn topbar-btn-ghost" title="View Site">
        <?= soi_admin_icon('external-link', 16) ?>
      </a>
      <a href="<?= esc($sidebarLogoutUrl) ?>" class="topbar-btn topbar-btn-ghost" style="color:var(--soi-danger);" title="Logout">
        <?= soi_admin_icon('logout', 16) ?>
      </a>
      <span class="topbar-version">v<?= esc($displayVersion) ?></span>
      <?php
        $accountStatusClass = $accountsApiKey === '' ? ' topbar-account-status--warning' : '';
      ?>
      <div id="soi-my-account-widget" class="topbar-account-embed-host"></div>
      <a href="<?= SOI_ADMIN_URL ?>/profile.php" class="topbar-profile-icon<?= $accountStatusClass ?>" title="My Account Profile: <?= esc($userLabel) ?>" aria-label="Open My Account profile for <?= esc($userLabel) ?>">
        <?php if ($userAvatarUrl !== ''): ?>
          <img class="topbar-profile-avatar-img" src="<?= esc($userAvatarUrl) ?>" alt="">
        <?php else: ?>
          <span class="topbar-profile-avatar-initial"><?= esc($userInitial) ?></span>
        <?php endif; ?>
      </a>
    </div>
  </div>
  <?php endif; ?>

  <div class="page-content <?= esc($pageContentClass) ?>">
    <?php if ($flashes): ?>
    <div class="flash-messages">
      <?php foreach ($flashes as $flash): ?>
      <div class="alert alert-<?= esc($flash['type']) ?>" data-autodismiss role="alert">
        <?= esc($flash['message']) ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
