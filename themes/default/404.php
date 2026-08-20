<?php
/**
 * Default Theme — 404 Not Found
 */
require SOI_THEME_DIR . '/header.php';
?>
<main class="site-main">
  <div class="container" style="text-align:center;padding:6rem 2rem;">
    <div style="font-size:5rem;margin-bottom:1rem;">🏗️</div>
    <h1 style="font-family:var(--heading-font);font-size:3rem;color:var(--text);margin-bottom:0.75rem;">404</h1>
    <h2 style="font-family:var(--heading-font);font-size:1.5rem;color:var(--text-muted);margin-bottom:1rem;">Page Not Found</h2>
    <p style="color:var(--text-muted);font-size:1rem;margin-bottom:2rem;max-width:480px;margin-left:auto;margin-right:auto;">
      The page you're looking for doesn't exist or may have been moved. Let's get you back on track.
    </p>
    <a href="<?= SOI_HOME_URL ?>" style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.85rem 1.75rem;background:var(--brand);color:#fff;text-decoration:none;border-radius:10px;font-weight:700;font-size:0.95rem;">
      ← Back to Homepage
    </a>
  </div>
</main>
<?php require SOI_THEME_DIR . '/footer.php'; ?>
