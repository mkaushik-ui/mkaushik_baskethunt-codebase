<?php
/**
 * Default Theme — Single Post Template
 */
// $post variable provided by App::route()
require SOI_THEME_DIR . '/header.php';
use SOI\Core\Database;

// Author
$author = $post['author_id'] ? Database::selectOne(
    "SELECT display_name, bio FROM `" . Database::prefix('users') . "` WHERE id = ?",
    [$post['author_id']]
) : null;

// Categories
$categories = Database::select(
    "SELECT c.* FROM `".Database::prefix('categories')."` c
     INNER JOIN `".Database::prefix('post_categories')."` pc ON c.id = pc.category_id
     WHERE pc.post_id = ?",
    [$post['id']]
);

// Previous / Next post
$prevPost = Database::selectOne(
    "SELECT title, slug FROM `".Database::prefix('posts')."` WHERE status='published' AND created_at < ? ORDER BY created_at DESC LIMIT 1",
    [$post['created_at']]
);
$nextPost = Database::selectOne(
    "SELECT title, slug FROM `".Database::prefix('posts')."` WHERE status='published' AND created_at > ? ORDER BY created_at ASC LIMIT 1",
    [$post['created_at']]
);

$wordCount  = str_word_count(strip_tags($post['content'] ?? ''));
$readTime   = max(1, ceil($wordCount / 200));
?>

<main class="site-main">
  <div class="container">
    <!-- Breadcrumb -->
    <div class="breadcrumb">
      <a href="<?= SOI_HOME_URL ?>">Home</a>
      <span class="breadcrumb-sep">›</span>
      <a href="<?= SOI_HOME_URL ?>/blog">Blog</a>
      <span class="breadcrumb-sep">›</span>
      <span><?= esc($post['title']) ?></span>
    </div>

    <div class="content-wrap">
      <article>
        <!-- Featured Image -->
        <?php if ($post['featured_image']): ?>
        <div class="soi-media-frame is-loading" style="width:100%;max-height:450px;border-radius:var(--radius);margin-bottom:2rem;">
          <div class="soi-skel soi-skel-layer" aria-hidden="true"></div>
          <img class="soi-perf-media" src="<?= esc($post['featured_image']) ?>" alt="<?= esc($post['title']) ?>"
               loading="eager" decoding="async"
               style="width:100%;max-height:450px;object-fit:cover;border-radius:var(--radius);">
        </div>
        <?php endif; ?>

        <header class="entry-header">
          <!-- Categories -->
          <?php if ($categories): ?>
          <div class="entry-cats">
            <?php foreach ($categories as $cat): ?>
            <a class="entry-cat" href="<?= SOI_HOME_URL ?>/category/<?= esc($cat['slug']) ?>"><?= esc($cat['name']) ?></a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <h1 class="entry-title"><?= esc($post['title']) ?></h1>

          <div class="entry-meta">
            <span>📅 <?= date('F j, Y', strtotime($post['created_at'])) ?></span>
            <?php if ($author): ?>
            <span>·</span>
            <span>✍️ <?= esc($author['display_name']) ?></span>
            <?php endif; ?>
            <span>·</span>
            <span>⏱️ <?= $readTime ?> min read</span>
            <span>·</span>
            <span><?= number_format($wordCount) ?> words</span>
          </div>
        </header>

        <div class="entry-content">
          <?php do_action('soi_before_post_content', $post); ?>
          <?= apply_filters('the_content', function_exists('soi_document_html') ? soi_document_html($post) : ($post['content'] ?? '')) ?>
          <?php do_action('soi_after_post_content', $post); ?>
        </div>

        <!-- Tags (stub placeholder) -->

        <!-- Author Box -->
        <?php if ($author && $author['bio']): ?>
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:1.5rem;margin-top:2.5rem;display:flex;gap:1.25rem;align-items:flex-start;">
          <div style="width:56px;height:56px;border-radius:50%;background:rgba(245,148,31,0.1);border:2px solid var(--brand);display:flex;align-items:center;justify-content:center;font-size:1.3rem;font-weight:700;color:var(--brand);flex-shrink:0;">
            <?= strtoupper(substr($author['display_name'], 0, 1)) ?>
          </div>
          <div>
            <div style="font-weight:700;font-size:0.9rem;margin-bottom:0.35rem;">About <?= esc($author['display_name']) ?></div>
            <p style="font-size:0.87rem;color:var(--text-muted);line-height:1.65;"><?= esc($author['bio']) ?></p>
          </div>
        </div>
        <?php endif; ?>

        <!-- Prev / Next Navigation -->
        <?php if ($prevPost || $nextPost): ?>
        <nav style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:2.5rem;border-top:1px solid var(--border);padding-top:2rem;">
          <?php if ($prevPost): ?>
          <a href="<?= SOI_HOME_URL ?>/<?= esc($prevPost['slug']) ?>" style="text-decoration:none;padding:1rem;border:1px solid var(--border);border-radius:var(--radius);background:var(--surface);">
            <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:0.35rem;">← Previous</div>
            <div style="font-weight:600;font-size:0.9rem;color:var(--text);"><?= esc($prevPost['title']) ?></div>
          </a>
          <?php else: ?><div></div><?php endif; ?>
          <?php if ($nextPost): ?>
          <a href="<?= SOI_HOME_URL ?>/<?= esc($nextPost['slug']) ?>" style="text-decoration:none;padding:1rem;border:1px solid var(--border);border-radius:var(--radius);background:var(--surface);text-align:right;">
            <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:0.35rem;">Next →</div>
            <div style="font-weight:600;font-size:0.9rem;color:var(--text);"><?= esc($nextPost['title']) ?></div>
          </a>
          <?php endif; ?>
        </nav>
        <?php endif; ?>

      </article>

      <!-- Sidebar -->
      <?php include SOI_THEME_DIR . '/sidebar.php'; ?>
    </div>
  </div>
</main>

<?php require SOI_THEME_DIR . '/footer.php'; ?>
