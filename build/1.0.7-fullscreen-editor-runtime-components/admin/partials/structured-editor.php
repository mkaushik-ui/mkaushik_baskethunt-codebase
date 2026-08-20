<?php
/**
 * Enterprise authoring workspace chrome.
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

$record = $editorRecord ?? [];
$entity = $editorEntity ?? 'page';
$isNew = !empty($editorIsNew);
$isStructured = $isNew || EditorSchema::isStructured($record);
$editorVersion = '1.0.7';
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
<form method="POST" id="kc-editor" class="kc-editor kc-workspace" data-mode="<?= $isStructured ? 'structured' : 'legacy' ?>" data-left="open" data-right="open" data-left-tab="components">
  <?= Auth::csrfField() ?>
  <input type="hidden" name="_action" value="save">
  <input type="hidden" name="id" id="kc-doc-id" value="<?= (int) ($record['id'] ?? 0) ?>">
  <input type="hidden" name="mode" id="kc-editor-mode" value="<?= $isStructured ? 'structured' : 'legacy' ?>">
  <input type="hidden" name="expected_updated_at" id="kc-updated-at" value="<?= esc($record['updated_at'] ?? '') ?>">
  <input type="hidden" name="document" id="kc-document-json" value="<?= esc(Document::encode($initialDocument)) ?>">

  <header class="kc-workspace-head">
    <div class="kc-head-left">
      <a class="kc-head-btn" href="<?= esc($editorListUrl) ?>">← Back</a>
      <input class="kc-title-input" id="kc-doc-title" name="title" value="<?= esc($record['title'] ?? '') ?>" placeholder="Document title" required>
    </div>
    <div class="kc-head-right">
      <span class="kc-save-chip" id="kc-save-status" data-state="<?= $isNew ? 'new' : 'saved' ?>"><?= $isNew ? 'Not saved' : 'Saved' ?></span>
      <select class="kc-status-select" id="kc-doc-status" name="status" aria-label="Document status">
        <?php foreach (['draft' => 'Draft', 'published' => 'Published', 'private' => 'Private'] as $value => $label): ?>
        <option value="<?= $value ?>" <?= $currentStatus === $value ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($isStructured): ?>
      <button type="button" class="kc-head-btn" id="kc-preview-btn">Preview</button>
      <?php endif; ?>
      <a class="kc-head-btn<?= $liveUrl === '' ? ' is-disabled' : '' ?>" id="kc-live-link" href="<?= $liveUrl !== '' ? esc($liveUrl) : '#' ?>" target="_blank" rel="noopener" <?= $liveUrl === '' ? 'aria-disabled="true"' : '' ?>>Open live</a>
      <button type="button" class="kc-head-btn" id="kc-toggle-right" aria-pressed="true">Settings</button>
      <button type="submit" class="kc-head-btn kc-head-btn-primary" id="kc-save-btn">Save</button>
    </div>
  </header>

  <?php if ($isStructured): ?>
  <div class="kc-ribbon" id="kc-ribbon">
    <div class="kc-ribbon-tabs" role="tablist">
      <button type="button" class="kc-ribbon-tab is-active" data-ribbon-tab="home">Home</button>
      <button type="button" class="kc-ribbon-tab" data-ribbon-tab="insert">Insert</button>
    </div>
    <div class="kc-ribbon-row" data-ribbon-panel="home">
      <div class="kc-ribbon-group">
        <button type="button" class="kc-tool" data-cmd="undo" title="Undo">Undo</button>
        <button type="button" class="kc-tool" data-cmd="redo" title="Redo">Redo</button>
      </div>
      <div class="kc-ribbon-group">
        <select class="kc-tool-select" id="kc-block-style" aria-label="Paragraph style">
          <option value="paragraph">Paragraph</option>
          <option value="h2">Heading 2</option>
          <option value="h3">Heading 3</option>
          <option value="h4">Heading 4</option>
        </select>
      </div>
      <div class="kc-ribbon-group">
        <button type="button" class="kc-tool" data-inline="bold" title="Bold"><strong>B</strong></button>
        <button type="button" class="kc-tool" data-inline="italic" title="Italic"><em>I</em></button>
        <button type="button" class="kc-tool" data-inline="underline" title="Underline"><span style="text-decoration:underline">U</span></button>
        <button type="button" class="kc-tool" data-inline="strike" title="Strikethrough"><s>S</s></button>
        <button type="button" class="kc-tool" data-inline="inlineCode" title="Inline code">&lt;/&gt;</button>
        <button type="button" class="kc-tool" data-inline="link" title="Hyperlink">Link</button>
      </div>
      <div class="kc-ribbon-group">
        <button type="button" class="kc-tool" data-insert="list-unordered" title="Bulleted list">• List</button>
        <button type="button" class="kc-tool" data-insert="list-ordered" title="Numbered list">1. List</button>
        <button type="button" class="kc-tool" data-insert="list-checklist" title="Checklist">☐ List</button>
      </div>
      <div class="kc-ribbon-group">
        <button type="button" class="kc-tool" id="kc-toggle-left" aria-pressed="true">Components</button>
      </div>
    </div>
    <div class="kc-ribbon-row" data-ribbon-panel="insert" hidden>
      <div class="kc-ribbon-group">
        <button type="button" class="kc-tool" data-insert="image">Image</button>
        <button type="button" class="kc-tool" data-insert="file">File</button>
        <button type="button" class="kc-tool" data-insert="table">Table</button>
        <button type="button" class="kc-tool" data-insert="divider">Divider</button>
        <button type="button" class="kc-tool" data-insert="quote">Quote</button>
        <button type="button" class="kc-tool" data-insert="code">Code</button>
        <button type="button" class="kc-tool" data-insert="callout">Callout</button>
        <button type="button" class="kc-tool" data-insert="link">Link card</button>
      </div>
      <div class="kc-ribbon-group">
        <button type="button" class="kc-tool" data-insert="steps">Steps</button>
        <button type="button" class="kc-tool" data-insert="accordion">Accordion</button>
        <button type="button" class="kc-tool" data-insert="faq">FAQ</button>
        <button type="button" class="kc-tool" data-insert="tabs">Tabs</button>
        <button type="button" class="kc-tool" data-insert="codeGroup">Code Group</button>
        <button type="button" class="kc-tool" data-insert="definitionList">Definitions</button>
        <button type="button" class="kc-tool" data-insert="statusBadge">Status</button>
      </div>
      <div class="kc-ribbon-group">
        <button type="button" class="kc-tool" data-insert="group">Group</button>
        <button type="button" class="kc-tool" data-insert="columns">Columns</button>
        <button type="button" class="kc-tool" data-insert="cards">Cards</button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="kc-workspace-body">
    <?php if ($isStructured): ?>
    <aside class="kc-left-pane" id="kc-left-pane">
      <div class="kc-pane-tabs">
        <button type="button" class="kc-pane-tab is-active" data-left-tab="components">Components</button>
        <button type="button" class="kc-pane-tab" data-left-tab="outline">Outline</button>
      </div>
      <div class="kc-left-panel" data-left-panel="components">
        <input class="kc-search" id="kc-component-search" type="search" placeholder="Search components…" autocomplete="off">
        <div id="kc-component-list" class="kc-component-list"></div>
      </div>
      <div class="kc-left-panel" data-left-panel="outline" hidden>
        <div id="kc-outline-list" class="kc-outline-list">
          <div class="kc-empty-hint">Headings will appear here.</div>
        </div>
      </div>
    </aside>
    <?php endif; ?>

    <main class="kc-canvas">
      <?php if (!$isStructured): ?>
      <div class="kc-legacy-banner">
        <p>This document still uses the previous HTML authoring format. Opening it in the structured editor keeps the existing HTML as a preserved legacy block — nothing is mass-converted.</p>
        <button type="submit" class="kc-head-btn kc-head-btn-primary" name="_action" value="convert_structured" id="kc-convert-structured">Open in structured editor</button>
      </div>
      <label class="form-label" for="kc-legacy-html">HTML content</label>
      <textarea class="form-textarea kc-legacy-html" id="kc-legacy-html" name="legacy_html" rows="18"><?= htmlspecialchars($record['content'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></textarea>
      <?php else: ?>
      <div class="kc-doc-stage">
        <div class="kc-doc-surface">
          <div id="kc-editorjs"></div>
          <div id="kc-editor-error" class="kc-editor-error-panel" hidden role="alert"></div>
          <div class="kc-empty-state" id="kc-empty-state" hidden>
            <strong>Start writing…</strong>
            <span>Type <kbd>/</kbd> to insert a block · or use Components</span>
            <button type="button" class="kc-head-btn" id="kc-empty-insert">+ Add component</button>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </main>

    <aside class="kc-right-pane" id="kc-right-pane">
      <div class="kc-inspector-section">
        <h3>Document</h3>
        <div class="kc-field">
          <label for="kc-doc-slug">Slug</label>
          <input id="kc-doc-slug" name="slug" value="<?= esc($record['slug'] ?? '') ?>" placeholder="auto-generated-from-title">
        </div>
        <?php if (!empty($editorShowExcerpt)): ?>
        <div class="kc-field">
          <label for="kc-doc-excerpt">Excerpt</label>
          <textarea id="kc-doc-excerpt" name="excerpt" rows="3"><?= esc($record['excerpt'] ?? '') ?></textarea>
        </div>
        <?php endif; ?>
        <div class="kc-field">
          <label>Meta title</label>
          <input name="meta_title" value="<?= esc($record['meta_title'] ?? '') ?>">
        </div>
        <div class="kc-field">
          <label>Meta description</label>
          <textarea name="meta_desc" rows="3"><?= esc($record['meta_desc'] ?? '') ?></textarea>
        </div>
        <?php if (!empty($editorCategories)): ?>
        <div class="kc-field">
          <span class="kc-field-label">Categories</span>
          <?php foreach ($editorCategories as $cat): ?>
          <label class="kc-check">
            <input type="checkbox" name="categories[]" value="<?= (int) $cat['id'] ?>" <?= in_array($cat['id'], $editorAssignedCats ?? []) ? 'checked' : '' ?>>
            <?= esc($cat['name']) ?>
          </label>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php if ($isStructured): ?>
      <div class="kc-inspector-section" id="kc-block-inspector">
        <h3>Block</h3>
        <p class="kc-empty-hint" id="kc-selected-meta">Select a block to edit its settings.</p>
        <div id="kc-block-fields"></div>
      </div>
      <?php endif; ?>
    </aside>
  </div>

  <footer class="kc-statusbar">
    <span id="kc-word-count">0 words</span>
    <span id="kc-char-count">0 characters</span>
    <span id="kc-status-bar-state"><?= esc(ucfirst($currentStatus)) ?></span>
    <span id="kc-status-bar-save"><?= $isNew ? 'Not saved' : 'Saved' ?></span>
  </footer>
</form>

<div class="kc-modal" id="kc-preview-modal" hidden>
  <div class="kc-modal-card">
    <div class="kc-modal-head">
      <strong>Preview</strong>
      <button type="button" class="kc-head-btn" data-kc-close>Close</button>
    </div>
    <div class="kc-modal-body">
      <div class="kc-preview-frame entry-content" id="kc-preview-body"></div>
    </div>
  </div>
</div>

<div class="kc-modal" id="kc-media-modal" hidden>
  <div class="kc-modal-card">
    <div class="kc-modal-head">
      <strong>Media library</strong>
      <button type="button" class="kc-head-btn" data-kc-close>Close</button>
    </div>
    <div class="kc-modal-body">
      <input class="form-input" id="kc-media-search" type="search" placeholder="Search media…">
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
