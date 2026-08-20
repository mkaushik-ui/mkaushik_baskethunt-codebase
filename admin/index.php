<?php
/**
 * Admin Dashboard — Phase 1.1.3 Command Center Mosaic
 */
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
$pageContentClass = 'page-content--dashboard';
require_once __DIR__ . '/partials/header.php';

use SOI\Core\{Database, Auth, Blog};

$blogEnabled = Blog::isEnabled();

$pagesCount   = Database::count('pages');
$postsCount   = $blogEnabled ? Database::count('posts') : 0;
$usersCount   = Database::count('users');
$mediaCount   = Database::count('media');
$draftPosts   = $blogEnabled ? Database::count('posts', "status = 'draft'") : 0;
$pubPosts     = $blogEnabled ? Database::count('posts', "status = 'published'") : 0;

$recentPosts  = $blogEnabled ? Database::select(
    "SELECT p.*, u.display_name as author_name FROM `" . Database::prefix('posts') . "` p
     LEFT JOIN `" . Database::prefix('users') . "` u ON p.author_id = u.id
     ORDER BY p.created_at DESC LIMIT 6"
) : [];
$recentPages  = Database::select(
    "SELECT id, title, status, updated_at FROM `" . Database::prefix('pages') . "` ORDER BY updated_at DESC LIMIT 6"
);
$recentUsers  = Database::select(
    "SELECT * FROM `" . Database::prefix('users') . "` ORDER BY created_at DESC LIMIT 5"
);

$siteVersion  = Database::getOption('cms_version', '1.0.0');
$updateLog    = json_decode(Database::getOption('update_log', '[]'), true) ?: [];
$lastUpdate   = $updateLog[0] ?? null;
?>

