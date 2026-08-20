<?php
/**
 * Enterprise authoring workspace chrome (1.0.9).
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

$record = $editorRecord ?? [];
$entity = $editorEntity ?? 'page';
$isNew = !empty($editorIsNew);
$isStructured = $isNew || EditorSchema::isStructured($record);
$editorVersion = '1.0.9';
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
];
$liveUrl = $config['viewUrl'];
$currentStatus = (string) ($record['status'] ?? 'draft');
?>
<form method="POST" id="kc-editor" class="kc-editor kc-workspace" data-mode="<?= $isStructured ? 'structured' : 'legacy' ?>" data-left="open" data-right="open" data-left-tab="components" data-editor-state="loading">
  <?= Auth::csrfField() ?>
  <input type="hidden" name="_action" value="save">
  <input type="hidden" name="id" id="kc-doc-id" value="<?= (int) ($record['id'] ?? 0) ?>">
  <input type="hidden" name="mode" id="kc-editor-mode" value="<?= $isStructured ? 'structured' : 'legacy' ?>">
  <input type="hidden" name="expected_updated_at" id="kc-updated-at" value="<?= esc($record['updated_at'] ?? '') ?>">
  <input type="hidden" name="document" id="kc-document-json" value="<?= esc(Document::encode($initialDocument)) ?>">

  <!-- Workspace Head / Top Navigation -->
  <header class="kc-workspace-head">
    <div class="kc-head-left">
      <a class="kc-head-btn" href="<?= esc($editorListUrl) ?>" title="Back to document list">← Back</a>
      <input class="kc-title-input" id="kc-doc-title" name="title" value="<?= esc($record['title'] ?? '') ?>" placeholder="Document title" required autocomplete="off">
    </div>
    <div class="kc-head-right">
      <span class="kc-save-chip" id="kc-save-status" data-state="<?= $isNew ? 'new' : 'saved' ?>"><?= $isNew ? 'Not saved' : 'Saved' ?></span>
      <select class="kc-status-select" id="kc-doc-status" name="status" aria-label="Document status">
        <?php foreach (['draft' => 'Draft', 'published' => 'Published', 'private' => 'Private'] as $value => $label): ?>
        <option value="<?= $value ?>" <?= $currentStatus === $value ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($isStructured): ?>
      <button type="button" class="kc-head-btn" id="kc-preview-btn" title="Preview formatted document">Preview</button>
      <?php endif; ?>
      <a class="kc-head-btn<?= $liveUrl === '' ? ' is-disabled' : '' ?>" id="kc-live-link" href="<?= $liveUrl !== '' ? esc($liveUrl) : '#' ?>" target="_blank" rel="noopener" <?= $liveUrl === '' ? 'aria-disabled="true"' : '' ?> title="Open live URL in new tab">Open live ↗</a>
      <button type="button" class="kc-head-btn" id="kc-toggle-right" aria-pressed="true" title="Toggle Document & Block Settings">⚙ Settings</button>
      <button type="submit" class="kc-head-btn kc-head-btn-primary" id="kc-save-btn" title="Save document (Ctrl/Cmd+S)">Save</button>
    </div>
  </header>

  <?php if ($isStructured): ?>
  <!-- Ribbon Toolbar -->
  <div class="kc-ribbon" id="kc-ribbon">
    <div class="kc-ribbon-tabs" role="tablist">
      <button type="button" class="kc-ribbon-tab is-active" data-ribbon-tab="home">Home</button>
      <button type="button" class="kc-ribbon-tab" data-ribbon-tab="insert">Insert</button>
    </div>
    <div class="kc-ribbon-row" data-ribbon-panel="home">
      <div class="kc-ribbon-group" title="History">
        <button type="button" class="kc-tool" data-cmd="undo" title="Undo (Ctrl/Cmd+Z)">↶</button>
        <button type="button" class="kc-tool" data-cmd="redo" title="Redo (Ctrl/Cmd+Y)">↷</button>
      </div>
      <div class="kc-ribbon-group" title="Paragraph / Heading style">
        <select class="kc-tool-select" id="kc-block-style" aria-label="Paragraph style">
          <option value="paragraph">Paragraph</option>
          <option value="h2">Heading 2</option>
          <option value="h3">Heading 3</option>
          <option value="h4">Heading 4</option>
        </select>
      </div>
      <div class="kc-ribbon-group" title="Inline formatting">
        <button type="button" class="kc-tool" data-inline="bold" title="Bold (Ctrl/Cmd+B)"><strong>B</strong></button>
        <button type="button" class="kc-tool" data-inline="italic" title="Italic (Ctrl/Cmd+I)"><em>I</em></button>
        <button type="button" class="kc-tool" data-inline="underline" title="Underline (Ctrl/Cmd+U)"><span style="text-decoration:underline">U</span></button>
        <button type="button" class="kc-tool" data-inline="strike" title="Strikethrough"><s>S</s></button>
        <button type="button" class="kc-tool" data-inline="inlineCode" title="Inline code">&lt;/&gt;</button>
        <button type="button" class="kc-tool" data-inline="link" title="Hyperlink">🔗</button>
      </div>
      <div class="kc-ribbon-group" title="Lists">
        <button type="button" class="kc-tool" data-insert="list-unordered" title="Bulleted list">• List</button>
        <button type="button" class="kc-tool" data-insert="list-ordered" title="Numbered list">1. List</button>
        <button type="button" class="kc-tool" data-insert="list-checklist" title="Checklist">☑ List</button>
      </div>
      <div class="kc-ribbon-group" title="Panels">
        <button type="button" class="kc-tool" id="kc-toggle-left" aria-pressed="true" title="Toggle Components Library">▦ Components</button>
      </div>
    </div>
    <div class="kc-ribbon-row" data-ribbon-panel="insert" hidden>
      <div class="kc-ribbon-group" title="Insert elements">
        <button type="button" class="kc-tool" data-insert="image" title="Insert Image">🖼 Image</button>
        <button type="button" class="kc-tool" data-insert="file" title="Insert File Attachment">📎 File</button>
        <button type="button" class="kc-tool" data-insert="table" title="Insert Table">▦ Table</button>
        <button type="button" class="kc-tool" data-insert="quote" title="Insert Quote">“ Quote</button>
        <button type="button" class="kc-tool" data-insert="divider" title="Insert Divider Line">— Divider</button>
        <button type="button" class="kc-tool" data-insert="code" title="Insert Code Block">&lt;/&gt; Code</button>
        <button type="button" class="kc-tool" data-insert="callout" title="Insert Callout Notice">! Callout</button>
        <button type="button" class="kc-tool" data-insert="link" title="Insert Link Card">🔗 Link Card</button>
      </div>
      <div class="kc-ribbon-group">
        <button type="button" class="kc-tool" id="kc-ribbon-more-components" title="Open Components Panel for Steps, Tabs, FAQ, Layout blocks">▦ More components…</button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="kc-workspace-body">
    <?php if ($isStructured): ?>
    <!-- Left Pane: Components & Outline -->
    <aside class="kc-left-pane" id="kc-left-pane" aria-label="Component Library and Document Outline">
      <div class="kc-pane-tabs">
        <button type="button" class="kc-pane-tab is-active" data-left-tab="components" aria-selected="true">Components</button>
        <button type="button" class="kc-pane-tab" data-left-tab="outline" aria-selected="false">Outline</button>
        <button type="button" class="kc-pane-close" id="kc-close-left" title="Close Components Panel" aria-label="Close Components Panel">✕</button>
      </div>
      <div class="kc-left-panel" data-left-panel="components">
        <div class="kc-search-wrap">
          <input class="kc-search" id="kc-component-search" type="search" placeholder="Search components (e.g. notice, steps, code)…" autocomplete="off" aria-label="Search components">
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
      <div class="kc-left-panel" data-left-panel="outline" hidden>
        <div id="kc-outline-list" class="kc-outline-list">
          <div class="kc-empty-hint">Headings added to the document will appear here.</div>
        </div>
      </div>
    </aside>
    <?php endif; ?>

    <!-- Main Canvas Area -->
    <main class="kc-canvas" id="kc-canvas">
      <!-- Floating Reopen Buttons when sidebars are collapsed -->
      <button type="button" class="kc-float-reopen kc-float-reopen-left" id="kc-reopen-left" title="Open Components Panel" aria-label="Open Components Panel" hidden>
        <span>▦</span>
      </button>
      <button type="button" class="kc-float-reopen kc-float-reopen-right" id="kc-reopen-right" title="Open Settings & Inspector" aria-label="Open Settings & Inspector" hidden>
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
      <div class="kc-doc-stage">
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

    <!-- Right Pane: Document & Block Inspector -->
    <aside class="kc-right-pane" id="kc-right-pane" aria-label="Document and Block Inspector">
      <div class="kc-pane-head">
        <div class="kc-pane-title">
          <span class="kc-pane-ico">⚙</span>
          <strong>Settings & Inspector</strong>
        </div>
        <button type="button" class="kc-pane-close" id="kc-close-right" title="Close Settings Panel" aria-label="Close Settings Panel">✕</button>
      </div>

      <div class="kc-inspector-section">
        <h3>Document metadata</h3>
        <div class="kc-field">
          <label for="kc-doc-slug">URL Slug</label>
          <input id="kc-doc-slug" name="slug" value="<?= esc($record['slug'] ?? '') ?>" placeholder="auto-generated-from-title">
        </div>
        <?php if (!empty($editorShowExcerpt)): ?>
        <div class="kc-field">
          <label for="kc-doc-excerpt">Excerpt</label>
          <textarea id="kc-doc-excerpt" name="excerpt" rows="3" placeholder="Summary excerpt for cards and search…"><?= esc($record['excerpt'] ?? '') ?></textarea>
        </div>
        <?php endif; ?>
        <div class="kc-field">
          <label>Meta title (SEO)</label>
          <input name="meta_title" value="<?= esc($record['meta_title'] ?? '') ?>" placeholder="Custom page title for search engines…">
        </div>
        <div class="kc-field">
          <label>Meta description (SEO)</label>
          <textarea name="meta_desc" rows="3" placeholder="Brief description for search result snippets…"><?= esc($record['meta_desc'] ?? '') ?></textarea>
        </div>
        <?php if (!empty($editorCategories)): ?>
        <div class="kc-field">
          <span class="kc-field-label">Categories</span>
          <div class="kc-categories-box">
            <?php foreach ($editorCategories as $cat): ?>
            <label class="kc-check">
              <input type="checkbox" name="categories[]" value="<?= (int) $cat['id'] ?>" <?= in_array($cat['id'], $editorAssignedCats ?? []) ? 'checked' : '' ?>>
              <?= esc($cat['name']) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($isStructured): ?>
      <div class="kc-inspector-section" id="kc-block-inspector">
        <h3>Block settings</h3>
        <p class="kc-empty-hint" id="kc-selected-meta">Select a block on the canvas to configure settings.</p>
        <div id="kc-block-fields"></div>
      </div>
      <?php endif; ?>
    </aside>
  </div>

  <!-- Bottom Status Bar -->
  <footer class="kc-statusbar">
    <div class="kc-status-left">
      <span id="kc-word-count">0 words</span>
      <span class="kc-status-sep">·</span>
      <span id="kc-char-count">0 characters</span>
    </div>
    <div class="kc-status-right">
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
<script src="' . $asset('editor/registry.js') . '"></script>
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
