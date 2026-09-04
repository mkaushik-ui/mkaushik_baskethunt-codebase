# SOI Knowledge Center — Editor UX Task Plan & Architecture Blueprint

> **DOCUMENT STATUS**: AUTHORITATIVE PLANNING ARTIFACT  
> **SCOPE**: SOI Knowledge Center Editor Workspace (1.1.0 Authoring Suite)  
> **EXECUTION DISCIPLINE**: PLANNING ONLY — ZERO CODE MODIFICATIONS DURING THIS RUN

---

## 1. Executive Purpose & Governance Rules

This document establishes a strictly isolated, small-task decomposition for all remaining Editor UX fixes and functional enhancements. It isolates the authoring runtime, defines strict file boundaries, preserves canonical architecture, and prevents regressions caused by multi-concern refactoring.

### Core Hard Rules:
1. **Editor Scope Isolation**: All tasks are strictly restricted to the Editor Workspace runtime. Non-editor domains (Reader Shell, Knowledge Space, Public Frontend, SAML/Central Auth, Unrelated Admin pages) must not be modified.
2. **Single Source of Truth**:
   $$\text{Block Definition} \longrightarrow \text{Block Registry} \longrightarrow \text{Editor.js Instance} \longrightarrow \text{Editor API} \longrightarrow \text{ContentService} \longrightarrow \text{ContentStore} \longrightarrow \text{Database}$$
   No duplicate state, shadow instances, or parallel save paths.
3. **Validation Standard**: Every task requires **Static Validation + Runtime CDP/Browser Verification + Regression Verification**. Syntax checking alone does not constitute task completion.
4. **Independent Execution**: Tasks designed as independent must be executable and verifiable without requiring adjacent incomplete tasks.

---

## 2. Workspace Boundary Audit & Runtime Tracing

### 2.1 The Editor Authority Boundary (`/test-editor` and `/admin/posts.php?action=edit`)
The actual runtime for the Editor consists strictly of:
- **Host Markup & Template**:
  - `admin/partials/structured-editor.php`
  - `admin/pages.php` (when `$action === 'edit'|'new'`)
  - `admin/posts.php` (when `$action === 'edit'|'new'`)
- **Client JS/CSS Runtime** (`admin/assets/editor/`):
  - Core controller: `kc-editor.js`, `kc-editor.css`
  - Block registry & tool mapping: `registry.js`
  - Runtime event hub: `runtime/bootstrap.js`
  - UI shells: `ui/block-toolbar.js`, `ui/inspector-shell.js`, `ui/navigator-shell.js`
  - Layout & Drag/Drop engine: `layout/drag-drop.js`, `layout/drag-drop.css`, `layout/layout-inspector.js`
  - Block definitions: `blocks/` (`accordion.js`, `cards.js`, `checklist.js`, `code.js`, `columns.js`, `divider.js`, `enterprise.js`, `favorites.js`, `file.js`, `group.js`, `heading.js`, `image.js`, `legacy.js`, `link.js`, `list.js`, `paragraph.js`, `quote.js`, `steps.js`)
  - Vendor runtimes: `vendor/` (`editorjs.js`, `header.js`, `list.js`, `quote.js`, `delimiter.js`, `table.js`, `underline.js`, `inline-code.js`)
  - Pagination runtime: `kc-pagination.js`
- **Backend Content Engine**:
  - `core/Content/` (BlockRegistry, ContentService, ContentStore, Document, DocumentRenderer, EditorApi, EditorSchema, Html, Transform, Blocks, Patterns, Reusable, Services, Templates, Validation)
  - `core/Services/` (Autosave, Concurrency, ErrorTracker, Recovery, Revisions)
  - `core/Legacy/` (Legacy converter & format detection)
- **Test Harness**:
  - `tests/editor/server.mjs`, `tests/editor/run.php`, `tests/editor/contract-tests.php`, `tests/editor/migration-tests.php`, `tests/editor/perf-tests.php`, `tests/editor/security-tests.php`

