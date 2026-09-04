<?php
/**
 * Master Reader Shell & Public Frontend Template.
 * Decoupled from authoring runtime scripts.
 */
declare(strict_types=1);

$space = $space ?? [
    'title' => 'Files Service Docs',
    'description' => 'Public post-powered documentation for CMS & developer integration.',
    'icon' => 'FS',
    'badge' => 'Developer Docs',
    'sections' => []
];

$article = $article ?? [
    'title' => 'App Registration',
    'description' => 'Manual registration, allowed domains, and credential handoff.',
    'category' => 'Developer Docs',
    'slug' => 'app-registration',
    'status' => 'Published',
    'updated_at' => 'Jul 3, 2026',
    'read_time' => '1 min read',
    'view_url' => '/docs/app-registration',
    'html' => ''
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($article['title']) ?> — <?= htmlspecialchars($space['title']) ?></title>
  <meta name="description" content="<?= htmlspecialchars($article['description']) ?>">
  <link rel="stylesheet" href="/assets/kc-reader.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;850&display=swap" rel="stylesheet">
</head>
<body class="kc-reader-body">

  <!-- Top Header Bar -->
  <header class="kc-r-header">
    <div class="kc-r-header-left">
      <button type="button" class="kc-r-drawer-toggle" id="kc-r-drawer-toggle" aria-label="Toggle Navigation Sidebar">☰</button>
      <a href="/docs/app-registration" class="kc-r-logo-pill">
        <div class="kc-r-logo-ico"><?= htmlspecialchars($space['icon'] ?? 'FS') ?></div>
        <span>files.soi.co.in</span>
      </a>
    </div>

    <div class="kc-r-search-box">
      <span class="kc-r-search-ico">🔍</span>
      <input type="search" class="kc-r-search-input" id="kc-r-search-input" placeholder="Search documentation posts, API headers, errors, or connector guides.. (⌘K)">
    </div>

    <div class="kc-r-header-actions">
      <a href="/test-editor" class="kc-r-btn-ghost" style="display:inline-flex;align-items:center;gap:0.35rem;text-decoration:none;color:#2563eb;font-weight:700;background:#eff6ff;border-color:#bfdbfe;" title="Open Authoring Workspace Editor">✏️ Edit Doc Article</a>
      <button type="button" class="kc-r-btn-ghost">Open Drive</button>
      <button type="button" class="kc-r-btn-primary">Sign in</button>
    </div>
  </header>

  <!-- Reader Layout Grid -->
  <div class="kc-r-layout">
    
    <!-- Left Navigation Sidebar -->
    <aside class="kc-r-sidebar-left" id="kc-r-sidebar-left">
      <div class="kc-r-space-card">
        <div class="kc-r-space-badge-icon"><?= htmlspecialchars($space['icon'] ?? 'FS') ?></div>
        <div class="kc-r-space-info">
          <strong><?= htmlspecialchars($space['title']) ?></strong>
          <p><?= htmlspecialchars($space['description']) ?></p>
        </div>
      </div>

      <nav aria-label="Space Navigation">
        <?php foreach ($space['sections'] as $sec): ?>
        <div class="kc-r-nav-section-title"><?= htmlspecialchars($sec['title']) ?></div>
        <ul class="kc-r-nav-list">
          <?php foreach ($sec['items'] as $item): ?>
          <li class="kc-r-nav-item <?= !empty($item['active']) ? 'is-active' : '' ?>">
            <a href="/docs/<?= htmlspecialchars($item['slug']) ?>">
              <span><?= htmlspecialchars($item['title']) ?></span>
              <span class="kc-r-pill-tag">Post</span>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endforeach; ?>
      </nav>
    </aside>

    <!-- Center Article Reading Canvas -->
    <main class="kc-r-main-canvas">
      
      <!-- Hero Header Card -->
      <div class="kc-r-hero-card">
        <div class="kc-r-breadcrumbs">
          <a href="#">Home</a> &rsaquo; <a href="#">Docs</a> &rsaquo; <?= htmlspecialchars($article['title']) ?>
        </div>
        <h1 class="kc-r-article-title"><?= htmlspecialchars($article['title']) ?></h1>
        <p class="kc-r-article-desc"><?= htmlspecialchars($article['description']) ?></p>

        <div class="kc-r-hero-tags">
          <span class="kc-r-hero-tag">Post-backed docs</span>
          <span class="kc-r-hero-tag">Section: <?= htmlspecialchars($article['title']) ?></span>
          <span class="kc-r-hero-tag">Updated <?= htmlspecialchars($article['updated_at']) ?></span>
          <span class="kc-r-hero-tag"><?= htmlspecialchars($article['view_url']) ?></span>
        </div>
      </div>

      <!-- Canvas Metadata Bar -->
      <div class="kc-r-meta-bar">
        <div>
          <strong><?= htmlspecialchars($space['title']) ?></strong>
          <span>Published CMS post &bull; <?= htmlspecialchars($space['badge']) ?> category</span>
        </div>
        <div>
          <span>⏱️ <?= htmlspecialchars($article['read_time']) ?></span>
        </div>
      </div>

      <!-- Rendered Article Body -->
      <article class="kc-r-article-body">
        <?= $article['html'] ?>
      </article>

    </main>

    <!-- Right On-This-Page Sidebar -->
    <aside class="kc-r-sidebar-right">
      
      <!-- On This Page Table of Contents -->
      <div class="kc-r-widget" id="kc-r-toc-widget">
        <div class="kc-r-widget-title">On this page</div>
        <ul class="kc-r-toc-list" id="kc-r-toc-list">
          <!-- Dynamic TOC items populated by kc-reader.js -->
        </ul>
      </div>

      <!-- Post Metadata Card -->
      <div class="kc-r-widget">
        <div class="kc-r-widget-title">Post metadata</div>
        <div class="kc-r-meta-card">
          <div class="kc-r-meta-row">
            <span>Category</span>
            <strong><?= htmlspecialchars($space['badge']) ?></strong>
          </div>
          <div class="kc-r-meta-row">
            <span>Slug</span>
            <strong><?= htmlspecialchars($article['slug']) ?></strong>
          </div>
          <div class="kc-r-meta-row">
            <span>Status</span>
            <strong style="color: #16a34a;"><?= htmlspecialchars($article['status']) ?></strong>
          </div>
          <div class="kc-r-meta-row">
            <span>Public URL</span>
            <strong style="font-size:0.72rem;"><?= htmlspecialchars($article['view_url']) ?></strong>
          </div>
          <div class="kc-r-meta-row">
            <span>Reading time</span>
            <strong><?= htmlspecialchars($article['read_time']) ?></strong>
          </div>
        </div>
      </div>

      <!-- Related Docs -->
      <div class="kc-r-widget">
        <div class="kc-r-widget-title">Related docs</div>
        <ul class="kc-r-toc-list">
          <li class="kc-r-toc-item"><a href="#">App Registration</a></li>
          <li class="kc-r-toc-item"><a href="#">Authentication</a></li>
          <li class="kc-r-toc-item"><a href="#">Connection Requests</a></li>
        </ul>
      </div>

    </aside>

  </div>

  <script src="/assets/kc-reader.js"></script>
</body>
</html>
