<?php
/**
 * Shared structured / legacy document editor chrome.
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
use SOI\Core\Content\Document;
use SOI\Core\Content\EditorSchema;

$record = $editorRecord ?? [];
$entity = $editorEntity ?? 'page';
$isNew = !empty($editorIsNew);
$isStructured = $isNew || EditorSchema::isStructured($record);
$editorVersion = '1.0.0';
$initialDocument = Document::empty();
if ($isStructured && !empty($record['body_json'])) {
    try {
        $initialDocument = Document::parse($record['body_json']);
    } catch (Throwable $e) {
        $initialDocument = Document::empty();
    }
}
$editorJsData = Document::toEditorJs($initialDocument);
$config = [
    'entity' => $entity,
    'mode' => $isStructured ? 'structured' : 'legacy',
    'csrf' => Auth::csrfToken(),
    'apiUrl' => SOI_ADMIN_URL . '/editor-api.php',
    'uploadUrl' => SOI_ADMIN_URL . '/editor-upload.php',
    'editUrl' => SOI_ADMIN_URL . '/' . ($entity === 'post' ? 'posts.php' : 'pages.php') . '?action=edit&id=',
    'autosave' => true,
    'initialData' => $editorJsData,
];
?>
<form method="POST" id="kc-editor" class="kc-editor" data-mode="<?= $isStructured ? 'structured' : 'legacy' ?>">
  <?= Auth::csrfField() ?>
  <input type="hidden" name="_action" value="save">
  <input type="hidden" name="id" id="kc-doc-id" value="<?= (int) ($record['id'] ?? 0) ?>">
  <input type="hidden" name="mode" id="kc-editor-mode" value="<?= $isStructured ? 'structured' : 'legacy' ?>">
  <input type="hidden" name="expected_updated_at" id="kc-updated-at" value="<?= esc($record['updated_at'] ?? '') ?>">
  <input type="hidden" name="document" id="kc-document-json" value="<?= esc(Document::encode($initialDocument)) ?>">

  <div class="kc-editor-bar">
    <a href="<?= esc($editorListUrl) ?>" class="btn btn-ghost btn-sm">← Back</a>
    <input class="kc-editor-title" id="kc-doc-title" name="title" value="<?= esc($record['title'] ?? '') ?>" placeholder="Document title" required>
    <span class="kc-editor-status" id="kc-save-status"><?= $isNew ? 'Not saved' : 'Saved' ?></span>
    <?php if ($isStructured): ?>
    <button type="button" class="btn btn-ghost btn-sm" id="kc-preview-btn">Preview</button>
    <?php endif; ?>
    <select class="form-select" id="kc-doc-status" name="status" style="width:auto;min-width:8rem;">
      <?php foreach (['draft' => 'Draft', 'published' => 'Published', 'private' => 'Private'] as $value => $label): ?>
      <option value="<?= $value ?>" <?= ($record['status'] ?? 'draft') === $value ? 'selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm" id="kc-save-btn"><?= $isNew ? 'Save' : 'Save' ?></button>
    <?php if (!empty($record['slug'])): ?>
    <a class="btn btn-ghost btn-sm" href="<?= SOI_HOME_URL ?>/<?= esc($record['slug']) ?>" target="_blank" rel="noopener">View</a>
    <?php endif; ?>
  </div>

  <div class="kc-editor-body">
    <aside class="kc-editor-outline">
      <div class="kc-pane-title">Outline</div>
      <div id="kc-outline-list" class="kc-outline-list">
        <div class="kc-outline-empty"><?= $isStructured ? 'Headings will appear here.' : 'Legacy documents do not expose a structured outline.' ?></div>
      </div>
    </aside>

    <main class="kc-editor-canvas">
      <?php if (!$isStructured): ?>
      <div class="kc-legacy-banner">
        <p>This document still uses the previous HTML authoring format. It continues to render as before. Open it in the structured editor to keep the existing HTML as a preserved legacy block — nothing is mass-converted.</p>
        <button type="submit" class="btn btn-primary btn-sm" name="_action" value="convert_structured" id="kc-convert-structured">Open in structured editor</button>
      </div>
      <label class="form-label" for="kc-legacy-html">HTML content</label>
      <textarea class="form-textarea kc-legacy-html" id="kc-legacy-html" name="legacy_html" rows="18"><?= htmlspecialchars($record['content'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></textarea>
      <?php else: ?>
      <div class="kc-editor-canvas-inner">
        <div id="kc-editorjs"></div>
        <div class="kc-add-block">
          <button type="button" class="btn btn-ghost" id="kc-add-block">+ Add block</button>
        </div>
      </div>
      <?php endif; ?>
    </main>

    <aside class="kc-editor-props">
      <div class="kc-pane-title">Properties</div>
      <div class="kc-prop-group">
        <label class="form-label" for="kc-doc-slug">Slug</label>
        <input class="form-input" id="kc-doc-slug" name="slug" value="<?= esc($record['slug'] ?? '') ?>" placeholder="auto-generated-from-title">
      </div>
      <?php if (!empty($editorShowExcerpt)): ?>
      <div class="kc-prop-group">
        <label class="form-label" for="kc-doc-excerpt">Excerpt</label>
        <textarea class="form-textarea" id="kc-doc-excerpt" name="excerpt" rows="3"><?= esc($record['excerpt'] ?? '') ?></textarea>
      </div>
      <?php endif; ?>
      <div class="kc-prop-group">
        <label class="form-label">Meta title</label>
        <input class="form-input" name="meta_title" value="<?= esc($record['meta_title'] ?? '') ?>">
      </div>
      <div class="kc-prop-group">
        <label class="form-label">Meta description</label>
        <textarea class="form-textarea" name="meta_desc" rows="3"><?= esc($record['meta_desc'] ?? '') ?></textarea>
      </div>
      <?php if (!empty($editorCategories)): ?>
      <div class="kc-prop-group">
        <div class="form-label">Categories</div>
        <?php foreach ($editorCategories as $cat): ?>
        <label style="display:flex;align-items:center;gap:0.45rem;font-size:0.8rem;margin:0.25rem 0;">
          <input type="checkbox" name="categories[]" value="<?= (int) $cat['id'] ?>" <?= in_array($cat['id'], $editorAssignedCats ?? []) ? 'checked' : '' ?>>
          <?= esc($cat['name']) ?>
        </label>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($isStructured): ?>
      <div class="kc-pane-title" style="margin-top:1rem;">Block</div>
      <div class="kc-selected-meta" id="kc-selected-meta">No block selected</div>
      <div class="kc-block-actions">
        <button type="button" class="btn btn-ghost btn-sm" id="kc-block-up">Move up</button>
        <button type="button" class="btn btn-ghost btn-sm" id="kc-block-down">Move down</button>
        <button type="button" class="btn btn-ghost btn-sm" id="kc-block-dup">Duplicate</button>
        <button type="button" class="btn btn-ghost btn-sm" id="kc-block-del">Delete</button>
      </div>
      <div class="kc-block-actions" style="margin-top:0.45rem;">
        <button type="button" class="btn btn-ghost btn-sm" data-convert="paragraph">To paragraph</button>
        <button type="button" class="btn btn-ghost btn-sm" data-convert="header">To heading</button>
        <button type="button" class="btn btn-ghost btn-sm" data-convert="quote">To quote</button>
        <button type="button" class="btn btn-ghost btn-sm" data-convert="code">To code</button>
      </div>
      <?php endif; ?>
    </aside>
  </div>
</form>

<div class="kc-modal" id="kc-preview-modal" hidden>
  <div class="kc-modal-card">
    <div class="kc-modal-head">
      <strong>Preview</strong>
      <button type="button" class="btn btn-ghost btn-sm" data-kc-close>Close</button>
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
      <button type="button" class="btn btn-ghost btn-sm" data-kc-close>Close</button>
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
<script src="' . $asset('editor/kc-editor.js') . '"></script>
';
} else {
    $extraScripts = ($extraScripts ?? '') . '
<script src="' . $asset('editor/kc-editor.js') . '"></script>
';
}
?>
