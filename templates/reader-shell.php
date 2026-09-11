<?php
declare(strict_types=1);

/**
 * SOI Knowledge Center — Master Public Reader & Presentation Shell
 * Location: templates/reader-shell.php
 * Domain: kc.soi.co.in
 *
 * Harmonized Presentation Template for Workstream C (Milestone M3):
 * - Anti-FOUC Light/Dark theme initialization (Task RC-05)
 * - Accessible Theme Toggle Button with Sun/Moon SVG icons (Task RC-05)
 * - Canonical Block Stylesheet & Design System Parity (Task RC-01)
 * - Public Tab & CodeGroup Client Interactivity (Task RC-02)
 * - Automatic One-Click Code Copy Engine (Task RC-02)
 * - "On This Page" Table of Contents Sticky Rail & Scroll-Spy (Task RC-03)
 * - Technical Product Space Version Switcher & Live Search (Task RC-04)
 * - Responsive Multi-Pane Layout (Desktop Tri-Pane, Tablet Dual-Pane, Mobile Drawer)
 *
 * @var array $space Resolved space data
 * @var array $sections Navigation tree from TaxonomyService::buildNavigationTree()
 * @var array|null $document Resolved document data (null or empty if empty space landing)
 * @var array $breadcrumbs Breadcrumb items array
 * @var string $activeSection Currently active section slug
 * @var string $activeSlug Currently active document slug
 */

use SOI\Core\Content\DocumentRenderer;
use SOI\Core\Spaces\SpaceDocumentService;

$spaceId   = (int) ($space['id'] ?? 0);
$spaceName = (string) ($space['name'] ?? ($space['title'] ?? 'Documentation'));
$spaceSlug = (string) ($space['slug'] ?? 'docs');
$spaceType = (string) ($space['type'] ?? 'docs');

$isDenied = !empty($isDenied);
$docTitle  = !empty($document['title']) ? (string) $document['title'] : ($isDenied ? 'Access Restricted' : $spaceName);
$pageTitle = $isDenied ? '403 Forbidden — Access Restricted' : ($docTitle . ' — ' . $spaceName);

$currentSlug    = $activeSlug ?? ($document['slug'] ?? '');
$currentSection = $activeSection ?? ($document['section_slug'] ?? '');
$currentVersion = $_GET['v'] ?? ($document['doc_version'] ?? '');

$homeUrl = defined('SOI_HOME_URL') ? SOI_HOME_URL : '/';

// Layout preset (Standard ~820px, Wide ~1140px, Full 100%)
$layoutPreset = (string) ($document['layout_preset'] ?? 'standard');
if (!in_array($layoutPreset, ['standard', 'wide', 'full'], true)) {
    $layoutPreset = 'standard';
}
$layoutClass = 'kc-layout-' . $layoutPreset;

// Render document content with canonical parity
$renderedBodyHtml = '';
if ($isDenied) {
    $msg = !empty($denialMessage) ? (string) $denialMessage : 'You do not have permission to access this content.';
    $renderedBodyHtml = '<div class="kc-denied-space" style="text-align:center;padding:5rem 2rem;">'
        . '<div style="font-size:4rem;margin-bottom:1rem;" aria-hidden="true">🔒</div>'
        . '<h1 style="font-size:2rem;font-weight:700;margin-bottom:0.75rem;">Access Restricted</h1>'
        . '<p style="color:var(--kc-muted,#64748b);max-width:480px;margin:0 auto 2rem auto;font-size:1.05rem;">' . esc($msg) . '</p>'
        . '<a href="' . esc($homeUrl) . '" style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.75rem 1.5rem;background:var(--kc-primary,#2563eb);color:#fff;text-decoration:none;border-radius:8px;font-weight:600;font-size:0.95rem;">← Back to Home</a>'
        . '</div>';
} elseif (!empty($document)) {
    if (function_exists('soi_document_html')) {
        $renderedBodyHtml = soi_document_html($document);
    } elseif (class_exists(DocumentRenderer::class)) {
        $renderedBodyHtml = DocumentRenderer::renderRecord($document, false);
    } else {
        $renderedBodyHtml = (string) ($document['content'] ?? '');
    }
} else {
    $renderedBodyHtml = '<div class="kc-empty-space"><p>Welcome to <strong>' . esc($spaceName) . '</strong>. Select a topic from the navigation sidebar to begin reading.</p></div>';
}

// Extract headings for Table of Contents rail (RC-03)
$tocHeadings = [];
if (!empty($document) && class_exists(DocumentRenderer::class)) {
    $parsedDoc = !empty($document['body_json']) ? json_decode((string) $document['body_json'], true) : $document;
    $rawHeadings = DocumentRenderer::extractHeadings($parsedDoc);
    // Filter H2 and H3 for Table of Contents rail
    foreach ($rawHeadings as $h) {
        if (isset($h['level']) && in_array((int)$h['level'], [2, 3], true)) {
            $tocHeadings[] = $h;
        }
    }
}