### 2.2 Non-Editor Domains (Explicitly Excluded)
The following files and directories belong to non-editor features and **MUST NOT BE TOUCHED** during Editor UX tasks:
- **Reader Shell & Public Frontend**: `assets/kc-reader.css`, `assets/kc-reader.js`, `templates/reader-shell.php`, `templates/`
- **Knowledge Space Model**: `core/Spaces/`
- **Global Search & Internal Link Engine**: `core/Search/`, `core/Links/`, `core/Relationships/`
- **Central Auth & SAML**: `core/SoiCentralAuth.php`, `saml/`, `soi-central/`, `admin/soicentral.php`
- **Unrelated Admin Modules**: `admin/analytics.php`, `admin/api-keys.php`, `admin/categories.php`, `admin/comments.php`, `admin/login.php`, `admin/logout.php`, `admin/media.php`, `admin/menus.php`, `admin/options.php`, `admin/plugins.php`, `admin/profile.php`, `admin/tags.php`, `admin/update.php`, `admin/users.php`
- **Unrelated Core Services**: `core/Accounts.php`, `core/AdminSearch.php`, `core/Auth.php`, `core/Blog.php`, `core/Mailer.php`, `core/Maintenance.php`, `core/MediaHandler.php`, `core/MediaStorage.php`, `core/Plugin.php`, `core/Security.php`, `core/Update.php`

---

## 3. Non-Editor Safety Archive

To guarantee absolute boundary protection and reference integrity, all non-editor code has been archived into a standalone safety ZIP:

- **Archive Location**: `ZIPS/non-editor-code-backup.zip` (123,411 bytes)
- **Original Source Files**: Preserved in place without deletion or relocation.
- **Included Packages**:
  1. `assets/kc-reader.css`, `assets/kc-reader.js`, `templates/`
  2. `core/Spaces/`, `core/Search/`, `core/Links/`, `core/Relationships/`
  3. `core/SoiCentralAuth.php`, `saml/`, `soi-central/`
  4. Non-editor admin controllers (`admin/analytics.php`, `admin/api-keys.php`, `admin/categories.php`, etc.)
  5. Non-editor backend services (`core/Accounts.php`, `core/AdminSearch.php`, `core/Auth.php`, etc.)

---

## 4. Problem Classification & Task Decomposition

Every reported issue is classified into a small, isolated task with zero cross-contamination.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                             TASK CLASSIFICATION                             │
├───────────────────────┬───────────────────────────┬─────────────────────────┤
│ Classification        │ Task IDs                  │ Primary Focus Area      │
├───────────────────────┼───────────────────────────┼─────────────────────────┤
│ A. Visual/UI          │ EUX-01, EUX-06, EUX-07    │ Styling, polish, icons  │
│ B. Interaction/State  │ EUX-02, EUX-11            │ Undo/redo, selection    │
│ C. Block Function     │ EUX-01, EUX-03, EUX-04    │ Tool classes, lifecyle  │
│ D. Layout Engine      │ EUX-05, EUX-08, EUX-12    │ DnD, columns, presets   │
│ E. Media/Upload       │ EUX-03, EUX-04            │ Image/File UX, payload  │
│ F. Ribbon Commands    │ EUX-10                    │ Inline formatting tools │
│ G. Inspector State    │ EUX-11                    │ Contextual selection    │
│ H. Typography/Format  │ EUX-09                    │ Line-height, base rules │
│ I. Integration/QA     │ EUX-13                    │ End-to-end regression   │
└───────────────────────┴───────────────────────────┴─────────────────────────┘
```

---

## 5. Detailed Task Briefings (EUX-01 through EUX-13)

### [EUX-01] Divider Rendering & Visible Line Parity(G P)
- **Category**: Visual/UI & Block Functionality
- **Phase**: Phase 1 (Independent Fixes) | **Priority**: High
- **Dependencies**: None | **Can Run Independently**: YES
- **Problem Statement**: The Divider component currently renders stars (`✶  ✶  ✶`) via `admin/assets/editor/blocks/divider.js` line 26 instead of an actual visible horizontal line separator, and CSS lacks explicit horizontal rule styling.
- **Investigation Scope**:
  - `admin/assets/editor/blocks/divider.js`: inspect `render()` output.
  - `core/Content/Blocks/DividerBlock.php`: inspect server-side HTML renderer (`<hr class="kc-divider-line">`).
  - `admin/assets/editor/kc-editor.css`: check `.kc-divider-tool` and `.kc-divider-line` rules.
- **Implementation Scope**:
  - Replace star characters in `divider.js` with a clean `<div class="kc-divider-line"></div>` or `<hr>`.
  - Ensure CSS gives the divider a visible `#cbd5e1` 1px rule with consistent 16px vertical padding.
  - Ensure published/preview HTML rendering parity.
