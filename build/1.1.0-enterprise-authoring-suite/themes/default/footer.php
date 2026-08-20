<?php
/**
 * Default Theme — Footer Partial
 */
$siteName   = soi_option('site_name', 'SOI (School Of Interns) CMS');
$tagline    = soi_option('site_tagline', '');
$adminEmail = soi_option('admin_email', '');
$year       = date('Y');

// Footer menu
$footerMenu = \SOI\Core\Database::selectOne(
    "SELECT * FROM `" . \SOI\Core\Database::prefix('menus') . "` WHERE location = 'footer' LIMIT 1"
);
$footerItems = $footerMenu ? \SOI\Core\Database::select(
    "SELECT * FROM `" . \SOI\Core\Database::prefix('menu_items') . "` WHERE menu_id = ? ORDER BY sort_order",
    [$footerMenu['id']]
) : [];

// Recent posts for footer (blog module only)
$recentPosts = [];
if (soi_blog_enabled()) {
    $recentPosts = \SOI\Core\Database::select(
        "SELECT title, slug FROM `" . \SOI\Core\Database::prefix('posts') . "` WHERE status='published' ORDER BY created_at DESC LIMIT 4"
    );
}
?>
<?php do_action('soi_before_footer'); ?>
<footer class="site-footer">
  <div class="container">
    <div class="footer-grid">
      <!-- Brand -->
      <div>
        <div class="footer-brand">
          <span style="font-size:1.25rem;">🎓</span>
          <span class="footer-brand-name"><?= esc($siteName) ?><span>.</span></span>
        </div>
        <p class="footer-desc"><?= esc($tagline ?: 'Built with SOI (School Of Interns) CMS — Simple, Powerful, Extensible.') ?></p>
        <?php if ($adminEmail): ?>
        <p style="margin-top:0.75rem;font-size:0.82rem;"><a href="mailto:<?= esc($adminEmail) ?>" style="color:var(--brand);text-decoration:none;"><?= esc($adminEmail) ?></a></p>
        <?php endif; ?>
      </div>

      <!-- Navigation -->
      <div>
        <h3 class="footer-heading">Navigation</h3>
        <ul class="footer-links">
          <?php if ($footerItems): ?>
            <?php foreach ($footerItems as $item): ?>
            <li><a href="<?= esc($item['url']) ?>"><?= esc($item['title']) ?></a></li>
            <?php endforeach; ?>
          <?php else: ?>
            <li><a href="<?= SOI_HOME_URL ?>">Home</a></li>
            <?php if (soi_blog_enabled()): ?><li><a href="<?= SOI_HOME_URL ?>/blog">Blog</a></li><?php endif; ?>
            <li><a href="<?= SOI_HOME_URL ?>/about">About</a></li>
            <li><a href="<?= SOI_HOME_URL ?>/contact">Contact</a></li>
          <?php endif; ?>
        </ul>
      </div>

      <!-- Recent Posts -->
      <?php if ($recentPosts): ?>
      <div>
        <h3 class="footer-heading">Recent Posts</h3>
        <ul class="footer-links">
          <?php foreach ($recentPosts as $p): ?>
          <li><a href="<?= SOI_HOME_URL ?>/<?= esc($p['slug']) ?>"><?= esc($p['title']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
    </div>

    <div class="footer-bottom">
      <span>© <?= $year ?> <a href="<?= SOI_HOME_URL ?>"><?= esc($siteName) ?></a>. All rights reserved.</span>
      <span>Powered by <a href="<?= SOI_HOME_URL ?>">SOI (School Of Interns) CMS</a></span>
    </div>
  </div>
</footer>
<?php do_action('soi_after_footer'); ?>
<?php
$kcPublicJs = SOI_ROOT . '/assets/kc-public.js';
$kcPublicVer = is_file($kcPublicJs) ? (string) filemtime($kcPublicJs) : '1.0.7';
?>
<script src="<?= esc(soi_public_path_prefix() . '/assets/kc-public.js') ?>?v=<?= esc($kcPublicVer) ?>" defer></script>
<?php do_action('soi_footer_scripts'); ?>
</body>
</html>
