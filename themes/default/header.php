<?php
/**
 * Default Theme — Header Partial
 * Include at top of all theme templates: require get_theme_file('header.php');
 */
$siteName    = soi_option('site_name', 'SOI (School Of Interns) CMS');
$siteTagline = soi_option('site_tagline', '');
$siteUrl     = SOI_HOME_URL;

// Get primary menu
$primaryMenu  = \SOI\Core\Database::selectOne(
    "SELECT * FROM `" . \SOI\Core\Database::prefix('menus') . "` WHERE location = 'primary' LIMIT 1"
);
$menuItems = [];
if ($primaryMenu) {
    $menuItems = \SOI\Core\Database::select(
        "SELECT * FROM `" . \SOI\Core\Database::prefix('menu_items') . "` WHERE menu_id = ? ORDER BY sort_order",
        [$primaryMenu['id']]
    );
}

$currentUri = \SOI\Core\Router::getUri();

// SEO
$seoTitle = isset($page) ? ($page['meta_title'] ?: $page['title'] . ' — ' . $siteName)
    : (isset($post) ? ($post['meta_title'] ?: $post['title'] . ' — ' . $siteName) : $siteName);
$seoDesc  = isset($page) ? ($page['meta_desc'] ?: $siteTagline)
    : (isset($post) ? ($post['meta_desc'] ?: excerpt($post['content'] ?? '', 25)) : $siteTagline);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= esc($seoTitle) ?></title>
<?php if ($seoDesc): ?><meta name="description" content="<?= esc($seoDesc) ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Merriweather:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= esc(soi_theme_asset_url('style.css')) ?>">
<?php do_action('soi_head'); ?>
</head>
<body <?php do_action('soi_body_class'); ?>>
<?php do_action('soi_before_header'); ?>
<header class="site-header">
  <div class="container">
    <div class="header-inner">
      <a class="site-brand" href="<?= esc($siteUrl) ?>">
        <span class="site-brand-icon">🎓</span>
        <span class="site-brand-name"><?= esc($siteName) ?><span>.</span></span>
      </a>

      <button class="mobile-menu-toggle" id="mobile-menu-toggle" aria-label="Menu">☰</button>

      <nav class="site-nav" id="site-nav">
        <?php if ($menuItems): ?>
          <?php foreach ($menuItems as $item): ?>
          <a class="nav-link <?= rtrim($currentUri,'/') === ('/' . trim(parse_url($item['url'], PHP_URL_PATH) ?? '', '/')) ? 'active' : '' ?>"
             href="<?= esc($item['url']) ?>">
            <?= esc($item['title']) ?>
          </a>
          <?php endforeach; ?>
        <?php else: ?>
          <a class="nav-link <?= $currentUri==='/'?'active':'' ?>" href="<?= esc($siteUrl) ?>">Home</a>
          <a class="nav-link" href="<?= esc($siteUrl) ?>/blog">Blog</a>
          <a class="nav-link" href="<?= esc($siteUrl) ?>/about">About</a>
          <a class="nav-link" href="<?= esc($siteUrl) ?>/contact">Contact</a>
        <?php endif; ?>
        <?php do_action('soi_nav_items'); ?>
      </nav>
    </div>
  </div>
</header>
<?php do_action('soi_after_header'); ?>

<script>
document.getElementById('mobile-menu-toggle')?.addEventListener('click', function(){
  const nav = document.getElementById('site-nav');
  nav?.classList.toggle('open');
});
</script>