- **Files Involved**:
  - Primary: `admin/assets/editor/blocks/divider.js`, `admin/assets/editor/kc-editor.css`
  - Secondary: `core/Content/Blocks/DividerBlock.php`
  - Do NOT touch: Any other block JS files or `kc-editor.js`.

---

### [EUX-02] Undo / Redo Stack & Shortcut Reliability (A P)
- **Category**: Interaction / State Management
- **Phase**: Phase 1 (Independent Fixes) | **Priority**: Critical
- **Dependencies**: None | **Can Run Independently**: YES
- **Problem Statement**: Undo/Redo stack actions via ribbon buttons and keyboard shortcuts (`Ctrl+Z`, `Ctrl+Y`, `Cmd+Z`, `Cmd+Shift+Z`) fail to restore full block hierarchy cleanly, occasionally creating duplicate snapshot entries or losing block IDs.
- **Investigation Scope**:
  - `admin/assets/editor/kc-editor.js`: trace `undoStack`, `redoStack`, `captureSnapshot()`, `applySnapshot()`.
  - Check whether Editor.js internal history conflicts with custom snapshot capture.
  - Verify debounce mechanism on text input vs immediate snapshot on block insertion/deletion.
- **Implementation Scope**:
  - Ensure `captureSnapshot()` serializes complete block JSON cleanly and ignores duplicate consecutive states.
  - Wire `applySnapshot()` to re-render blocks via `instance.render(snapshot)` while preserving cursor focus.
  - Connect ribbon `[data-cmd="undo"]` and `[data-cmd="redo"]` click handlers directly to the stack controller.
  - Ensure `Ctrl+Z` and `Ctrl+Y` / `Cmd+Shift+Z` shortcuts trigger stack navigation without browser default conflict.
- **Files Involved**:
  - Primary: `admin/assets/editor/kc-editor.js`
  - Do NOT touch: `structured-editor.php` markup or block tool classes.

---

### [EUX-03] Image Component Upload Flow, Resize & Layout Alignment (P P)
- **Category**: Media / Upload & Block Functionality
- **Phase**: Phase 2 (Media Components) | **Priority**: High
- **Dependencies**: None | **Can Run Independently**: YES
- **Problem Statement**:
  1. Image component upload UI remains unnecessarily visible after an image has been uploaded.
  2. Uploaded image lacks interactive resizing handles/controls.
  3. Text wrapping/alignment around resized images does not behave properly.
- **Investigation Scope**:
  - `admin/assets/editor/blocks/image.js`: trace `render()`, file drop/picker handler, image preview state.
  - `admin/assets/editor/kc-editor.css`: inspect `.kc-image-wrapper`, `.kc-image-resizer`, alignment classes (`align-left`, `align-center`, `align-right`, `is-stretched`).
  - `core/Content/Blocks/Media/ImageBlock.php`: verify server-side HTML rendering for dimensions and alignment.
- **Implementation Scope**:
  - When image URL is populated, hide upload zone and display the image with subtle hover controls (Replace, Remove, Align, Resize).
  - Add width preset buttons (25%, 50%, 75%, 100%) or interactive corner resize drag.
  - Support alignment modes (`left` with float/wrap, `center`, `right` with float/wrap, `full`).
  - Ensure serialized data stores `{ url, caption, alt, width, alignment }` cleanly in document JSON.
- **Files Involved**:
  - Primary: `admin/assets/editor/blocks/image.js`, `admin/assets/editor/kc-editor.css`
  - Secondary: `core/Content/Blocks/Media/ImageBlock.php`
  - Do NOT touch: `file.js` or generic media upload admin handlers.

---

### [EUX-04] File Component End-to-End Upload & Download Lifecycle (P P)
- **Category**: Media / Upload & Block Functionality
- **Phase**: Phase 2 (Media Components) | **Priority**: High
- **Dependencies**: None | **Can Run Independently**: YES
- **Problem Statement**: The File attachment component fails during file selection/upload and does not render a downloadable file card with size, extension badge, and filename.
- **Investigation Scope**:
  - `admin/assets/editor/blocks/file.js`: inspect input file event listener, upload endpoint call, and error handling.
  - `tests/editor/server.mjs` & `core/Content/EditorApi.php`: inspect file upload multipart handler and response payload format `{ ok: true, file: { url, name, size, extension } }`.
  - `core/Content/Blocks/Media/FileBlock.php`: inspect HTML download card output.
