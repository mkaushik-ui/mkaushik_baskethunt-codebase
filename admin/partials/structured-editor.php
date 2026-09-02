<?php
/**
 * SOI Knowledge Center — Complete Enterprise Authoring Suite (1.1.0).
 *
 * Expected locals:
 *   $editorEntity string  page|post
 *   $editorRecord array
 *   $editorIsNew bool
 *   $editorListUrl string
 *   $editorShowExcerpt bool
 *   $editorCategories array
 *   $editorAssignedCats array
 */

use SOI\Core\Auth;
use SOI\Core\Content\BlockRegistry;
use SOI\Core\Content\Document;
use SOI\Core\Content\EditorSchema;
use SOI\Core\Content\Html;
use SOI\Core\Content\Patterns;
use SOI\Core\Content\Templates;

$record = $editorRecord ?? [];
$entity = $editorEntity ?? 'page';
$isNew = !empty($editorIsNew);
$isStructured = $isNew || EditorSchema::isStructured($record);
$editorVersion = '1.1.0';
$initialDocument = Document::empty();
if ($isStructured && !empty($record['body_json'])) {
    try {
        $initialDocument = Document::parse($record['body_json']);
    } catch (Throwable $e) {
        $initialDocument = Document::empty();
    }
}
$editorJsData = Document::toEditorJs($initialDocument);
$hasLegacyBlock = false;
foreach ($initialDocument['blocks'] ?? [] as $block) {
    if (($block['type'] ?? '') === 'legacy') {
        $hasLegacyBlock = true;
        break;
    }
}
$catalog = BlockRegistry::catalog($hasLegacyBlock || !$isStructured);
$templatesList = Templates::all();
$patternsList = Patterns::all();

