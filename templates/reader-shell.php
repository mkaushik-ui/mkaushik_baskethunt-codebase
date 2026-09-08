<?php
/**
 * Public Reader Shell Template (Task KS-06)
 *
 * Renders the public reader interface with dynamic navigation tree,
 * breadcrumb hierarchy, active section highlighting, and canonical document rendering parity.
 *
 * @var array $space Resolved space data
 * @var array $sections Navigation tree from TaxonomyService::buildNavigationTree()
 * @var array|null $document Resolved document data (null or empty if empty space landing)
 * @var array $breadcrumbs Breadcrumb items array
 * @var string $activeSection Currently active section slug
 * @var string $activeSlug Currently active document slug
 */

use SOI\Core\Content\DocumentRenderer;

$spaceName = $space['name'] ?? 'Documentation';
$spaceSlug = $space['slug'] ?? 'docs';
$spaceType = $space['type'] ?? 'docs';

$docTitle = !empty($document['title']) ? $document['title'] : $spaceName;
$pageTitle = $docTitle . ' — ' . $spaceName;

$currentSlug = $activeSlug ?? ($document['slug'] ?? '');
$currentSection = $activeSection ?? ($document['section_slug'] ?? '');

// Layout preset (Standard ~820px, Wide ~1140px, Full 100%)
$layoutPreset = $document['layout_preset'] ?? 'standard';
if (!in_array($layoutPreset, ['standard', 'wide', 'full'], true)) {
    $layoutPreset = 'standard';
}
$layoutClass = 'kc-layout-' . $layoutPreset;

// Render document content with canonical parity
$renderedBodyHtml = '';
if (!empty($document)) {
    if (function_exists('soi_document_html')) {
        $renderedBodyHtml = soi_document_html($document);
    } elseif (class_exists(DocumentRenderer::class)) {
        $renderedBodyHtml = DocumentRenderer::renderRecord($document, false);
    } else {
        $renderedBodyHtml = (string)($document['content'] ?? '');
    }
} else {
    $renderedBodyHtml = '<div class="kc-empty-space"><p>Welcome to <strong>' . esc($spaceName) . '</strong>. Select a topic from the navigation sidebar to begin reading.</p></div>';
}

