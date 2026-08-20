<?php
/**
 * Default Theme — Blank CMS landing (blog disabled, no homepage configured)
 */
require SOI_THEME_DIR . '/header.php';

$siteName = soi_option('site_name', 'SOI CMS');
$adminUrl = defined('SOI_ADMIN_URL') ? SOI_ADMIN_URL : soi_url('admin');
?>

<main class="site-main">
  <div class="container" style="text-align:center;padding:5rem 2rem;max-width:640px;margin:0 auto;">
    <div style="font-size:3rem;margin-bottom:1.25rem;">🏗️</div>
    <h1 style="font-family:var(--heading-font);font-size:2rem;margin-bottom:0.75rem;">Welcome to <?= esc($siteName) ?></h1>
    <p style="color:var(--text-muted);font-size:1.05rem;line-height:1.7;margin-bottom:0.75rem;">
      No application landing page is configured yet.
    </p>
    <p style="color:var(--text-muted);font-size:0.95rem;line-height:1.65;margin-bottom:2rem;">
      Configure a homepage from <strong>Admin Center → General Settings</strong>, or enable the blog module if you need posts and archives.
    </p>
    <a href="<?= esc($adminUrl) ?>"
       style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.85rem 1.75rem;background:var(--brand);color:#fff;text-decoration:none;border-radius:10px;font-weight:700;font-size:0.95rem;">
      Open Admin Center →
    </a>
  </div>
</main>

<?php require SOI_THEME_DIR . '/footer.php'; ?>