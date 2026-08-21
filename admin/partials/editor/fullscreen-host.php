<?php
declare(strict_types=1);

/**
 * Fullscreen Authoring Host View Template (Task T2 Skeleton).
 * Omit default CMS chrome server-side and render the dedicated editor workspace.
 *
 * @var array<string, mixed> $documentRecord
 * @var array<string, mixed> $editorConfig
 */
?>
<!-- Fullscreen Authoring Shell Container -->
<div id="kc-editor-workspace" class="kc-workspace kc-fullscreen" data-theme="default">
    <!-- Top Document Action Bar -->
    <header class="kc-top-bar" id="kc-top-bar">
        <div class="kc-top-left">
            <a href="/admin/pages.php" class="kc-btn kc-btn-exit" id="kc-btn-exit">← Exit</a>
            <input type="text" id="kc-doc-title" class="kc-title-input" placeholder="Untitled Document" value="<?= htmlspecialchars((string)($documentRecord['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
        </div>
        <div class="kc-top-right">
            <span class="kc-save-chip" id="kc-save-status" data-state="saved">Saved</span>
            <button type="button" class="kc-btn" id="kc-preview-btn">Preview 👁</button>
            <button type="button" class="kc-btn" id="kc-revisions-btn">Revisions</button>
            <select id="kc-doc-status" class="kc-select">
                <option value="draft">Draft</option>
                <option value="published">Published</option>
            </select>
            <button type="button" class="kc-btn kc-btn-primary" id="kc-save-btn">Save</button>
        </div>
    </header>

    <!-- Ribbon Tabs Host -->
    <div id="kc-ribbon-host" class="kc-ribbon-host"></div>

    <!-- Main Workspace Canvas & Sidebars -->
    <div class="kc-stage-container">
        <aside class="kc-sidebar kc-sidebar-left" id="kc-sidebar-left" data-state="open">
            <div id="kc-navigator-host"></div>
        </aside>

        <main class="kc-canvas-container" id="kc-canvas-container">
            <div id="kc-editorjs" class="kc-editorjs-canvas"></div>
        </main>

        <aside class="kc-sidebar kc-sidebar-right" id="kc-sidebar-right" data-state="open">
            <div id="kc-inspector-host"></div>
        </aside>
    </div>

    <!-- Bottom Status Bar -->
    <footer class="kc-status-bar" id="kc-status-bar">
        <div class="kc-status-left">
            <span id="kc-word-count">0 words</span>
            <span id="kc-char-count">0 characters</span>
        </div>
        <div class="kc-status-right">
            <span id="kc-revision-label">Revision #1</span>
            <span id="kc-active-block-path">Paragraph</span>
        </div>
    </footer>
</div>