<div class="dashboard-command-center">

  <header class="dashboard-band dashboard-band--stats">
    <div class="stats-grid stats-grid--dashboard">
      <div class="stat-card stat-card--compact">
        <div class="stat-card-icon"><?= soi_admin_icon('pages', 18) ?></div>
        <div class="stat-card-content">
          <div class="stat-card-value"><?= $pagesCount ?></div>
          <div class="stat-card-label">Pages</div>
        </div>
      </div>
      <?php if ($blogEnabled): ?>
      <div class="stat-card stat-card--compact">
        <div class="stat-card-icon"><?= soi_admin_icon('posts', 18) ?></div>
        <div class="stat-card-content">
          <div class="stat-card-value"><?= $postsCount ?></div>
          <div class="stat-card-label">Posts</div>
        </div>
      </div>
      <?php endif; ?>
      <div class="stat-card stat-card--compact">
        <div class="stat-card-icon"><?= soi_admin_icon('users', 18) ?></div>
        <div class="stat-card-content">
          <div class="stat-card-value"><?= $usersCount ?></div>
          <div class="stat-card-label">Users</div>
        </div>
      </div>
      <div class="stat-card stat-card--compact">
        <div class="stat-card-icon"><?= soi_admin_icon('media', 18) ?></div>
        <div class="stat-card-content">
          <div class="stat-card-value"><?= $mediaCount ?></div>
          <div class="stat-card-label">Media</div>
        </div>
      </div>
      <?php if ($blogEnabled): ?>
      <div class="stat-card stat-card--compact">
        <div class="stat-card-icon"><?= soi_admin_icon('warning', 18) ?></div>
        <div class="stat-card-content">
          <div class="stat-card-value"><?= $draftPosts ?></div>
          <div class="stat-card-label">Drafts</div>
        </div>
      </div>
      <div class="stat-card stat-card--compact">
        <div class="stat-card-icon"><?= soi_admin_icon('updates', 18) ?></div>
        <div class="stat-card-content">
          <div class="stat-card-value"><?= $pubPosts ?></div>
          <div class="stat-card-label">Published</div>
        </div>
      </div>
      <?php else: ?>
      <div class="stat-card stat-card--compact">
        <div class="stat-card-icon"><?= soi_admin_icon('settings', 18) ?></div>
        <div class="stat-card-content">
          <div class="stat-card-value">—</div>
          <div class="stat-card-label">Blog Off</div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </header>

  <div class="dashboard-mosaic">

    <section class="mosaic-panel mosaic-panel--activity card">
      <div class="card-header">
        <h2 class="card-title"><?= $blogEnabled ? 'Recent Content Activity' : 'Recent Pages' ?></h2>
        <?php if ($blogEnabled): ?>
        <a href="<?= SOI_ADMIN_URL ?>/posts.php?action=new" class="btn btn-primary btn-sm">+ New Post</a>
        <?php else: ?>
        <a href="<?= SOI_ADMIN_URL ?>/pages.php?action=new" class="btn btn-primary btn-sm">+ New Page</a>
        <?php endif; ?>
      </div>
      <?php if ($blogEnabled && $recentPosts): ?>
      <div class="table-wrap table-wrap--compact">
        <table class="table--dense">
          <thead>
            <tr><th>Title</th><th>Author</th><th>Status</th><th>Date</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($recentPosts as $post): ?>
            <tr>
              <td class="td-title">
                <a href="<?= SOI_ADMIN_URL ?>/posts.php?action=edit&id=<?= $post['id'] ?>" class="table-link"><?= esc($post['title']) ?></a>
              </td>
              <td class="text-muted"><?= esc($post['author_name'] ?? '—') ?></td>
              <td>
                <?php $sc = ['published'=>'badge-success','draft'=>'badge-warning','private'=>'badge-info'][$post['status']] ?? 'badge-muted'; ?>
                <span class="badge <?= $sc ?>"><?= esc($post['status']) ?></span>
              </td>
              <td class="text-muted"><?= date('M j, Y', strtotime($post['created_at'])) ?></td>
              <td><a href="<?= SOI_ADMIN_URL ?>/posts.php?action=edit&id=<?= $post['id'] ?>" class="btn btn-ghost btn-sm">Edit</a></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer-link"><a href="<?= SOI_ADMIN_URL ?>/posts.php">View all posts</a></div>
      <?php elseif ($blogEnabled): ?>
      <div class="empty-state">
        <div class="empty-state-title">No posts yet</div>
        <div class="empty-state-text"><a href="<?= SOI_ADMIN_URL ?>/posts.php?action=new" class="admin-link">Create your first post →</a></div>
      </div>
      <?php elseif ($recentPages): ?>
      <div class="table-wrap table-wrap--compact">
        <table class="table--dense">
          <thead>
            <tr><th>Title</th><th>Status</th><th>Updated</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($recentPages as $page): ?>
            <tr>
              <td class="td-title">
                <a href="<?= SOI_ADMIN_URL ?>/pages.php?action=edit&id=<?= $page['id'] ?>" class="table-link"><?= esc($page['title']) ?></a>
              </td>
              <td><span class="badge badge-muted"><?= esc($page['status']) ?></span></td>
              <td class="text-muted"><?= date('M j, Y', strtotime($page['updated_at'])) ?></td>
              <td><a href="<?= SOI_ADMIN_URL ?>/pages.php?action=edit&id=<?= $page['id'] ?>" class="btn btn-ghost btn-sm">Edit</a></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer-link"><a href="<?= SOI_ADMIN_URL ?>/pages.php">View all pages</a></div>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-state-title">Blank CMS ready</div>
        <div class="empty-state-text"><a href="<?= SOI_ADMIN_URL ?>/settings.php" class="admin-link">Configure homepage and modules →</a></div>
      </div>
      <?php endif; ?>
    </section>

    <section class="mosaic-panel mosaic-panel--health card">
      <div class="card-header"><h2 class="card-title">System Health</h2></div>
      <div class="card-body">
        <div class="health-kv"><span>CMS Version</span><span>v<?= esc($siteVersion) ?></span></div>
        <div class="health-kv"><span>PHP Version</span><span><?= esc(PHP_VERSION) ?></span></div>
        <?php if ($blogEnabled): ?>
        <div class="health-kv"><span>Published</span><span><?= $pubPosts ?></span></div>
        <div class="health-kv"><span>Drafts</span><span><?= $draftPosts ?></span></div>
        <?php else: ?>
        <div class="health-kv"><span>Blog Module</span><span>Disabled</span></div>
        <?php endif; ?>
        <div class="health-kv"><span>Last Update</span><span><?= esc($lastUpdate ? $lastUpdate['name'] . ' v' . $lastUpdate['version'] : 'None') ?></span></div>
        <a href="<?= SOI_HOME_URL ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm" style="width:100%;margin-top:0.75rem;justify-content:center;">View Live Site</a>
      </div>
    </section>

    <section class="mosaic-panel mosaic-panel--users card">
      <div class="card-header"><h2 class="card-title">Recent Users</h2></div>
      <div class="card-body user-list-compact">
        <?php foreach ($recentUsers as $u): ?>
        <div class="user-row">
          <div class="user-avatar-sm"><?= strtoupper(substr($u['display_name'] ?? $u['username'], 0, 1)) ?></div>
          <div>
            <div style="font-size:0.82rem;font-weight:600;"><?= esc($u['display_name'] ?? $u['username']) ?></div>
            <div style="font-size:0.72rem;color:var(--text-muted);text-transform:capitalize;"><?= esc($u['role']) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="card-footer-link"><a href="<?= SOI_ADMIN_URL ?>/users.php">Manage team access</a></div>
    </section>

    <section class="mosaic-panel mosaic-panel--actions card">
      <div class="card-header"><h2 class="card-title">Quick Actions</h2></div>
      <div class="card-body">
        <div class="quick-actions-grid quick-actions-grid--dense">
          <?php if ($blogEnabled): ?>
          <a href="<?= SOI_ADMIN_URL ?>/posts.php?action=new" class="quick-action-btn"><?= soi_admin_icon('posts', 16) ?> New Post</a>
          <?php endif; ?>
          <a href="<?= SOI_ADMIN_URL ?>/pages.php?action=new" class="quick-action-btn"><?= soi_admin_icon('pages', 16) ?> New Page</a>
          <a href="<?= SOI_ADMIN_URL ?>/media.php" class="quick-action-btn"><?= soi_admin_icon('upload', 16) ?> Upload</a>
          <a href="<?= SOI_ADMIN_URL ?>/updates.php" class="quick-action-btn"><?= soi_admin_icon('updates', 16) ?> Updates</a>
          <a href="<?= SOI_ADMIN_URL ?>/plugins.php" class="quick-action-btn"><?= soi_admin_icon('plugins', 16) ?> Plugins</a>
          <a href="<?= SOI_ADMIN_URL ?>/security.php" class="quick-action-btn"><?= soi_admin_icon('security', 16) ?> Security</a>
        </div>
      </div>
    </section>

    <section class="mosaic-panel mosaic-panel--system card">
      <div class="card-header"><h2 class="card-title">System Overview</h2></div>
      <div class="card-body">
        <div class="system-kv"><span>Total Pages</span><span><?= $pagesCount ?></span></div>
        <?php if ($blogEnabled): ?>
        <div class="system-kv"><span>Total Posts</span><span><?= $postsCount ?></span></div>
        <?php endif; ?>
        <div class="system-kv"><span>Active Users</span><span><?= $usersCount ?></span></div>
        <div class="system-kv"><span>Media Files</span><span><?= $mediaCount ?></span></div>
      </div>
    </section>

    <section class="mosaic-panel mosaic-panel--links card">
      <div class="card-header"><h2 class="card-title">Quick Links</h2></div>
      <div class="card-body">
        <nav class="quick-links-grid" aria-label="Quick links">
          <a href="<?= SOI_ADMIN_URL ?>/pages.php" class="quick-link">Pages</a>
          <?php if ($blogEnabled): ?><a href="<?= SOI_ADMIN_URL ?>/posts.php" class="quick-link">Posts</a><?php endif; ?>
          <a href="<?= SOI_ADMIN_URL ?>/media.php" class="quick-link">Media</a>
          <a href="<?= SOI_ADMIN_URL ?>/users.php" class="quick-link">Users</a>
          <a href="<?= SOI_ADMIN_URL ?>/security.php" class="quick-link">Security</a>
          <a href="<?= SOI_ADMIN_URL ?>/updates.php" class="quick-link">Updates</a>
        </nav>
      </div>
    </section>

  </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>