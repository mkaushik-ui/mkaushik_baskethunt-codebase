<?php
/**
 * Default Theme — Archive/Category Template
 */
require SOI_THEME_DIR . '/header.php';
use SOI\Core\Database;

$perPage = (int) soi_option('posts_per_page', 10);
$curPage = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($curPage - 1) * $perPage;

$archiveTitle = 'All Posts';
$where = "status = 'published'";
$params = [];

if (isset($category) && $category) {
    $archiveTitle = 'Category: ' . $category['name'];
    $where = "p.status = 'published' AND pc.category_id = ?";
    $params[] = $category['id'];
    $posts = Database::select(
        "SELECT p.*, u.display_name as author_name FROM `".Database::prefix('posts')."` p
         INNER JOIN `".Database::prefix('post_categories')."` pc ON p.id = pc.post_id
         LEFT JOIN `".Database::prefix('users')."` u ON p.author_id = u.id
         WHERE $where ORDER BY p.created_at DESC LIMIT ? OFFSET ?",
        array_merge($params, [$perPage, $offset])
    );
    $total = Database::count('posts', "id IN (SELECT post_id FROM `".Database::prefix('post_categories')."` WHERE category_id = ?)", [$category['id']]);
} else {
    $posts = Database::select(
        "SELECT p.*, u.display_name as author_name FROM `".Database::prefix('posts')."` p
         LEFT JOIN `".Database::prefix('users')."` u ON p.author_id = u.id
         WHERE p.status = 'published' ORDER BY p.created_at DESC LIMIT ? OFFSET ?",
        [$perPage, $offset]
    );
    $total = Database::count('posts', "status = 'published'");
}

$totalPages = ceil($total / $perPage);
$allCats = Database::select("SELECT * FROM `" . Database::prefix('categories') . "` ORDER BY name");
?>

<main class="site-main">
  <div class="container">
    <!-- Breadcrumb -->
    <div class="breadcrumb">
      <a href="<?= SOI_HOME_URL ?>">Home</a>
      <span class="breadcrumb-sep">›</span>
      <?php if (isset($category)): ?>
      <a href="<?= SOI_HOME_URL ?>/blog">Blog</a>
      <span class="breadcrumb-sep">›</span>
      <span><?= esc($category['name'] ?? '') ?></span>
      <?php else: ?>
      <span>Blog</span>
      <?php endif; ?>
    </div>

    <div style="margin-bottom:1.75rem;">
      <h1 style="font-family:var(--heading-font);font-size:2rem;"><?= esc($archiveTitle) ?></h1>
      <?php if (isset($category) && ($category['description'] ?? '')): ?>
      <p style="color:var(--text-muted);margin-top:0.5rem;"><?= esc($category['description']) ?></p>
      <?php endif; ?>
      <p style="font-size:0.82rem;color:var(--text-light);margin-top:0.35rem;"><?= $total ?> post<?= $total!=1?'s':'' ?></p>
    </div>

    <!-- Categories Filter -->
    <?php if ($allCats): ?>
    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:2rem;">
      <a href="<?= SOI_HOME_URL ?>/blog" style="display:inline-block;padding:0.3rem 0.9rem;border-radius:20px;font-size:0.8rem;font-weight:600;background:<?= !isset($category)?'var(--brand)':'var(--surface)' ?>;color:<?= !isset($category)?'#fff':'var(--text-muted)' ?>;text-decoration:none;border:1px solid <?= !isset($category)?'var(--brand)':'var(--border)' ?>;">All</a>
      <?php foreach ($allCats as $cat): ?>
      <a href="<?= SOI_HOME_URL ?>/category/<?= esc($cat['slug']) ?>"
         style="display:inline-block;padding:0.3rem 0.9rem;border-radius:20px;font-size:0.8rem;font-weight:600;background:<?= (isset($category) && $category['id']==$cat['id'])?'var(--brand)':'var(--surface)' ?>;color:<?= (isset($category) && $category['id']==$cat['id'])?'#fff':'var(--text-muted)' ?>;text-decoration:none;border:1px solid <?= (isset($category) && $category['id']==$cat['id'])?'var(--brand)':'var(--border)' ?>;">
        <?= esc($cat['name']) ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="content-wrap">
      <div>
        <?php if ($posts): ?>
        <div class="posts-grid">
          <?php foreach ($posts as $post): ?>
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
                <span><?= date('M j, Y', strtotime($post['created_at'])) ?></span>
                <?php if ($post['author_name']): ?>
                <span class="post-card-meta-dot"></span>
                <span><?= esc($post['author_name']) ?></span>
                <?php endif; ?>
              </div>
              <h2 class="post-card-title">
                <a href="<?= SOI_HOME_URL ?>/<?= esc($post['slug']) ?>"><?= esc($post['title']) ?></a>
              </h2>
              <p class="post-card-excerpt"><?= esc(excerpt($post['excerpt'] ?: $post['content'], 22)) ?></p>
              <div class="post-card-footer">
                <a href="<?= SOI_HOME_URL ?>/<?= esc($post['slug']) ?>" class="read-more">Read more →</a>
              </div>
            </div>
          </article>
          <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav class="pagination">
          <?php if ($curPage > 1): ?><a href="?page=<?= $curPage-1 ?>" class="page-link">←</a><?php endif; ?>
          <?php for ($i=max(1,$curPage-2);$i<=min($totalPages,$curPage+2);$i++): ?>
          <a href="?page=<?= $i ?>" class="page-link <?= $i===$curPage?'current':'' ?>"><?= $i ?></a>
          <?php endfor; ?>
          <?php if ($curPage < $totalPages): ?><a href="?page=<?= $curPage+1 ?>" class="page-link">→</a><?php endif; ?>
        </nav>
        <?php endif; ?>

        <?php else: ?>
        <div style="text-align:center;padding:4rem 0;color:var(--text-muted);">
          <p style="font-size:2rem;margin-bottom:1rem;">🔍</p>
          <h2>No posts found</h2>
          <p style="margin-top:0.5rem;">Try a different category or check back later.</p>
        </div>
        <?php endif; ?>
      </div>

      <!-- Sidebar -->
      <?php include SOI_THEME_DIR . '/sidebar.php'; ?>
    </div>
  </div>
</main>

<?php require SOI_THEME_DIR . '/footer.php'; ?>
