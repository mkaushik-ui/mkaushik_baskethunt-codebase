<?php
/**
 * Default Theme — Homepage (Blog listing or static page)
 */
require SOI_THEME_DIR . '/header.php';

use SOI\Core\Database;

$perPage  = (int) soi_option('posts_per_page', 10);
$curPage  = max(1, (int) ($_GET['page'] ?? 1));
$offset   = ($curPage - 1) * $perPage;
$total    = Database::count('posts', "status = 'published'");
$pages    = ceil($total / $perPage);

$posts = Database::select(
    "SELECT p.*, u.display_name as author_name FROM `" . Database::prefix('posts') . "` p
     LEFT JOIN `" . Database::prefix('users') . "` u ON p.author_id = u.id
     WHERE p.status = 'published'
     ORDER BY p.created_at DESC
     LIMIT ? OFFSET ?",
    [$perPage, $offset]
);

$siteName = soi_option('site_name', 'SOI (School Of Interns) CMS');
$tagline  = soi_option('site_tagline', 'Your new favourite website');
?>

<!-- Hero section (shown only on page 1) -->
<?php if ($curPage === 1): ?>
<section class="hero">
  <div class="container">
    <div class="hero-tag">Welcome</div>
    <h1 class="hero-title"><?= esc($siteName) ?></h1>
    <p class="hero-sub"><?= esc($tagline) ?></p>
    <?php if ($posts): ?>
    <a href="#latest-posts" class="hero-cta">Read Latest Posts ↓</a>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<main class="site-main">
  <div class="container">
    <div class="content-wrap">
      <!-- Main column -->
      <div>
        <?php if ($curPage > 1): ?>
        <h2 style="font-family:var(--heading-font);font-size:1.3rem;margin-bottom:1.5rem;color:var(--text-muted);">
          Page <?= $curPage ?> of <?= $pages ?>
        </h2>
        <?php endif; ?>

        <div id="latest-posts">
          <?php if ($posts): ?>
          <div class="posts-grid">
            <?php foreach ($posts as $post):
              // Get first category
              $postCat = Database::selectOne(
                "SELECT c.name, c.slug FROM `".Database::prefix('categories')."` c
                 INNER JOIN `".Database::prefix('post_categories')."` pc ON c.id = pc.category_id
                 WHERE pc.post_id = ? LIMIT 1", [$post['id']]
              );
            ?>
            <article class="post-card soi-defer-block">
              <?php if ($post['featured_image']): ?>
              <div class="soi-media-frame is-loading post-card-image-wrap">
                <div class="soi-skel soi-skel-layer" aria-hidden="true"></div>
                <img class="post-card-image soi-perf-media" src="<?= esc($post['featured_image']) ?>" alt="<?= esc($post['title']) ?>" loading="lazy" decoding="async">
              </div>
              <?php else: ?>
              <div class="post-card-image-placeholder">📝</div>
              <?php endif; ?>
              <div class="post-card-body">
                <div class="post-card-meta">
                  <?php if ($postCat): ?>
                  <a href="<?= SOI_HOME_URL ?>/category/<?= esc($postCat['slug']) ?>" class="post-card-cat"><?= esc($postCat['name']) ?></a>
                  <span class="post-card-meta-dot"></span>
                  <?php endif; ?>
                  <span><?= date('M j, Y', strtotime($post['created_at'])) ?></span>
                  <?php if ($post['author_name']): ?>
                  <span class="post-card-meta-dot"></span>
                  <span>by <?= esc($post['author_name']) ?></span>
                  <?php endif; ?>
                </div>
                <h2 class="post-card-title">
                  <a href="<?= SOI_HOME_URL ?>/<?= esc($post['slug']) ?>"><?= esc($post['title']) ?></a>
                </h2>
                <p class="post-card-excerpt"><?= esc(excerpt($post['excerpt'] ?: $post['content'], 22)) ?></p>
                <div class="post-card-footer">
                  <a href="<?= SOI_HOME_URL ?>/<?= esc($post['slug']) ?>" class="read-more">Read more →</a>
                  <span style="font-size:0.75rem;color:var(--text-light);"><?= ceil(str_word_count(strip_tags($post['content'])) / 200) ?> min read</span>
                </div>
              </div>
            </article>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div style="text-align:center;padding:4rem 2rem;color:var(--text-muted);">
            <p style="font-size:2rem;margin-bottom:1rem;">📝</p>
            <h2 style="font-family:var(--heading-font);">No posts yet</h2>
            <p>Check back soon for new content!</p>
          </div>
          <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
        <nav class="pagination">
          <?php if ($curPage > 1): ?>
          <a href="?page=<?= $curPage-1 ?>" class="page-link">←</a>
          <?php endif; ?>
          <?php for ($i = max(1,$curPage-2); $i <= min($pages,$curPage+2); $i++): ?>
          <a href="?page=<?= $i ?>" class="page-link <?= $i===$curPage?'current':'' ?>"><?= $i ?></a>
          <?php endfor; ?>
          <?php if ($curPage < $pages): ?>
          <a href="?page=<?= $curPage+1 ?>" class="page-link">→</a>
          <?php endif; ?>
        </nav>
        <?php endif; ?>
      </div>

      <!-- Sidebar -->
      <?php include SOI_THEME_DIR . '/sidebar.php'; ?>
    </div>
  </div>
</main>

<?php require SOI_THEME_DIR . '/footer.php'; ?>
