<?php
/**
 * Default Theme — Static Page Template
 */
// $page variable provided by App::route()
require SOI_THEME_DIR . '/header.php';
use SOI\Core\Database;
?>

<main class="site-main">
  <div class="container">
    <!-- Breadcrumb -->
    <div class="breadcrumb">
      <a href="<?= SOI_HOME_URL ?>">Home</a>
      <span class="breadcrumb-sep">›</span>
      <span><?= esc($page['title'] ?? 'Page') ?></span>
    </div>

    <div class="content-wrap">
      <article>
        <header class="entry-header">
          <h1 class="entry-title"><?= esc($page['title'] ?? '') ?></h1>
          <div class="entry-meta">
            <span><?= date('F j, Y', strtotime($page['created_at'] ?? 'now')) ?></span>
          </div>
        </header>
        <div class="entry-content">
          <?= do_action('soi_before_content') ?>
          <?= apply_filters('the_content', function_exists('soi_document_html') ? soi_document_html($page) : ($page['content'] ?? '')) ?>
          <?= do_action('soi_after_content') ?>
        </div>
      </article>

      <!-- Sidebar -->
      <?php include SOI_THEME_DIR . '/sidebar.php'; ?>
    </div>
  </div>
</main>

<?php require SOI_THEME_DIR . '/footer.php'; ?>