$homeUrl = defined('SOI_HOME_URL') ? SOI_HOME_URL : '/';
$publicPrefix = function_exists('soi_public_path_prefix') ? soi_public_path_prefix() : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= esc($pageTitle) ?></title>
  <?php if (!empty($document['meta_desc'])): ?>
    <meta name="description" content="<?= esc($document['meta_desc']) ?>">
  <?php endif; ?>
  <!-- Workstream C: Canonical Block Stylesheet & Reader Styles -->
  <link rel="stylesheet" href="<?= esc($publicPrefix) ?>/assets/kc-blocks.css">
  <link rel="stylesheet" href="<?= esc($publicPrefix) ?>/assets/kc-reader.css">
  <style>
    /* Reader Shell Foundation Styling */
    :root {
      --kc-bg: #f8fafc;
      --kc-surface: #ffffff;
      --kc-text: #0f172a;
      --kc-muted: #64748b;
      --kc-border: #e2e8f0;
      --kc-primary: #2563eb;
      --kc-primary-hover: #1d4ed8;
      --kc-primary-soft: #eff6ff;
      --kc-sidebar-width: 290px;
      --kc-header-height: 60px;
      --kc-radius: 8px;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      background-color: var(--kc-bg);
      color: var(--kc-text);
      line-height: 1.6;
      display: flex;
      flex-direction: column;
      min-height: 100vh;
    }
    a { color: var(--kc-primary); text-decoration: none; }
    a:hover { text-decoration: underline; }

    /* Top Navigation Bar */
    .kc-header {
      position: sticky;
      top: 0;
      z-index: 50;
      height: var(--kc-header-height);
      background: var(--kc-surface);
      border-bottom: 1px solid var(--kc-border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 1.5rem;
    }
    .kc-brand {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      font-weight: 700;
      font-size: 1.15rem;
      color: var(--kc-text);
    }
    .kc-badge-type {
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      padding: 0.2rem 0.5rem;
      background: var(--kc-primary-soft);
      color: var(--kc-primary);
      border-radius: 4px;
      font-weight: 600;
    }
    .kc-header-actions {
      display: flex;
      align-items: center;
      gap: 1rem;
    }
    .kc-mobile-toggle {
      display: none;
      background: none;
      border: 1px solid var(--kc-border);
      border-radius: 6px;
      padding: 0.4rem 0.6rem;
      font-size: 1.25rem;
      cursor: pointer;
    }

    /* Layout Structure */
    .kc-layout {
      display: flex;
      flex: 1;
      position: relative;
    }

    /* Sidebar Navigation */
    .kc-sidebar {
      width: var(--kc-sidebar-width);
      flex-shrink: 0;
      background: var(--kc-surface);
      border-right: 1px solid var(--kc-border);
      overflow-y: auto;
      height: calc(100vh - var(--kc-header-height));
      position: sticky;
      top: var(--kc-header-height);
      padding: 1.5rem 1rem;
    }
    .kc-nav-group {
      margin-bottom: 1.5rem;
    }
    .kc-section-title {
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--kc-muted);
      margin-bottom: 0.6rem;
      padding-left: 0.75rem;
      font-weight: 700;
    }
    .kc-nav-list {
      list-style: none;
    }
    .kc-nav-item {
      margin-bottom: 0.2rem;
    }
    .kc-nav-link {
      display: block;
      padding: 0.45rem 0.75rem;
      border-radius: var(--kc-radius);
      color: #334155;
      font-size: 0.925rem;
      font-weight: 500;
      transition: background 0.15s ease, color 0.15s ease;
    }
    .kc-nav-link:hover {
      background: #f1f5f9;
      color: var(--kc-text);
      text-decoration: none;
    }
    .kc-nav-item.is-active .kc-nav-link,
    .kc-nav-link.is-active {
      background: var(--kc-primary-soft);
      color: var(--kc-primary);
      font-weight: 600;
    }

    /* Main Reader Area */
    .kc-main {
      flex: 1;
      min-width: 0;
      padding: 2rem 3rem 4rem 3rem;
    }

    /* Breadcrumbs */
    .kc-breadcrumbs {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 0.4rem;
      font-size: 0.875rem;
      color: var(--kc-muted);
      margin-bottom: 1.5rem;
    }
    .kc-breadcrumb-sep {
      color: #94a3b8;
      font-size: 0.875rem;
      user-select: none;
    }
    .kc-breadcrumb-current {
      color: var(--kc-text);
      font-weight: 600;
    }

    /* Article Content */
    article.kc-article {
      background: var(--kc-surface);
      border-radius: 12px;
      border: 1px solid var(--kc-border);
      padding: 2.5rem 3rem;
      box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    }
    .kc-doc-header {
      margin-bottom: 2rem;
      padding-bottom: 1.25rem;
      border-bottom: 1px solid var(--kc-border);
    }
    .kc-doc-title {
      font-size: 2.25rem;
      font-weight: 800;
      letter-spacing: -0.025em;
      line-height: 1.2;
      color: var(--kc-text);
    }
    .kc-doc-meta {
      display: flex;
      gap: 1rem;
      margin-top: 0.5rem;
      font-size: 0.85rem;
      color: var(--kc-muted);
    }

    /* Content Typography & Blocks */
    .entry-content {
      font-size: 1.05rem;
      line-height: 1.75;
      color: #334155;
    }
    .entry-content h2 { margin-top: 2rem; margin-bottom: 0.8rem; font-size: 1.6rem; color: #0f172a; }
    .entry-content h3 { margin-top: 1.5rem; margin-bottom: 0.6rem; font-size: 1.3rem; color: #0f172a; }
    .entry-content p { margin-bottom: 1.2rem; }
    .entry-content ul, .entry-content ol { margin-bottom: 1.2rem; padding-left: 1.5rem; }
    .entry-content li { margin-bottom: 0.4rem; }
    .entry-content blockquote {
      border-left: 4px solid var(--kc-primary);
      padding-left: 1rem;
      margin: 1.5rem 0;
      color: var(--kc-muted);
      font-style: italic;
    }

    /* Responsive Design */
    @media (max-width: 900px) {
      .kc-mobile-toggle { display: block; }
      .kc-sidebar {
        position: fixed;
        left: -320px;
        top: var(--kc-header-height);
        z-index: 40;
        transition: left 0.25s ease;
        box-shadow: 2px 0 10px rgba(0,0,0,0.1);
      }
      .kc-sidebar.is-open { left: 0; }
      .kc-main { padding: 1.5rem 1rem; }
      article.kc-article { padding: 1.5rem; }
    }
  </style>
</head>
<body class="kc-reader-body">

  <!-- Top Navigation Header -->
  <header class="kc-header">
    <div class="kc-brand">
      <button class="kc-mobile-toggle" id="kcSidebarToggle" aria-label="Toggle Navigation">☰</button>
      <a href="<?= esc($homeUrl) ?>" class="kc-brand-link"><?= esc($spaceName) ?></a>
      <span class="kc-badge-type"><?= esc($spaceType) ?></span>
    </div>
    <div class="kc-header-actions">
      <a href="<?= esc($homeUrl) ?>" class="kc-home-link">← Back to Site</a>
    </div>
  </header>

  <!-- Reader Shell Layout -->
  <div class="kc-layout">

    <!-- Sidebar Navigation Tree -->
    <aside class="kc-sidebar" id="kcSidebar" aria-label="Space Documentation Navigation">
      <nav class="kc-nav-tree">
        <?php if (!empty($sections)): ?>
          <?php foreach ($sections as $sec): ?>
            <?php 
              $isSecActive = ($currentSection !== '' && strtolower($sec['slug'] ?? '') === strtolower($currentSection));
            ?>
            <div class="kc-nav-group <?= $isSecActive ? 'is-expanded' : '' ?>">
              <div class="kc-section-title"><?= esc($sec['title'] ?? 'Section') ?></div>
              <ul class="kc-nav-list">
                <?php foreach (($sec['items'] ?? []) as $item): ?>
                  <?php 
                    $isItemActive = ($currentSlug !== '' && strtolower($item['slug'] ?? '') === strtolower($currentSlug));
                  ?>
                  <li class="kc-nav-item <?= $isItemActive ? 'is-active' : '' ?>">
                    <a href="<?= esc($item['url'] ?? '#') ?>" 
                       class="kc-nav-link <?= $isItemActive ? 'is-active' : '' ?>"
                       <?= $isItemActive ? 'aria-current="page"' : '' ?>>
                      <?= esc($item['title'] ?? 'Document') ?>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <p style="padding: 1rem; color: var(--kc-muted); font-size: 0.875rem;">No navigation sections available.</p>
        <?php endif; ?>
      </nav>
    </aside>

    <!-- Main Content Reader View -->
    <main class="kc-main" id="kcMain">

      <!-- Breadcrumbs Trail -->
      <?php if (!empty($breadcrumbs)): ?>
        <nav class="kc-breadcrumbs" aria-label="Breadcrumb">
          <?php foreach ($breadcrumbs as $index => $crumb): ?>
            <?php if ($index > 0): ?>
              <span class="kc-breadcrumb-sep">›</span>
            <?php endif; ?>
            <?php if (!empty($crumb['url'])): ?>
              <a href="<?= esc($crumb['url']) ?>" class="kc-breadcrumb-link"><?= esc($crumb['label']) ?></a>
            <?php else: ?>
              <span class="kc-breadcrumb-current"><?= esc($crumb['label']) ?></span>
            <?php endif; ?>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>

      <!-- Document Article Container with EUX-12 layout preset class -->
      <article class="kc-article <?= esc($layoutClass) ?>" data-layout-preset="<?= esc($layoutPreset) ?>">
        <header class="kc-doc-header">
          <h1 class="kc-doc-title"><?= esc($docTitle) ?></h1>
          <?php if (!empty($document['created_at'])): ?>
            <div class="kc-doc-meta">
              <span>Updated <?= date('F j, Y', strtotime($document['updated_at'] ?? $document['created_at'])) ?></span>
              <?php if (!empty($document['section_title'])): ?>
                <span>• in <strong><?= esc($document['section_title']) ?></strong></span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </header>

        <!-- Canonical Document Body Render -->
        <div class="entry-content <?= esc($layoutClass) ?>">
          <?= $renderedBodyHtml ?>
        </div>
      </article>

    </main>
  </div>

  <!-- Workstream C: Client-side Block Interactivity & Reader Navigation Shell -->
  <script src="<?= esc($publicPrefix) ?>/assets/kc-public.js"></script>
  <script src="<?= esc($publicPrefix) ?>/assets/kc-reader.js"></script>
</body>
</html>