// Fetch versions for technical product spaces (RC-04)
$spaceVersions = [];
if ($spaceType === 'tech' && $spaceId > 0 && class_exists(SpaceDocumentService::class)) {
    try {
        $docService = new SpaceDocumentService();
        $spaceVersions = $docService->getSpaceVersions($spaceId);
    } catch (\Throwable $e) {
        $spaceVersions = [];
    }
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

  <!-- Anti-FOUC Theme Initialization (RC-05) -->
  <script>
    (function () {
      try {
        var savedTheme = localStorage.getItem('kc-reader-theme');
        var theme = 'light';
        if (savedTheme === 'dark' || savedTheme === 'light') {
          theme = savedTheme;
        } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
          theme = 'dark';
        }
        document.documentElement.setAttribute('data-theme', theme);
        if (theme === 'dark') {
          document.documentElement.classList.add('theme-dark');
        } else {
          document.documentElement.classList.remove('theme-dark');
        }
      } catch (e) {
        document.documentElement.setAttribute('data-theme', 'light');
      }
    })();
  </script>

  <!-- Canonical Block Stylesheet & Reader Layout Styles (RC-01, RC-03, RC-05) -->
  <link rel="stylesheet" href="<?= esc($publicPrefix) ?>/assets/kc-blocks.css">
  <link rel="stylesheet" href="<?= esc($publicPrefix) ?>/assets/kc-reader.css">
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
      <!-- Dark / Light Mode Toggle Button (RC-05) -->
      <button class="kc-theme-toggle" id="kcThemeToggle" aria-label="Toggle dark/light theme" title="Toggle theme">
        <!-- Sun Icon (shown in dark mode) -->
        <svg class="kc-icon-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="5"></circle>
          <line x1="12" y1="1" x2="12" y2="3"></line>
          <line x1="12" y1="21" x2="12" y2="23"></line>
          <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
          <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
          <line x1="1" y1="12" x2="3" y2="12"></line>
          <line x1="21" y1="12" x2="23" y2="12"></line>
          <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
          <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
        </svg>
        <!-- Moon Icon (shown in light mode) -->
        <svg class="kc-icon-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
        </svg>
      </button>

      <a href="<?= esc($homeUrl) ?>" class="kc-home-link">← Back to Site</a>
    </div>
  </header>

  <!-- Reader Shell Layout -->
  <div class="kc-layout">

    <!-- Sidebar Navigation Tree -->
    <aside class="kc-sidebar" id="kcSidebar" aria-label="Space Documentation Navigation">

      <!-- Technical Space Product Version Selector (RC-04) -->
      <?php if ($spaceType === 'tech' && !empty($spaceVersions)): ?>
        <div class="kc-sidebar-toolbar" style="margin-bottom: 1rem;">
          <label for="kcVersionSelect" class="kc-toolbar-label" style="display:block; margin-bottom: 0.35rem; font-size: 0.75rem; font-weight: 600; color: var(--kc-muted);">PRODUCT VERSION</label>
          <select id="kcVersionSelect" class="kc-version-select" style="width: 100%; height: 36px; border: 1px solid var(--kc-border); border-radius: 6px; background: var(--kc-surface); color: var(--kc-text); padding: 0 0.75rem; font-size: 0.82rem; font-weight: 600;">
            <?php foreach ($spaceVersions as $v): ?>
              <?php 
                $vTag = $v['version_tag'] ?? '';
                $isSelected = ($currentVersion !== '' && strtolower($currentVersion) === strtolower($vTag)) || (empty($currentVersion) && !empty($v['is_latest']));
              ?>
              <option value="<?= esc($vTag) ?>" <?= $isSelected ? 'selected' : '' ?>>
                <?= esc($v['version_name'] ?? $vTag) ?> <?= !empty($v['is_latest']) ? '(Latest)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <!-- Sidebar Quick Search & Topic Filter (RC-04) -->
      <div class="kc-sidebar-search" style="margin-bottom: 1rem;">
        <input type="search" id="kcSidebarSearch" placeholder="Filter topics..." style="width: 100%; height: 36px; padding: 0 0.75rem; border: 1px solid var(--kc-border); border-radius: 6px; font-size: 0.88rem; background: var(--kc-surface); color: var(--kc-text);">
      </div>

      <!-- Navigation Tree Structure -->
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
              <?php if (!empty($document['doc_version'])): ?>
                <span>• version <strong><?= esc($document['doc_version']) ?></strong></span>
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

    <!-- "On This Page" Table of Contents Sticky Rail (RC-03) -->
    <?php if (count($tocHeadings) >= 2): ?>
      <aside class="kc-toc" id="kcToc" aria-label="Table of Contents">
        <div class="kc-toc-title">ON THIS PAGE</div>
        <ul class="kc-toc-list">
          <?php foreach ($tocHeadings as $th): ?>
            <?php 
              $hLevel = (int) ($th['level'] ?? 2);
              $hIndent = $hLevel === 3 ? 'style="padding-left: 0.75rem; font-size: 0.8rem;"' : '';
            ?>
            <li class="kc-toc-item">
              <a href="#<?= esc($th['id']) ?>" class="kc-toc-link" <?= $hIndent ?>>
                <?= esc($th['text']) ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </aside>
    <?php endif; ?>

  </div>

  <!-- Client-side Public Block Interactivity & Master Reader Runtime (RC-02, RC-03, RC-04, RC-05) -->
  <script src="<?= esc($publicPrefix) ?>/assets/kc-public.js"></script>
  <script src="<?= esc($publicPrefix) ?>/assets/kc-reader.js"></script>
</body>
</html>
