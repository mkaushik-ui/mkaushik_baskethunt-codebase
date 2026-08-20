<?php
/**
 * Default Theme — Sidebar
 */
use SOI\Core\Database;

$recentPosts = [];
$categories = [];
if (soi_blog_enabled()) {
    $recentPosts = Database::select(
        "SELECT title, slug, created_at FROM `" . Database::prefix('posts') . "` WHERE status='published' ORDER BY created_at DESC LIMIT 5"
    );
    $categories = Database::select(
        "SELECT c.*, COUNT(pc.post_id) as post_count FROM `".Database::prefix('categories')."` c
         LEFT JOIN `".Database::prefix('post_categories')."` pc ON c.id = pc.category_id
         GROUP BY c.id ORDER BY post_count DESC"
    );
}
?>
<aside class="sidebar">

  <!-- Recent Posts Widget -->
  <?php if ($recentPosts): ?>
  <div class="widget">
    <h3 class="widget-title">Recent Posts</h3>
    <div class="widget-body" style="padding:0.5rem 0;">
      <?php foreach ($recentPosts as $post): ?>
      <a class="widget-post" href="<?= SOI_HOME_URL ?>/<?= esc($post['slug']) ?>">
        <div>
          <div class="widget-post-title"><?= esc($post['title']) ?></div>
          <div class="widget-post-date"><?= date('M j, Y', strtotime($post['created_at'])) ?></div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Categories Widget -->
  <?php if ($categories): ?>
  <div class="widget">
    <h3 class="widget-title">Categories</h3>
    <div class="widget-body" style="display:flex;flex-direction:column;gap:0.35rem;">
      <?php foreach ($categories as $cat): ?>
      <a href="<?= SOI_HOME_URL ?>/category/<?= esc($cat['slug']) ?>"
         style="display:flex;align-items:center;justify-content:space-between;text-decoration:none;padding:0.4rem 0.5rem;border-radius:6px;font-size:0.85rem;color:var(--text-muted);transition:all .15s;"
         onmouseover="this.style.color='var(--brand)';this.style.background='rgba(245,148,31,0.05)'"
         onmouseout="this.style.color='var(--text-muted)';this.style.background=''">
        <span><?= esc($cat['name']) ?></span>
        <span style="background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:1px 8px;font-size:0.72rem;"><?= (int)$cat['post_count'] ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- About Widget -->
  <div class="widget">
    <h3 class="widget-title">About This Site</h3>
    <div class="widget-body" style="font-size:0.87rem;color:var(--text-muted);line-height:1.7;">
      <p><?= esc(soi_option('site_tagline', 'Welcome to our website! Explore our content and feel free to reach out.')) ?></p>
    </div>
  </div>

  <?php do_action('soi_sidebar_widgets'); ?>
</aside>