- **Implementation Scope**:
  - Ensure file input trigger calls upload API cleanly with progress/loading state.
  - On upload success, render a file card showing icon by extension (`PDF`, `ZIP`, `DOCX`, `TXT`), filename, humanized size (e.g. `2.4 MB`), and download link.
  - Support file replacement and removal.
- **Files Involved**:
  - Primary: `admin/assets/editor/blocks/file.js`, `admin/assets/editor/kc-editor.css`
  - Secondary: `core/Content/Blocks/Media/FileBlock.php`, `core/Content/EditorApi.php`
  - Do NOT touch: `image.js` or unrelated media library endpoints.

---

### [EUX-05] Drag/Drop Component Label Leakage Bug (S P)
- **Category**: Layout / Editor Engine & Interaction
- **Phase**: Phase 3 (Layout & Drag/Drop) | **Priority**: Critical
- **Dependencies**: None | **Can Run Independently**: YES
- **Problem Statement**: Dragging a component from the left Components library into the canvas drops the palette card label text (e.g. `"Paragraph Basic text body block"`) into the document content alongside the new block.
- **Investigation Scope**:
  - `admin/assets/editor/layout/drag-drop.js`: trace `dragstart` event `e.dataTransfer.setData('text/plain', ...)` and `drop` event listener.
  - `admin/assets/editor/kc-editor.js`: trace `insertBlockByType()` call from drop handler.
  - Check browser native drop behavior on contenteditable elements where plain text is pasted if `e.preventDefault()` is not called at the exact target or custom drag data type (`application/x-kc-component`) is not used.
- **Implementation Scope**:
  - Switch `e.dataTransfer.setData` to use a custom MIME type: `application/x-kc-component-id`.
  - Stop propagating text drag payloads to contenteditable nodes.
  - Ensure the drop handler exclusively invokes `insertBlockByType(componentId, targetIndex)` with clean default data, inserting ZERO palette metadata into document JSON.
- **Files Involved**:
  - Primary: `admin/assets/editor/layout/drag-drop.js`, `admin/assets/editor/kc-editor.js`
  - Do NOT touch: Component tile HTML templates in `structured-editor.php`.

---

### [EUX-06] Structured Components UI Quality (Excluding Table) (G P)
- **Category**: Visual / UI & Document Typography
- **Phase**: Phase 4 (Component Visual Polish) | **Priority**: Medium
- **Dependencies**: None | **Can Run Independently**: YES
- **Explicit Exclusion**: **DO NOT MODIFY TABLE COMPONENT**.
- **Scope Components**: Callout, Quote, Steps, Accordion, FAQ, Checklist, Tabs.
- **Problem Statement**: Visual styling across structured components has inconsistent padding, borders, empty states, icon alignments, and theme colors.
- **Implementation Scope**:
  - Standardize container padding (`1rem 1.25rem`), border-radius (`8px`), and subtle neutral borders (`#e2e8f0`).
  - Refine Callout tone variants (`info`, `warning`, `success`, `danger`) with modern soft backgrounds and distinct left accent borders.
  - Polish Steps numbering badge pills (`1`, `2`, `3`), connector lines, and step title typography.
  - Polish Accordion/FAQ expandable chevron animations, summary headers, and schema.org compliant output.
  - Polish Checklist checkbox checkmarks and strike-through states.
- **Files Involved**:
  - Primary: `admin/assets/editor/blocks/callout.js`, `admin/assets/editor/blocks/quote.js`, `admin/assets/editor/blocks/steps.js`, `admin/assets/editor/blocks/accordion.js`, `admin/assets/editor/blocks/checklist.js`, `admin/assets/editor/kc-editor.css`
  - Secondary: `core/Content/Blocks/Interactive/`, `core/Content/Blocks/Knowledge/`
  - STRICTLY FORBIDDEN: `vendor/table.js`, `core/Content/Blocks/TableBlock.php`.

---