$config = [
    'entity' => $entity,
    'mode' => $isStructured ? 'structured' : 'legacy',
    'csrf' => Auth::csrfToken(),
    'apiUrl' => SOI_ADMIN_URL . '/editor-api.php',
    'uploadUrl' => SOI_ADMIN_URL . '/editor-upload.php',
    'editUrl' => SOI_ADMIN_URL . '/' . ($entity === 'post' ? 'posts.php' : 'pages.php') . '?action=edit&id=',
    'viewUrl' => !empty($record['slug']) ? (rtrim(SOI_HOME_URL, '/') . '/' . ltrim((string) $record['slug'], '/')) : '',
    'autosave' => true,
    'initialData' => $editorJsData,
    'catalog' => $catalog,
    'templates' => $templatesList,
    'patterns' => $patternsList,
];
$liveUrl = $config['viewUrl'];
$currentStatus = (string) ($record['status'] ?? 'draft');
?>
<form method="POST" id="kc-editor" class="kc-editor kc-workspace" data-mode="<?= $isStructured ? 'structured' : 'legacy' ?>" data-left="open" data-right="open" data-left-tab="components" data-right-tab="doc" data-editor-state="loading">
  <?= Auth::csrfField() ?>
  <input type="hidden" name="_action" value="save">
  <input type="hidden" name="id" id="kc-doc-id" value="<?= (int) ($record['id'] ?? 0) ?>">
  <input type="hidden" name="mode" id="kc-editor-mode" value="<?= $isStructured ? 'structured' : 'legacy' ?>">
  <input type="hidden" name="expected_updated_at" id="kc-updated-at" value="<?= esc($record['updated_at'] ?? '') ?>">
  <input type="hidden" name="document" id="kc-document-json" value="<?= esc(Document::encode($initialDocument)) ?>">

  <!-- Unified Sticky Header (Header + Title Wrapper + Ribbon) -->
  <div class="kc-sticky-header">
    <header class="kc-workspace-head">
      <div class="kc-head-left">
        <a class="kc-head-btn-exit" href="<?= esc($editorListUrl) ?>" title="Return to document list">←</a>
        <div class="kc-head-title-stack">
          <strong class="kc-head-title">Edit Doc Article</strong>
          <span class="kc-doc-slug-path" id="kc-head-slug-path"><?= esc($liveUrl !== '' ? $liveUrl : '/docs/' . ($record['slug'] ?? '')) ?></span>
        </div>
      </div>
      <div class="kc-head-right">
        <span class="kc-save-chip" id="kc-save-status" data-state="<?= $isNew ? 'new' : 'saved' ?>"><?= $isNew ? 'Not saved' : 'Saved' ?></span>
        <span class="kc-badge-mode" id="kc-card-status-badge"><?= esc(strtoupper($currentStatus)) ?></span>
        <button type="button" class="kc-head-btn" id="kc-palette-trigger" title="Command Palette (Ctrl/Cmd+K)">⌨ Palette</button>
        <button type="button" class="kc-head-btn" id="kc-toggle-left" aria-pressed="true" title="Open Components Panel">Components</button>
        <?php if ($isStructured): ?>
        <button type="button" class="kc-head-btn" id="kc-preview-btn" title="Preview formatted document">Preview</button>
        <?php endif; ?>
        <a class="kc-head-btn<?= $liveUrl === '' ? ' is-disabled' : '' ?>" id="kc-live-link" href="<?= $liveUrl !== '' ? esc($liveUrl) : '#' ?>" target="_blank" rel="noopener" <?= $liveUrl === '' ? 'aria-disabled="true"' : '' ?> title="Open live URL in new tab">Open live</a>
        <button type="button" class="kc-head-btn" id="kc-toggle-right" aria-pressed="true" title="Toggle Inspector & Settings">Settings</button>
        <button type="submit" class="kc-head-btn kc-head-btn-primary" id="kc-save-btn" title="Save document (Ctrl/Cmd+S)">Save article</button>
      </div>
    </header>

    <div class="kc-title-wrapper">
      <label for="kc-doc-title" class="kc-title-label">DOCUMENT TITLE</label>
      <input class="kc-title-input" id="kc-doc-title" name="title" value="<?= esc($record['title'] ?? '') ?>" placeholder="Controlled Existing File Batch Migration" required autocomplete="off">
    </div>

    <?php if ($isStructured): ?>
    <!-- Toolbar Ribbon -->
    <div class="kc-ribbon" id="kc-ribbon">
      <div class="kc-ribbon-tabs-header">
        <button type="button" class="kc-ribbon-tab-btn is-active" data-ribbon-tab="home">Home</button>
      </div>
      <div class="kc-ribbon-row" data-ribbon-panel="home">
        <div class="kc-ribbon-group" title="History">
          <button type="button" class="kc-tool" data-cmd="undo" title="Undo (Ctrl/Cmd+Z)">↶</button>
          <button type="button" class="kc-tool" data-cmd="redo" title="Redo (Ctrl/Cmd+Y)">↷</button>
        </div>

        <div class="kc-ribbon-group" title="Paragraph / Heading style">
          <select class="kc-tool-select" id="kc-block-style" aria-label="Paragraph style">
            <option value="paragraph" selected>Paragraph</option>
            <option value="h1">Heading 1</option>
            <option value="h2">Heading 2</option>
            <option value="h3">Heading 3</option>
            <option value="h4">Heading 4</option>
            <option value="h5">Heading 5</option>
            <option value="h6">Heading 6</option>
          </select>
        </div>

        <div class="kc-ribbon-group" title="Inline formatting">
          <button type="button" class="kc-tool" data-inline="bold" title="Bold (Ctrl/Cmd+B)"><strong>B</strong></button>
          <button type="button" class="kc-tool" data-inline="italic" title="Italic (Ctrl/Cmd+I)"><em>I</em></button>
          <button type="button" class="kc-tool" data-inline="underline" title="Underline (Ctrl/Cmd+U)"><span style="text-decoration:underline">U</span></button>
          <button type="button" class="kc-tool" data-inline="inlineCode" title="Inline code">&lt;/&gt;</button>
          <button type="button" class="kc-tool" data-inline="highlight" title="Highlight text">HL</button>
        </div>

        <div class="kc-ribbon-group" title="Lists">
          <button type="button" class="kc-tool" data-insert="list-unordered" title="Bulleted list">• List</button>
          <button type="button" class="kc-tool" data-insert="list-ordered" title="Numbered list">1. List</button>
          <button type="button" class="kc-tool" data-insert="list-checklist" title="Checklist">☐ List</button>
        </div>

        <div class="kc-ribbon-group" title="Quick Elements">
          <button type="button" class="kc-tool" data-inline="link" title="Hyperlink">Link</button>
          <button type="button" class="kc-tool" data-insert="image" title="Insert Image">Image</button>
          <button type="button" class="kc-tool" data-insert="quote" title="Insert Quote">Quote</button>
          <select class="kc-tool-select" id="kc-callout-tone-select" aria-label="Insert Callout with tone">
            <option value="" disabled selected>Callout...</option>
            <option value="info">Info Callout</option>
            <option value="note">Note Callout</option>
            <option value="tip">Tip Callout</option>
            <option value="warning">Warning Callout</option>
            <option value="danger">Danger Callout</option>
            <option value="success">Success Callout</option>
          </select>
        </div>

        <div class="kc-ribbon-group" title="Table operations">
          <button type="button" class="kc-tool" data-insert="table" title="Insert Table">Table</button>
          <button type="button" class="kc-tool" data-table-op="add-col" title="Add Column">+Col</button>
          <button type="button" class="kc-tool" data-table-op="add-row" title="Add Row">+Row</button>
          <button type="button" class="kc-tool" data-table-op="del-col" title="Delete Column">-Col</button>
          <button type="button" class="kc-tool" data-table-op="del-row" title="Delete Row">-Row</button>
          <button type="button" class="kc-tool kc-tool-danger" data-cmd="delete-block" title="Delete Block">Del</button>
        </div>

        <div class="kc-ribbon-group" title="Alignment & Extras">
          <button type="button" class="kc-tool" data-align="left" title="Align Left">Left</button>
          <button type="button" class="kc-tool" data-align="center" title="Align Center">Center</button>
          <button type="button" class="kc-tool" data-align="right" title="Align Right">Right</button>
          <button type="button" class="kc-tool" data-insert="delimiter" title="Horizontal Rule">Rule</button>
          <button type="button" class="kc-tool" data-insert="code" title="Raw Code / HTML">HTML</button>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="kc-workspace-body">
    <?php if ($isStructured): ?>
    <!-- Left Pane: Components, Navigator, Patterns, Reusable -->
    <aside class="kc-left-pane" id="kc-left-pane" aria-label="Component Library, Navigator, Patterns, and Reusable">
      <div class="kc-pane-tabs">
        <button type="button" class="kc-pane-tab is-active" data-left-tab="components" aria-selected="true">Components</button>
        <button type="button" class="kc-pane-tab" data-left-tab="navigator" aria-selected="false">Navigator</button>
        <button type="button" class="kc-pane-tab" data-left-tab="patterns" aria-selected="false">Patterns</button>
        <button type="button" class="kc-pane-tab" data-left-tab="reusable" aria-selected="false">Reusable</button>
        <button type="button" class="kc-pane-close" id="kc-close-left" title="Close Library Panel" aria-label="Close Library Panel">✕</button>
      </div>

      <!-- Components Tab Panel -->
      <div class="kc-left-panel" data-left-panel="components">
        <div class="kc-search-wrap">
          <input class="kc-search" id="kc-component-search" type="search" placeholder="Search components (e.g. notice, steps, api)…" autocomplete="off" aria-label="Search components">
        </div>
        <div id="kc-component-list" class="kc-component-list">
          <?php
          $catalogGroups = [
              'basic' => 'Basic',
              'media' => 'Media',
              'structured' => 'Structured',
              'technical' => 'Technical',
              'notice' => 'Notices',
              'layout' => 'Layout',
              'legacy' => 'Legacy',
          ];
          $groupedCatalog = [];
          foreach ($catalog as $catItem) {
              $grp = $catItem['group'] ?? 'basic';
              $groupedCatalog[$grp][] = $catItem;
          }
          foreach ($catalogGroups as $grpKey => $grpLabel):
              if (!empty($groupedCatalog[$grpKey])):
          ?>
            <div class="kc-component-group" data-group="<?= esc($grpKey) ?>">
              <h4><?= esc($grpLabel) ?></h4>
              <div class="kc-component-grid">
                <?php foreach ($groupedCatalog[$grpKey] as $item): ?>
                  <?php
                    $ico = !empty($item['icon']) ? $item['icon'] : mb_substr($item['label'] ?? '?', 0, 1);
                    $alias = ($item['alias'] ?? '') . ' ' . ($item['keywords'] ?? '') . ' ' . ($grpLabel);
                  ?>
                  <button type="button" class="kc-component" draggable="true" data-component-id="<?= esc($item['id']) ?>" data-group="<?= esc($grpKey) ?>" data-keywords="<?= esc($alias) ?>" title="<?= esc($item['description'] ?? $item['label']) ?>">
                    <span class="kc-component-ico"><?= esc($ico) ?></span>
                    <strong><?= esc($item['label']) ?></strong>
                    <span class="kc-component-desc"><?= esc($item['description'] ?? '') ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
            </div>
          <?php
              endif;
          endforeach;
          ?>
        </div>
      </div>

      <!-- Navigator / Outline Tab Panel -->
      <div class="kc-left-panel" data-left-panel="navigator" hidden>
        <div class="kc-nav-header">
          <span>Document Hierarchy</span>
        </div>
        <div id="kc-outline-list" class="kc-outline-list">
          <div class="kc-empty-hint">Headings and layout containers in the document appear here. Click to jump.</div>
        </div>
      </div>

      <!-- Patterns Tab Panel -->
      <div class="kc-left-panel" data-left-panel="patterns" hidden>
        <div class="kc-nav-header">
          <span>Pre-Built Patterns</span>
        </div>
        <div class="kc-patterns-list" id="kc-patterns-sidebar-list">
          <?php foreach ($patternsList as $pattern): ?>
          <div class="kc-pattern-card" data-pattern-id="<?= esc($pattern['id']) ?>">
            <div class="kc-pattern-card-head">
              <span class="kc-pattern-ico"><?= esc($pattern['icon'] ?? '🧩') ?></span>
              <strong><?= esc($pattern['title']) ?></strong>
            </div>
            <p><?= esc($pattern['description']) ?></p>
            <button type="button" class="kc-btn-mini kc-insert-pattern-btn" data-pattern-id="<?= esc($pattern['id']) ?>">+ Insert Pattern</button>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Reusable Blocks Tab Panel -->
      <div class="kc-left-panel" data-left-panel="reusable" hidden>
        <div class="kc-nav-header">
          <span>Shared Reusable Blocks</span>
          <button type="button" class="kc-btn-mini" id="kc-create-reusable-btn">+ Create New</button>
        </div>
        <div class="kc-reusable-list" id="kc-reusable-sidebar-list">
          <div class="kc-empty-hint">No reusable blocks created yet. Select a block to save as reusable.</div>
        </div>
      </div>
    </aside>
    <div class="kc-resizer kc-resizer-left" id="kc-resizer-left" title="Drag to resize left panel" role="separator" aria-label="Resize left panel"></div>
    <?php endif; ?>

    <!-- Main Canvas Area -->
    <main class="kc-canvas" id="kc-canvas">
      <!-- Floating Reopen Buttons when sidebars are collapsed -->
      <button type="button" class="kc-float-reopen kc-float-reopen-left" id="kc-reopen-left" title="Open Components & Navigator" aria-label="Open Components & Navigator" hidden>
        <span>▦</span>
      </button>
      <button type="button" class="kc-float-reopen kc-float-reopen-right" id="kc-reopen-right" title="Open Inspector & Settings" aria-label="Open Inspector & Settings" hidden>
        <span>⚙</span>
      </button>

      <?php if (!$isStructured): ?>
      <!-- Legacy HTML Document Workspace -->
      <div class="kc-doc-stage">
        <div class="kc-doc-surface kc-legacy-surface">
          <div class="kc-legacy-banner">
            <div class="kc-legacy-badge">LEGACY HTML FORMAT</div>
            <h2>Preserved HTML Document</h2>
            <p>This document was authored in previous HTML format. Opening it in the structured editor will preserve your entire existing content safely as a structured legacy block.</p>
            <div class="kc-legacy-actions">
              <button type="submit" class="kc-head-btn kc-head-btn-primary" name="_action" value="convert_structured" id="kc-convert-structured">Open in structured editor</button>
              <div class="kc-legacy-view-switch">
                <button type="button" class="kc-btn-tab is-active" id="kc-legacy-tab-preview">👁 Preview</button>
                <button type="button" class="kc-btn-tab" id="kc-legacy-tab-edit">✏ Edit HTML</button>
              </div>
            </div>
          </div>
          <div class="kc-legacy-preview-wrap entry-content" id="kc-legacy-preview-container">
            <?= Html::sanitize($record['content'] ?? '') ?>
          </div>
          <div class="kc-legacy-editor-wrap" id="kc-legacy-editor-container" hidden>
            <label class="form-label" for="kc-legacy-html">Raw HTML content</label>
            <textarea class="form-textarea kc-legacy-html" id="kc-legacy-html" name="legacy_html" rows="18"><?= htmlspecialchars($record['content'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></textarea>
          </div>
        </div>
      </div>
      <?php else: ?>
      <!-- Structured Block Canvas -->
      <div class="kc-doc-stage" id="kc-doc-stage">
        <div class="kc-doc-surface">
          <div id="kc-editorjs"></div>
          <div id="kc-editor-error" class="kc-editor-error-panel" hidden role="alert"></div>
          <div class="kc-empty-state" id="kc-empty-state" hidden>
            <strong>Start writing…</strong>
            <span>Type <kbd>/</kbd> to insert a block · or choose from Components</span>
            <button type="button" class="kc-head-btn" id="kc-empty-insert">+ Add component</button>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </main>

    <div class="kc-resizer kc-resizer-right" id="kc-resizer-right" title="Drag to resize right panel" role="separator" aria-label="Resize right panel"></div>

    <!-- Right Pane: Document, Block, and Layout Inspector -->
    <aside class="kc-right-pane" id="kc-right-pane" aria-label="Document, Block, and Layout Inspector">
      <div class="kc-pane-tabs">
        <button type="button" class="kc-pane-tab is-active" data-right-tab="doc" aria-selected="true">Document</button>
        <button type="button" class="kc-pane-tab" data-right-tab="block" aria-selected="false">Block</button>
        <button type="button" class="kc-pane-tab" data-right-tab="layout" aria-selected="false">Layout</button>
        <button type="button" class="kc-pane-close" id="kc-close-right" title="Close Inspector Panel" aria-label="Close Inspector Panel">✕</button>
      </div>

      <!-- Document Tab Panel -->
      <div class="kc-right-panel" data-right-panel="doc">
        <!-- Card 1: Publish & Actions -->
        <div class="kc-settings-card">
          <div class="kc-card-head">
            <strong>Publish</strong>
            <span class="kc-card-badge is-<?= esc($currentStatus) ?>" id="kc-doc-status-chip" data-status="<?= esc($currentStatus) ?>"><?= esc(strtoupper($currentStatus)) ?></span>
          </div>

          <div class="kc-field">
            <label for="kc-doc-status">Document status</label>
            <select class="kc-card-select" id="kc-doc-status" name="status">
              <?php foreach (['draft' => 'Draft', 'in_review' => 'In Review', 'approved' => 'Approved', 'published' => 'Published', 'archived' => 'Archived'] as $value => $label): ?>
              <option value="<?= $value ?>" <?= $currentStatus === $value ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="kc-field">
            <label for="kc-doc-slug">URL slug</label>
            <input class="kc-card-input" id="kc-doc-slug" name="slug" value="<?= esc($record['slug'] ?? '') ?>" placeholder="url-slug-handle">
            <span class="kc-slug-preview-link" id="kc-slug-preview-link"><?= esc($liveUrl !== '' ? $liveUrl : '/docs/' . ($record['slug'] ?? '')) ?></span>
          </div>

          <div class="kc-cat-box">
            <div class="kc-cat-badge-row">
              <span class="kc-cat-label">Category</span>
              <strong class="kc-cat-val">Developer Docs</strong>
            </div>
            <p class="kc-cat-note">Category assignment remains automatic and cannot be changed from this workspace.</p>
          </div>

          <div class="kc-card-actions">
            <button type="submit" class="kc-card-btn kc-card-btn-primary" id="kc-card-save-btn">Save article</button>
            <button type="button" class="kc-card-btn kc-card-btn-secondary" id="kc-card-preview-btn">Preview article</button>
            <a class="kc-card-btn kc-card-btn-secondary" id="kc-card-live-link" href="<?= $liveUrl !== '' ? esc($liveUrl) : '#' ?>" target="_blank" rel="noopener">View public article</a>
          </div>
        </div>

        <!-- Card 2: Summary -->
        <div class="kc-settings-card">
          <div class="kc-card-head">
            <strong>Summary</strong>
          </div>
          <div class="kc-field">
            <label for="kc-doc-excerpt">Article excerpt</label>
            <textarea class="kc-card-textarea" id="kc-doc-excerpt" name="excerpt" rows="3" placeholder="Brief summary excerpt for cards and search…"><?= esc($record['excerpt'] ?? '') ?></textarea>
          </div>
        </div>

        <!-- Card 3: Search metadata -->
        <div class="kc-settings-card">
          <div class="kc-card-head">
            <strong>Search metadata</strong>
          </div>
          <div class="kc-field">
            <label for="kc-doc-meta-title">Meta title</label>
            <input class="kc-card-input" id="kc-doc-meta-title" name="meta_title" value="<?= esc($record['meta_title'] ?? '') ?>" placeholder="Custom page title for search engines…">
          </div>
          <div class="kc-field">
            <label for="kc-doc-meta-desc">Meta description</label>
            <textarea class="kc-card-textarea" id="kc-doc-meta-desc" name="meta_desc" rows="3" placeholder="Brief description for search result snippets…"><?= esc($record['meta_desc'] ?? '') ?></textarea>
          </div>
        </div>
      </div>

      <!-- Block Tab Panel -->
      <div class="kc-right-panel" data-right-panel="block" hidden>
        <div class="kc-inspector-section" id="kc-block-inspector">
          <h3>Block Properties</h3>
          <p class="kc-empty-hint" id="kc-selected-meta">Select a block on the canvas to configure properties.</p>
          <div id="kc-block-fields"></div>
        </div>
      </div>

      <!-- Layout Tab Panel -->
      <div class="kc-right-panel" data-right-panel="layout" hidden>
        <div class="kc-inspector-section" id="kc-layout-inspector">
          <h3>Layout Controls</h3>
          <div class="kc-field">
            <label>Layout Preset</label>
            <select id="kc-layout-preset-select" class="kc-tool-select">
              <option value="default">Standard Prose (860px max)</option>
              <option value="wide">Wide Content (1100px max)</option>
              <option value="full">Full Width</option>
            </select>
          </div>
          <div class="kc-field">
            <label>Column Stacking on Mobile</label>
            <label class="kc-check"><input type="checkbox" id="kc-stack-mobile" checked> Stack columns vertically on mobile</label>
          </div>
        </div>
      </div>
    </aside>
  </div>

  <!-- Bottom Status Bar -->
  <footer class="kc-statusbar">
    <div class="kc-status-left">
      <span id="kc-word-count">0 words</span>
      <span class="kc-status-sep">·</span>
      <span id="kc-char-count">0 characters</span>
      <span class="kc-status-sep">·</span>
      <span id="kc-read-time">1 min read</span>
    </div>
    <div class="kc-status-right">
      <span id="kc-selected-block-type">No block selected</span>
      <span class="kc-status-sep">·</span>
      <span id="kc-status-bar-state"><?= esc(ucfirst($currentStatus)) ?></span>
      <span class="kc-status-sep">·</span>
      <span id="kc-status-bar-save"><?= $isNew ? 'Not saved' : 'Saved' ?></span>
    </div>
  </footer>
</form>

<!-- Formatted Preview Modal -->
<div class="kc-modal" id="kc-preview-modal" hidden>
  <div class="kc-modal-card">
    <div class="kc-modal-head">
      <strong>Document Preview</strong>
      <button type="button" class="kc-head-btn" data-kc-close>Close</button>
    </div>
    <div class="kc-modal-body">
      <div class="kc-preview-frame entry-content" id="kc-preview-body"></div>
    </div>
  </div>
</div>

<!-- Revisions History Modal -->
<div class="kc-modal" id="kc-revisions-modal" hidden>
  <div class="kc-modal-card kc-modal-card-lg">
    <div class="kc-modal-head">
      <strong>Document Revision History</strong>
      <button type="button" class="kc-head-btn" data-kc-close>Close</button>
    </div>
    <div class="kc-modal-body">
      <div class="kc-revisions-grid">
        <div class="kc-revisions-sidebar">
          <h4>Revisions</h4>
          <div id="kc-revisions-list" class="kc-rev-list">
            <div class="kc-empty-hint">Loading revisions…</div>
          </div>
        </div>
        <div class="kc-revisions-preview-pane">
          <div class="kc-rev-preview-head">
            <span id="kc-rev-selected-title">Select a revision to inspect</span>
            <button type="button" class="kc-head-btn kc-head-btn-primary" id="kc-restore-rev-btn" disabled>Restore This Revision</button>
          </div>
          <div id="kc-rev-diff-view" class="kc-rev-diff-body">
            <p class="kc-empty-hint">Select a revision from the left to view contents.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Templates Modal -->
<div class="kc-modal" id="kc-templates-modal" hidden>
  <div class="kc-modal-card kc-modal-card-lg">
    <div class="kc-modal-head">
      <strong>Document Starter Templates</strong>
      <button type="button" class="kc-head-btn" data-kc-close>Close</button>
    </div>
    <div class="kc-modal-body">
      <p class="kc-modal-intro">Choose a template to pre-populate your document with structured sections and blocks.</p>
      <div class="kc-templates-grid">
        <?php foreach ($templatesList as $tmpl): ?>
        <div class="kc-template-card" data-template-id="<?= esc($tmpl['id']) ?>">
          <div class="kc-template-ico"><?= esc($tmpl['icon']) ?></div>
          <div class="kc-template-info">
            <strong><?= esc($tmpl['title']) ?></strong>
            <span class="kc-template-cat"><?= esc($tmpl['category']) ?></span>
            <p><?= esc($tmpl['description']) ?></p>
          </div>
          <button type="button" class="kc-btn-mini kc-apply-template-btn" data-template-id="<?= esc($tmpl['id']) ?>">Use Template</button>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- Command Palette Modal (Ctrl/Cmd+K) -->
<div class="kc-modal kc-modal-palette" id="kc-palette-modal" hidden>
  <div class="kc-palette-box">
    <div class="kc-palette-input-wrap">
      <span class="kc-palette-ico">⌨</span>
      <input type="search" id="kc-palette-input" class="kc-palette-input" placeholder="Type a command or block name…" autocomplete="off">
      <kbd>ESC</kbd>
    </div>
    <div class="kc-palette-results" id="kc-palette-results"></div>
  </div>
</div>

<!-- Concurrency Conflict Resolution Dialog -->
<div class="kc-modal" id="kc-conflict-modal" hidden>
  <div class="kc-modal-card">
    <div class="kc-modal-head kc-modal-head-danger">
      <strong>⚠️ Edit Conflict Detected</strong>
    </div>
    <div class="kc-modal-body">
      <p>This document was updated in another browser session while you were editing.</p>
      <p>To prevent accidental overwrites, you can choose how to proceed:</p>
      <div class="kc-conflict-actions">
        <button type="button" class="kc-head-btn kc-head-btn-danger" id="kc-conflict-overwrite">Overwrite with My Changes</button>
        <button type="button" class="kc-head-btn" id="kc-conflict-reload">Reload Server Version</button>
      </div>
    </div>
  </div>
</div>

<!-- Media Library Modal -->
<div class="kc-modal" id="kc-media-modal" hidden>
  <div class="kc-modal-card">
    <div class="kc-modal-head">
      <strong>Media library</strong>
      <button type="button" class="kc-head-btn" data-kc-close>Close</button>
    </div>
    <div class="kc-modal-body">
      <input class="form-input" id="kc-media-search" type="search" placeholder="Search media by filename…">
      <div class="kc-media-grid" id="kc-media-grid"></div>
    </div>
  </div>
</div>

<script type="application/json" id="kc-editor-config"><?= json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php
$asset = static function (string $path) use ($editorVersion): string {
    return esc(soi_admin_asset_url($path)) . '?v=' . rawurlencode($editorVersion);
};
if ($isStructured) {
    $extraScripts = ($extraScripts ?? '') . '
<script src="' . $asset('editor/vendor/editorjs.js') . '"></script>
<script src="' . $asset('editor/vendor/header.js') . '"></script>
<script src="' . $asset('editor/vendor/list.js') . '"></script>
<script src="' . $asset('editor/vendor/quote.js') . '"></script>
<script src="' . $asset('editor/vendor/delimiter.js') . '"></script>
<script src="' . $asset('editor/vendor/table.js') . '"></script>
<script src="' . $asset('editor/vendor/underline.js') . '"></script>
<script src="' . $asset('editor/vendor/inline-code.js') . '"></script>
<script src="' . $asset('editor/runtime/bootstrap.js') . '"></script>
<script src="' . $asset('editor/registry.js') . '"></script>
<script src="' . $asset('editor/ui/block-toolbar.js') . '"></script>
<script src="' . $asset('editor/ui/inspector-shell.js') . '"></script>
<script src="' . $asset('editor/ui/navigator-shell.js') . '"></script>
<script src="' . $asset('editor/layout/drag-drop.js') . '"></script>
<script src="' . $asset('editor/layout/layout-inspector.js') . '"></script>
<script src="' . $asset('editor/blocks/favorites.js') . '"></script>
<script src="' . $asset('editor/blocks/callout.js') . '"></script>
<script src="' . $asset('editor/blocks/link.js') . '"></script>
<script src="' . $asset('editor/blocks/code.js') . '"></script>
<script src="' . $asset('editor/blocks/image.js') . '"></script>
<script src="' . $asset('editor/blocks/file.js') . '"></script>
<script src="' . $asset('editor/blocks/legacy.js') . '"></script>
<script src="' . $asset('editor/blocks/enterprise.js') . '"></script>
<script src="' . $asset('editor/kc-editor.js') . '"></script>
';
} else {
    $extraScripts = ($extraScripts ?? '') . '
<script src="' . $asset('editor/kc-editor.js') . '"></script>
';
}
?>