### [EUX-07] Technical Components UI Quality (G P)
- **Category**: Visual / UI & Technical Presentation
- **Phase**: Phase 4 (Component Visual Polish) | **Priority**: Medium
- **Dependencies**: None | **Can Run Independently**: YES
- **Scope Components**: Code, CodeGroup, API Endpoint, KeyValues, Keyboard Key (Kbd), Definition List.
- **Problem Statement**: Technical blocks require visual refinement for dark-mode code editors, method badge contrast (`GET`, `POST`, `PUT`, `DELETE`), key/value tabular alignment, and semantic `<kbd>` chip styling.
- **Implementation Scope**:
  - Code Block: sleek dark background (`#0f172a`), language badge pill, line numbers, copy snippet button.
  - API Endpoint: pill tags for HTTP methods (`GET` #2563eb, `POST` #16a34a, `DELETE` #dc2626), monospace path font, parameter table.
  - KeyValues: clean 2-column key/value layout with subtle alternating row shading.
  - Kbd: Mac/PC keyboard key chip (`box-shadow: 0 2px 0 #cbd5e1; border: 1px solid #cbd5e1; border-radius: 4px; font-family: monospace`).
- **Files Involved**:
  - Primary: `admin/assets/editor/blocks/code.js`, `admin/assets/editor/blocks/enterprise.js`, `admin/assets/editor/kc-editor.css`
  - Secondary: `core/Content/Blocks/Technical/`
  - Do NOT touch: Table block or non-technical block tools.

---

### [EUX-08] Layout Components Functionality & UI (Excluding Reusable) (S P)
- **Category**: Layout / Editor Engine
- **Phase**: Phase 5 (Layout & Presets) | **Priority**: High
- **Dependencies**: None | **Can Run Independently**: YES
- **Explicit Exclusion**: **DO NOT MODIFY REUSABLE BLOCKS**.
- **Scope Components**: Columns, Grid, Group, Cards.
- **Problem Statement**:
  1. Columns layout switching (`50-50`, `33-67`, `67-33`, `33-33-33`) must resize side-by-side editable columns smoothly.
  2. Grid 2/3/4 column cards need reliable cell insertion, cell deletion, and responsive mobile stacking.
  3. Dragging blocks between layout containers must show clean drop-indicator highlights without losing block content.
- **Implementation Scope**:
  - Overhaul column width flex/grid rules in `kc-editor.css` for responsive desktop $\rightarrow$ tablet $\rightarrow$ mobile breakpoints.
  - Ensure column ratio selector in inspector updates data layout and DOM class dynamically.
  - Ensure Grid cell add/delete triggers canvas change and auto-saves clean cell array data.
  - Validate serialization to document JSON and restore on reload.
- **Files Involved**:
  - Primary: `admin/assets/editor/blocks/enterprise.js` (`KcColumns`, `KcGrid`, `KcCards`), `admin/assets/editor/kc-editor.css`
  - Secondary: `core/Content/Blocks/Layout/`
  - STRICTLY FORBIDDEN: `KcReusable`, `core/Reusable/`, `soi_kc_reusable_blocks`.

---

### [EUX-09] Document Line Spacing Typography (Default 1.15)
- **Category**: Document Typography / Formatting
- **Phase**: Phase 1 (Independent Fixes) | **Priority**: Medium
- **Dependencies**: None | **Can Run Independently**: YES
- **Problem Statement**: Document canvas text paragraphs, list items, and descriptions must maintain a clean, readable default line-height of `1.15` without ad-hoc overrides.
- **Investigation Scope**:
  - Check `admin/assets/editor/kc-editor.css` line-height rules on `.ce-paragraph`, `.ce-block__content`, `.kc-document-body`, `li`.
  - Check line-height in published/preview stylesheets (`assets/kc-reader.css`).
- **Implementation Scope**:
  - Set `line-height: 1.15` as the baseline token on `.codex-editor`, `.ce-paragraph`, `.cdx-list__item`.
  - Verify heading line-heights (`h1: 1.2`, `h2: 1.25`, `h3: 1.3`) remain proportionate.
  - Ensure published reader content maintains matching typography.
- **Files Involved**:
  - Primary: `admin/assets/editor/kc-editor.css`
  - Secondary: `assets/kc-reader.css`
  - Do NOT touch: JS block logic or database schema.

---

### [EUX-10] Ribbon HL (Highlight) & Adjacent Tool Command Correctness
- **Category**: Ribbon / Command System
- **Phase**: Phase 1 (Independent Fixes) | **Priority**: High
- **Dependencies**: None | **Can Run Independently**: YES
- **Problem Statement**: The Ribbon Highlight (`HL`) button (`data-inline="highlight"`) and the tool immediately to its left (`inlineCode` or `underline`) in the Home ribbon toolbar do not consistently toggle active inline formatting states on selected canvas text.
- **Investigation Scope**:
  - `admin/partials/structured-editor.php`: inspect ribbon button markup `<button class="kc-tool" data-inline="...">`.
  - `admin/assets/editor/kc-editor.js`: trace `bindRibbonTools()` and inline formatting command execution via Editor.js InlineToolbar API (`api.inlineToolbar`).
  - `admin/assets/editor/vendor/inline-code.js` and highlight inline tool logic.
- **Implementation Scope**:
  - Ensure clicking `HL` applies/removes `<mark class="cdx-marker">` on selected text and toggles button `.is-active` state.
  - Ensure the adjacent button (`</>` Inline Code) wraps selection in `<code class="inline-code">` and toggles `.is-active` state.
  - Ensure keyboard shortcuts (`Ctrl+Shift+H` for Highlight, `Ctrl+E` for Inline Code) trigger the same commands.
- **Files Involved**:
  - Primary: `admin/partials/structured-editor.php`, `admin/assets/editor/kc-editor.js`
  - Secondary: `admin/assets/editor/vendor/inline-code.js`
  - Do NOT touch: Ribbon tab markup or non-inline ribbon groups.

---

### [EUX-11] Contextual Block Inspector Synchronization (A P)
- **Category**: Inspector / Context State Management
- **Phase**: Phase 5 (Layout & Presets) | **Priority**: Critical
- **Dependencies**: Stable block selection events from `kc-editor.js`.
- **Problem Statement**: Clicking or focusing on any block on the canvas leaves the right-side Inspector panel stuck showing `"Select a block on the canvas to configure properties."` instead of displaying property fields for the active block.
- **Investigation Scope**:
  - `admin/assets/editor/ui/inspector-shell.js`: trace `setBlock(block)`, `render()`, and `renderBlockControls()`.
  - `admin/assets/editor/runtime/bootstrap.js`: trace `updateActiveBlock()` and `selectionChange` event dispatch.
  - `admin/assets/editor/kc-editor.js`: trace canvas click/focus listeners and ensure `window.KcEditorRuntime.emit('selectionChange', activeBlock)` is called when any `.ce-block` is focused.
- **Implementation Scope**:
  - Wire canvas block focus listener to emit active block index, block type, and block ID to `InspectorShell`.
  - In `inspector-shell.js`, render block-specific controls for the active block:
    - **Heading**: Level selector (H1-H6), anchor ID input.
    - **Callout**: Tone selector (`info`, `warning`, `success`, `danger`), title input.
    - **Image**: Caption, alt text, width, alignment selector.
    - **Columns**: Layout ratio selector (`50-50`, `33-67`, `33-33-33`).
    - **Code**: Language selector, line numbers toggle.
  - Changes in inspector inputs must immediately update the block on the canvas and mark document state as `dirty`.
- **Files Involved**:
  - Primary: `admin/assets/editor/ui/inspector-shell.js`, `admin/assets/editor/kc-editor.js`, `admin/assets/editor/runtime/bootstrap.js`
  - Secondary: `admin/partials/structured-editor.php`
  - Do NOT touch: Document metadata tab or Navigator shell logic.

---

### [EUX-12] Layout Presets (Standard, Wide, Full-width) Canvas Engine (S P)
- **Category**: Layout / Editor Engine
- **Phase**: Phase 5 (Layout & Presets) | **Priority**: High
- **Dependencies**: None | **Can Run Independently**: YES
- **Problem Statement**: Selecting different Document Layout Presets (e.g., `Standard`, `Wide`, `Full-width`) in the Layout tab of the right Inspector has no observable effect on canvas container width or published output.
- **Investigation Scope**:
  - `admin/assets/editor/layout/layout-inspector.js` or `inspector-shell.js`: check layout preset dropdown change handler.
  - `admin/assets/editor/kc-editor.css`: check CSS classes `.kc-layout-standard` (max-width: 840px), `.kc-layout-wide` (max-width: 1200px), `.kc-layout-full` (max-width: 100%).
  - `admin/assets/editor/kc-editor.js`: check serialization of `layout_preset` in document metadata and application to `#kc-editor-canvas` on load.
- **Implementation Scope**:
  - Define distinct canvas container width rules:
    - `.kc-layout-standard .codex-editor__redactor`: `max-width: 820px; margin: 0 auto;`
    - `.kc-layout-wide .codex-editor__redactor`: `max-width: 1140px; margin: 0 auto;`
    - `.kc-layout-full .codex-editor__redactor`: `max-width: 100%; padding: 0 2rem;`
  - Ensure changing preset in inspector immediately toggles canvas wrapper class and persists `layout_preset` into document JSON metadata.
  - Ensure published reader view applies matching layout constraint.
- **Files Involved**:
  - Primary: `admin/assets/editor/kc-editor.css`, `admin/assets/editor/ui/inspector-shell.js`, `admin/assets/editor/kc-editor.js`
  - Secondary: `assets/kc-reader.css`
  - Do NOT touch: Individual block tool implementations.

---

### [EUX-13] End-to-End Regression Verification & Live Acceptance Gate
- **Category**: Integration / Quality Assurance
- **Phase**: Phase 6 (Final Acceptance) | **Priority**: Critical
- **Dependencies**: Completion of EUX-01 through EUX-12.
- **Problem Statement**: Validate that all 12 UX fixes work seamlessly together with zero console errors, zero data loss on save/reload, clean migration of legacy documents, and 100% test pass rate.
- **Scope**:
  - Execute full PHP test suite: `php tests/editor/run.php`.
  - Validate browser CDP tests via test harness (`/test-editor`).
  - Verify complete lifecycle: Type $\rightarrow$ Insert Blocks $\rightarrow$ Drag/Drop $\rightarrow$ Undo/Redo $\rightarrow$ Inspect Block $\rightarrow$ Save $\rightarrow$ Reload $\rightarrow$ Live Reader View.
- **Files Involved**:
  - `tests/editor/run.php`, `tests/editor/server.mjs`, `tests/editor/contract-tests.php`

---

## 6. Task Dependency Graph & Sequencing

```
                            PHASE 0: WORKSPACE AUDIT & BOUNDARY
                                [ZIPS/non-editor-code-backup.zip]
                                               │
               ┌───────────────────────────────┼───────────────────────────────┐
               ▼                               ▼                               ▼
       PHASE 1: INDEPENDENT            PHASE 2: MEDIA                  PHASE 3: DRAG/DROP
      [EUX-01] Divider Line           [EUX-03] Image Component         [EUX-05] Label Leak Fix
      [EUX-02] Undo/Redo Stack        [EUX-04] File Component
      [EUX-09] Line Spacing 1.15
      [EUX-10] Ribbon HL / Inline
               │                               │                               │
               └───────────────────────┬───────┴───────────────────────────────┘
                                       ▼
                       PHASE 4: COMPONENT UI POLISH
                      [EUX-06] Structured UI (No Table)
                      [EUX-07] Technical UI
                                       │
                                       ▼
                       PHASE 5: LAYOUT & INSPECTOR
                      [EUX-08] Layout Components (No Reusable)
                      [EUX-11] Contextual Block Inspector
                      [EUX-12] Layout Presets (Std/Wide/Full)
                                       │
                                       ▼
                       PHASE 6: FINAL REGRESSION QA
                      [EUX-13] End-to-End Acceptance Gate
```

---

## 7. Execution Matrix: Independent vs Sequenced Tasks

### 7.1 Tasks That Can Be Done Independently (Parallelizable)
These tasks touch isolated files or have zero prerequisite state:
- **EUX-01** (Divider Line): Isolated to `blocks/divider.js` and CSS.
- **EUX-03** (Image Component): Isolated to `blocks/image.js`.
- **EUX-04** (File Component): Isolated to `blocks/file.js`.
- **EUX-05** (Drag/Drop Label Bug): Isolated to `layout/drag-drop.js`.
- **EUX-09** (Line Spacing 1.15): Isolated to CSS typography tokens.
- **EUX-10** (Ribbon HL / Inline Code): Isolated to inline tool bindings.

### 7.2 Tasks That Must Be Sequenced
- **EUX-11** (Block Inspector): Must run after block focus events in `kc-editor.js` and `runtime/bootstrap.js` are stable.
- **EUX-12** (Layout Presets): Must run after `kc-editor.css` redactor width constraints are defined.
- **EUX-13** (Final Acceptance Gate): Must run strictly as the final verification step after EUX-01 through EUX-12 are complete.

### 7.3 Shared-File Conflict & Coordination Matrix
The following table outlines all tasks that modify shared files and defines the exact conflict mitigation rule:

| Shared File | Tasks Modifying File | Specific Concern Per Task | Conflict Prevention Rule |
| :--- | :--- | :--- | :--- |
| `admin/assets/editor/kc-editor.js` | EUX-02, EUX-05, EUX-10, EUX-11, EUX-12 | EUX-02: Undo/Redo stack<br>EUX-05: Drop handler<br>EUX-10: Ribbon command routing<br>EUX-11: Block focus listener<br>EUX-12: Preset loader | Apply changes in discrete isolated blocks using `replace_file_content` targeting exact line ranges. |
| `admin/assets/editor/kc-editor.css` | EUX-01, EUX-03, EUX-06, EUX-07, EUX-08, EUX-09, EUX-12 | EUX-01: Divider line<br>EUX-03: Image resizer<br>EUX-06/07: Component styles<br>EUX-08: Column grids<br>EUX-09: Line-height 1.15<br>EUX-12: Redactor widths | Add CSS under dedicated, labeled section headers without overwriting existing utility classes. |
| `admin/assets/editor/blocks/enterprise.js` | EUX-07, EUX-08 | EUX-07: CodeGroup/API endpoint<br>EUX-08: Columns/Grid | Isolate edits to the specific class definitions (`KcColumns`, `KcGrid`, `KcApiEndpoint`). |
| `admin/assets/editor/ui/inspector-shell.js` | EUX-11, EUX-12 | EUX-11: Block inspector fields<br>EUX-12: Layout preset listener | Separate Document/Layout tab logic from Block tab property rendering. |

---

## 8. Complete Test & Acceptance Matrix

| Task ID | Test Name | Verification Procedure | Expected Result | Regression Risk |
| :---: | :--- | :--- | :--- | :--- |
| **EUX-01** | Divider Line Visibility | Insert Divider in editor; inspect preview & published view. | Clean `#cbd5e1` 1px horizontal rule renders with 16px vertical padding; zero star characters. | Low |
| **EUX-02** | Undo / Redo Stack | Type text, insert blocks, delete block, press `Ctrl+Z`, `Ctrl+Y`, click ribbon buttons. | Previous/next states restore accurately without duplicate states or lost block IDs. | Medium |
| **EUX-03** | Image Upload & Resize | Upload image, adjust width (50%/100%), change alignment. | Upload box hides post-upload; image resizes smoothly; text wraps according to alignment. | Medium |
| **EUX-04** | File Attachment | Pick a PDF/ZIP file, verify card, save, reload, click download. | Downloadable card displays extension badge, size, filename; download link valid. | Medium |
| **EUX-05** | Drag/Drop Content Purity | Drag Paragraph / Heading from palette into canvas. | Only empty editable block inserted; zero palette title/description text in document JSON. | High |
| **EUX-06** | Structured Component UI | Insert Callout, Steps, Accordion, FAQ, Checklist, Quote. | High-quality visual polish, consistent 8px radius, theme borders, clean empty states. | Low |
| **EUX-07** | Technical Component UI | Insert Code block, API Endpoint, KeyValues, Kbd. | Dark code background, distinct HTTP method pills, clean 2-col key/value alignment. | Low |
| **EUX-08** | Layout Columns & Grid | Insert Columns (50-50, 33-67), add/remove grid cells, resize viewport. | Side-by-side columns render smoothly; grid cells wrap on mobile viewports. | Medium |
| **EUX-09** | Line Spacing 1.15 | Inspect computed CSS on canvas paragraphs and lists. | Computed `line-height` equals `1.15` across all base document typography. | Low |
| **EUX-10** | Ribbon HL & Inline Code | Select text on canvas, click `HL` button, click `</>` button. | Selection highlighted with `<mark>` / wrapped in `<code>`; buttons reflect `.is-active`. | Low |
| **EUX-11** | Contextual Block Inspector | Click different blocks (Heading, Callout, Image, Columns). | Inspector transitions from Document tab to show live properties for the selected block. | High |
| **EUX-12** | Layout Presets | Change preset from Standard $\rightarrow$ Wide $\rightarrow$ Full-width. | Canvas redactor width adjusts immediately (820px $\rightarrow$ 1140px $\rightarrow$ 100%). | Low |
| **EUX-13** | Full Regression QA | Run automated test runner `php tests/editor/run.php`. | 252+ tests pass with 0 failures and 0 uncaught JavaScript errors in console. | Critical |

---

## 9. Final Milestone Acceptance Standard

Before locking the Editor UX milestone, the following gates must be met:
1. **Zero Uncaught Console Errors**: Browser devtools console must remain completely clean during typing, block insertion, drag/drop, inspector switching, saving, and reloading.
2. **Zero Data Loss**: Saving and reloading preserves all block types, nested column data, and image/file metadata.
3. **Canonical Render Parity**: Document appearance on `/test-editor` canvas matches the rendered HTML output on the public reader view.
4. **Automated Suite**: `php tests/editor/run.php` passes with 100% success rate.
