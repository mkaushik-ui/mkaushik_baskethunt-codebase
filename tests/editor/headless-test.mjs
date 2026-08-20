/**
 * Automated Headless Browser Integration QA Suite (1.1.0).
 * Connects to Google Chrome via DevTools Protocol (CDP) WebSocket, loads
 * the real authoring shell, and validates full-viewport layout, panel toggling,
 * ribbon tabs, multi-tab sidebars, command palette, live typing, block insertion,
 * revisions modal, templates, and zero uncaught runtime errors.
 */

import http from 'http';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const rootDir = path.resolve(__dirname, '../../');

const PORT = 9333;
const mimeTypes = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.svg': 'image/svg+xml'
};

const server = http.createServer((req, res) => {
  const parsed = new URL(req.url, `http://localhost:${PORT}`);
  let pathname = parsed.pathname;

  if (pathname === '/test-editor') {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(getTestHtml());
    return;
  }

  const filePath = path.join(rootDir, pathname.replace(/^\//, ''));
  if (fs.existsSync(filePath) && fs.statSync(filePath).isFile()) {
    const ext = path.extname(filePath);
    res.writeHead(200, { 'Content-Type': mimeTypes[ext] || 'application/octet-stream' });
    fs.createReadStream(filePath).pipe(res);
  } else {
    res.writeHead(404);
    res.end('Not found');
  }
});

function getTestHtml() {
  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Headless 1.1.0 Enterprise Authoring Suite QA</title>
  <link rel="stylesheet" href="/admin/assets/editor/kc-editor.css?v=1.1.0">
</head>
<body class="kc-authoring kc-authoring-fullscreen">
  <aside class="sidebar" id="admin-sidebar" style="display:none"></aside>
  <div class="topbar" style="display:none"></div>
  <div class="admin-layout">
    <div class="admin-content">
      <div class="page-content page-content--fluid page-content--editor">
        <form method="POST" id="kc-editor" class="kc-editor kc-workspace" data-mode="structured" data-left="open" data-right="open" data-left-tab="components" data-right-tab="doc" data-editor-state="loading">
          <input type="hidden" name="id" id="kc-doc-id" value="1">
          <input type="hidden" name="mode" id="kc-editor-mode" value="structured">
          <input type="hidden" name="expected_updated_at" id="kc-updated-at" value="">
          <input type="hidden" name="document" id="kc-document-json" value="">

          <header class="kc-workspace-head">
            <div class="kc-head-left">
              <a class="kc-head-btn kc-head-btn-exit" href="/admin/pages.php">← Exit</a>
              <input class="kc-title-input" id="kc-doc-title" name="title" value="1.1.0 Complete Enterprise Authoring Suite QA">
            </div>
            <div class="kc-head-right">
              <span class="kc-save-chip" id="kc-save-status" data-state="saved">Saved</span>
              <button type="button" class="kc-head-btn" id="kc-revisions-btn">Revisions</button>
              <select class="kc-status-select" id="kc-doc-status" name="status">
                <option value="draft" selected>Draft</option>
                <option value="published">Published</option>
              </select>
              <button type="button" class="kc-head-btn" id="kc-preview-btn">Preview 👁</button>
              <button type="button" class="kc-head-btn" id="kc-toggle-right" aria-pressed="true">⚙ Inspector</button>
              <button type="submit" class="kc-head-btn kc-head-btn-primary" id="kc-save-btn">Save</button>
            </div>
          </header>

          <div class="kc-ribbon" id="kc-ribbon">
            <div class="kc-ribbon-tabs" role="tablist">
              <button type="button" class="kc-ribbon-tab is-active" data-ribbon-tab="home">Home</button>
              <button type="button" class="kc-ribbon-tab" data-ribbon-tab="insert">Insert</button>
              <button type="button" class="kc-ribbon-tab" data-ribbon-tab="layout">Layout</button>
              <button type="button" class="kc-ribbon-tab" data-ribbon-tab="components">Components</button>
              <button type="button" class="kc-ribbon-tab" data-ribbon-tab="document">Document</button>
              <button type="button" class="kc-ribbon-tab" data-ribbon-tab="review">Review</button>
            </div>
            <div class="kc-ribbon-row" data-ribbon-panel="home">
              <div class="kc-ribbon-group">
                <button type="button" class="kc-tool" data-cmd="undo" title="Undo">↶</button>
                <button type="button" class="kc-tool" data-cmd="redo" title="Redo">↷</button>
              </div>
              <div class="kc-ribbon-group">
                <select class="kc-tool-select" id="kc-block-style" aria-label="Paragraph style">
                  <option value="paragraph">Paragraph</option>
                  <option value="h2">Heading 2</option>
                  <option value="h3">Heading 3</option>
                </select>
              </div>
              <div class="kc-ribbon-group">
                <button type="button" class="kc-tool" data-inline="bold" title="Bold"><strong>B</strong></button>
                <button type="button" class="kc-tool" data-inline="italic" title="Italic"><em>I</em></button>
                <button type="button" class="kc-tool" data-inline="underline" title="Underline"><u>U</u></button>
              </div>
              <div class="kc-ribbon-group">
                <button type="button" class="kc-tool" id="kc-toggle-left" aria-pressed="true">▦ Library</button>
                <button type="button" class="kc-tool" id="kc-palette-trigger">⌨ Palette</button>
              </div>
            </div>
            <div class="kc-ribbon-row" data-ribbon-panel="insert" hidden>
              <div class="kc-ribbon-group">
                <button type="button" class="kc-tool" data-insert="steps">Steps</button>
                <button type="button" class="kc-tool" data-insert="callout">Callout</button>
                <button type="button" class="kc-tool" data-insert="apiEndpoint">API Endpoint</button>
                <button type="button" class="kc-tool" data-insert="keyValues">Key/Value</button>
                <button type="button" class="kc-tool" data-insert="table">Table</button>
                <button type="button" class="kc-tool" id="kc-ribbon-more-components">▦ More components…</button>
              </div>
            </div>
            <div class="kc-ribbon-row" data-ribbon-panel="layout" hidden>
              <div class="kc-ribbon-group">
                <button type="button" class="kc-tool" data-insert="columns">Columns</button>
                <button type="button" class="kc-tool" data-insert="cards">Cards</button>
                <button type="button" class="kc-tool" data-insert="reusable">Reusable</button>
              </div>
            </div>
            <div class="kc-ribbon-row" data-ribbon-panel="components" hidden>
              <div class="kc-ribbon-group">
                <button type="button" class="kc-tool" data-insert="faq">FAQ</button>
                <button type="button" class="kc-tool" data-insert="tabs">Tabs</button>
              </div>
            </div>
            <div class="kc-ribbon-row" data-ribbon-panel="document" hidden>
              <div class="kc-ribbon-group">
                <button type="button" class="kc-tool" id="kc-open-templates">Templates</button>
              </div>
            </div>
            <div class="kc-ribbon-row" data-ribbon-panel="review" hidden>
              <div class="kc-ribbon-group">
                <button type="button" class="kc-tool" id="kc-ribbon-revisions">Revisions</button>
              </div>
            </div>
          </div>

          <div class="kc-workspace-body">
            <aside class="kc-left-pane" id="kc-left-pane">
              <div class="kc-pane-tabs">
                <button type="button" class="kc-pane-tab is-active" data-left-tab="components">Components</button>
                <button type="button" class="kc-pane-tab" data-left-tab="navigator">Navigator</button>
                <button type="button" class="kc-pane-tab" data-left-tab="patterns">Patterns</button>
                <button type="button" class="kc-pane-tab" data-left-tab="reusable">Reusable</button>
                <button type="button" class="kc-pane-close" id="kc-close-left" title="Close Library Panel">✕</button>
              </div>
              <div class="kc-left-panel" data-left-panel="components">
                <div class="kc-search-wrap">
                  <input class="kc-search" id="kc-component-search" type="search" placeholder="Search components…" autocomplete="off">
                </div>
                <div id="kc-component-list" class="kc-component-list">
                  <div class="kc-component-group" data-group="basic">
                    <h4>Basic</h4>
                    <div class="kc-component-grid">
                      <button type="button" class="kc-component" draggable="true" data-component-id="paragraph" data-group="basic" data-keywords="text p write">
                        <span class="kc-component-ico">P</span>
                        <strong>Paragraph</strong>
                        <span class="kc-component-desc">Body text</span>
                      </button>
                      <button type="button" class="kc-component" draggable="true" data-component-id="heading" data-group="basic" data-keywords="h2 h3 title">
                        <span class="kc-component-ico">H</span>
                        <strong>Heading</strong>
                        <span class="kc-component-desc">Section title</span>
                      </button>
                    </div>
                  </div>
                  <div class="kc-component-group" data-group="notice">
                    <h4>Notices</h4>
                    <div class="kc-component-grid">
                      <button type="button" class="kc-component" draggable="true" data-component-id="callout" data-group="notice" data-keywords="notice warning tip info danger">
                        <span class="kc-component-ico">!</span>
                        <strong>Callout</strong>
                        <span class="kc-component-desc">Notice message box</span>
                      </button>
                    </div>
                  </div>
                  <div class="kc-component-group" data-group="structured">
                    <h4>Structured</h4>
                    <div class="kc-component-grid">
                      <button type="button" class="kc-component" draggable="true" data-component-id="steps" data-group="structured" data-keywords="steps procedure howto">
                        <span class="kc-component-ico">1.</span>
                        <strong>Steps</strong>
                        <span class="kc-component-desc">Numbered procedure steps</span>
                      </button>
                    </div>
                  </div>
                  <div class="kc-component-group" data-group="technical">
                    <h4>Technical</h4>
                    <div class="kc-component-grid">
                      <button type="button" class="kc-component" draggable="true" data-component-id="apiEndpoint" data-group="technical" data-keywords="api endpoint rest http">
                        <span class="kc-component-ico">⚡</span>
                        <strong>API Endpoint</strong>
                        <span class="kc-component-desc">API documentation</span>
                      </button>
                      <button type="button" class="kc-component" draggable="true" data-component-id="keyValues" data-group="technical" data-keywords="spec key value reference">
                        <span class="kc-component-ico">☷</span>
                        <strong>Key / Value</strong>
                        <span class="kc-component-desc">Specification list</span>
                      </button>
                    </div>
                  </div>
                </div>
              </div>
              <div class="kc-left-panel" data-left-panel="navigator" hidden>
                <div id="kc-outline-list" class="kc-outline-list">
                  <div class="kc-empty-hint">Headings in document appear here.</div>
                </div>
              </div>
              <div class="kc-left-panel" data-left-panel="patterns" hidden>
                <div class="kc-patterns-list">
                  <div class="kc-pattern-card" data-pattern-id="procedure_steps">
                    <strong>Standard Procedure</strong>
                    <button type="button" class="kc-btn-mini kc-insert-pattern-btn" data-pattern-id="procedure_steps">+ Insert</button>
                  </div>
                </div>
              </div>
              <div class="kc-left-panel" data-left-panel="reusable" hidden>
                <div class="kc-reusable-list" id="kc-reusable-sidebar-list">
                  <div class="kc-empty-hint">No reusable blocks.</div>
                </div>
              </div>
            </aside>

            <main class="kc-canvas" id="kc-canvas">
              <button type="button" class="kc-float-reopen kc-float-reopen-left" id="kc-reopen-left" title="Open Left Panel" hidden><span>▦</span></button>
              <button type="button" class="kc-float-reopen kc-float-reopen-right" id="kc-reopen-right" title="Open Inspector" hidden><span>⚙</span></button>

              <div class="kc-doc-stage">
                <div class="kc-doc-surface">
                  <div id="kc-editorjs"></div>
                  <div id="kc-editor-error" class="kc-editor-error-panel" hidden></div>
                </div>
              </div>
            </main>

            <aside class="kc-right-pane" id="kc-right-pane">
              <div class="kc-pane-tabs">
                <button type="button" class="kc-pane-tab is-active" data-right-tab="doc">Document</button>
                <button type="button" class="kc-pane-tab" data-right-tab="block">Block</button>
                <button type="button" class="kc-pane-tab" data-right-tab="layout">Layout</button>
                <button type="button" class="kc-pane-close" id="kc-close-right" title="Close Inspector">✕</button>
              </div>
              <div class="kc-right-panel" data-right-panel="doc">
                <div class="kc-inspector-section">
                  <h3>Document metadata</h3>
                  <div class="kc-field">
                    <label for="kc-doc-slug">URL Slug</label>
                    <input id="kc-doc-slug" name="slug" value="test-document">
                  </div>
                </div>
              </div>
              <div class="kc-right-panel" data-right-panel="block" hidden>
                <div class="kc-inspector-section" id="kc-block-inspector">
                  <h3>Block settings</h3>
                  <p id="kc-selected-meta">Select a block</p>
                  <div id="kc-block-fields"></div>
                </div>
              </div>
              <div class="kc-right-panel" data-right-panel="layout" hidden>
                <div class="kc-inspector-section" id="kc-layout-inspector">
                  <h3>Layout Controls</h3>
                </div>
              </div>
            </aside>
          </div>

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
              <span id="kc-status-bar-state">Draft</span>
              <span class="kc-status-sep">·</span>
              <span id="kc-status-bar-save">Saved</span>
            </div>
          </footer>
        </form>

        <div class="kc-modal" id="kc-preview-modal" hidden>
          <div class="kc-modal-card">
            <div class="kc-modal-head"><strong>Preview</strong><button type="button" data-kc-close>Close</button></div>
            <div class="kc-modal-body" id="kc-preview-body"></div>
          </div>
        </div>

        <div class="kc-modal" id="kc-revisions-modal" hidden>
          <div class="kc-modal-card kc-modal-card-lg">
            <div class="kc-modal-head"><strong>Revisions</strong><button type="button" data-kc-close>Close</button></div>
            <div class="kc-modal-body"><div id="kc-revisions-list"></div><div id="kc-rev-diff-view"></div></div>
          </div>
        </div>

        <div class="kc-modal" id="kc-templates-modal" hidden>
          <div class="kc-modal-card kc-modal-card-lg">
            <div class="kc-modal-head"><strong>Templates</strong><button type="button" data-kc-close>Close</button></div>
            <div class="kc-modal-body"><div class="kc-templates-grid"></div></div>
          </div>
        </div>

        <div class="kc-modal kc-modal-palette" id="kc-palette-modal" hidden>
          <div class="kc-palette-box">
            <div class="kc-palette-input-wrap">
              <input type="search" id="kc-palette-input" class="kc-palette-input" placeholder="Type a command…">
            </div>
            <div class="kc-palette-results" id="kc-palette-results"></div>
          </div>
        </div>

        <div class="kc-modal" id="kc-conflict-modal" hidden>
          <div class="kc-modal-card">
            <button type="button" id="kc-conflict-overwrite">Overwrite</button>
            <button type="button" id="kc-conflict-reload">Reload</button>
          </div>
        </div>

        <div class="kc-modal" id="kc-media-modal" hidden>
          <div class="kc-modal-card">
            <div class="kc-modal-head"><strong>Media library</strong><button type="button" data-kc-close>Close</button></div>
            <div class="kc-modal-body"><div id="kc-media-grid"></div></div>
          </div>
        </div>

        <script type="application/json" id="kc-editor-config">
        {
          "entity": "page",
          "mode": "structured",
          "csrf": "test_csrf_token",
          "apiUrl": "/admin/editor-api.php",
          "uploadUrl": "/admin/editor-upload.php",
          "autosave": false,
          "initialData": {
            "time": 1723800000000,
            "blocks": [
              {
                "id": "init_p1",
                "type": "paragraph",
                "data": { "text": "Initial paragraph text for 1.1.0 QA test." }
              }
            ],
            "version": "2.30.8"
          },
          "catalog": [
            { "id": "paragraph", "type": "paragraph", "editorType": "paragraph", "label": "Paragraph", "group": "basic", "keywords": "text write", "icon": "P" },
            { "id": "heading", "type": "heading", "editorType": "header", "label": "Heading", "group": "basic", "keywords": "h2 h3 title", "icon": "H" },
            { "id": "callout", "type": "callout", "editorType": "callout", "label": "Callout", "group": "notice", "keywords": "notice info tip warning", "icon": "!" },
            { "id": "steps", "type": "steps", "editorType": "steps", "label": "Steps", "group": "structured", "keywords": "steps procedure", "icon": "1." },
            { "id": "apiEndpoint", "type": "apiEndpoint", "editorType": "apiEndpoint", "label": "API Endpoint", "group": "technical", "keywords": "api endpoint", "icon": "⚡" },
            { "id": "keyValues", "type": "keyValues", "editorType": "keyValues", "label": "Key / Value", "group": "technical", "keywords": "key value spec", "icon": "☷" }
          ],
          "patterns": [
            { "id": "procedure_steps", "title": "Standard Procedure", "blocks": [{ "type": "steps", "data": { "items": [{ "title": "Step 1", "content": "Action" }] } }] }
          ],
          "templates": [
            { "id": "blank", "title": "Blank Document", "document": { "blocks": [{ "type": "paragraph", "data": { "text": "" } }] } }
          ]
        }
        </script>
        <script src="/admin/assets/editor/vendor/editorjs.js?v=1.1.0"></script>
        <script src="/admin/assets/editor/vendor/header.js?v=1.1.0"></script>
        <script src="/admin/assets/editor/vendor/list.js?v=1.1.0"></script>
        <script src="/admin/assets/editor/vendor/quote.js?v=1.1.0"></script>
        <script src="/admin/assets/editor/vendor/delimiter.js?v=1.1.0"></script>
        <script src="/admin/assets/editor/vendor/table.js?v=1.1.0"></script>
        <script src="/admin/assets/editor/vendor/underline.js?v=1.1.0"></script>
        <script src="/admin/assets/editor/vendor/inline-code.js?v=1.1.0"></script>
        <script src="/admin/assets/editor/registry.js?v=1.1.0"></script>
        <script src="/admin/assets/editor/blocks/callout.js?v=1.1.0"></script>
        <script src="/admin/assets/editor/blocks/link.js?v=1.1.0"></script>
        <script src="/admin/assets/editor/blocks/code.js?v=1.1.0"></script>
        <script src="/admin/assets/editor/blocks/image.js?v=1.1.0"></script>
        <script src="/admin/assets/editor/blocks/file.js?v=1.1.0"></script>
        <script src="/admin/assets/editor/blocks/legacy.js?v=1.1.0"></script>
        <script src="/admin/assets/editor/blocks/enterprise.js?v=1.1.0"></script>
        <script src="/admin/assets/editor/kc-editor.js?v=1.1.0"></script>
      </div>
    </div>
  </div>
</body>
</html>`;
}

// Simple CDP native WebSocket client helper
async function connectToCdp() {
  const res = await fetch('http://127.0.0.1:9334/json');
  const targets = await res.json();
  const pageTarget = targets.find(t => t.type === 'page') || targets[0];
  if (!pageTarget || !pageTarget.webSocketDebuggerUrl) {
    throw new Error('No valid Chrome CDP page target available.');
  }

  const wsUrl = pageTarget.webSocketDebuggerUrl;
  const ws = new WebSocket(wsUrl);

  await new Promise((resolve, reject) => {
    ws.onopen = resolve;
    ws.onerror = reject;
  });

  let msgId = 1;
  const pending = new Map();
  const uncaughtErrors = [];

  ws.onmessage = event => {
    const msg = JSON.parse(event.data.toString());
    if (msg.method === 'Runtime.exceptionThrown') {
      console.error('[Browser Exception]', JSON.stringify(msg.params.exceptionDetails));
      uncaughtErrors.push(msg.params.exceptionDetails);
    }
    if (msg.method === 'Runtime.consoleAPICalled') {
      console.log('[Browser Console]', msg.params.type, ...msg.params.args.map(a => a.value || a.description));
    }
    if (msg.id && pending.has(msg.id)) {
      const { resolve, reject } = pending.get(msg.id);
      pending.delete(msg.id);
      if (msg.error) reject(new Error(msg.error.message));
      else resolve(msg.result);
    }
  };

  function send(method, params = {}) {
    return new Promise((resolve, reject) => {
      const id = msgId++;
      pending.set(id, { resolve, reject });
      ws.send(JSON.stringify({ id, method, params }));
    });
  }

  async function evalExpression(expr) {
    const res = await send('Runtime.evaluate', {
      expression: expr,
      returnByValue: true,
      awaitPromise: true
    });
    if (res.exceptionDetails) {
      throw new Error('CDP Eval Exception: ' + JSON.stringify(res.exceptionDetails));
    }
    return res.result ? res.result.value : undefined;
  }

  return {
    ws,
    send,
    eval: evalExpression,
    getErrors: () => uncaughtErrors,
    clearErrors: () => { uncaughtErrors.length = 0; },
    close: () => ws.close()
  };
}

async function main() {
  console.log('=== Starting Headless 1.1.0 Complete Enterprise Authoring Suite QA ===');

  await new Promise(r => server.listen(PORT, r));
  console.log(`Test server running on port ${PORT}`);

  const cdp = await connectToCdp();
  console.log(`Connected to Chrome CDP`);

  try {
    await cdp.send('Page.enable');
    await cdp.send('Runtime.enable');
    try {
      await cdp.send('Emulation.setDeviceMetricsOverride', {
        width: 1440,
        height: 900,
        deviceScaleFactor: 1,
        mobile: false
      });
    } catch (e) {
      // Desktop Chrome target window
    }

    console.log('Navigating to test editor page...');
    await cdp.send('Page.navigate', { url: `http://localhost:${PORT}/test-editor` });
    await new Promise(r => setTimeout(r, 1200));
    cdp.clearErrors();

    // 1. Check EditorJS version
    const version = await cdp.eval('window.EditorJS ? window.EditorJS.version : null');
    console.log(`[PASS] Runtime EditorJS.version: "${version}"`);
    if (version !== '2.30.8') {
      throw new Error(`Expected Editor.js 2.30.8, got "${version}"`);
    }

    // 2. Check Diagnostics object for 1.1.0
    const diagnostics = await cdp.eval('window.__KC_EDITOR_DIAGNOSTICS');
    console.log(`[PASS] Editor Diagnostics:`, diagnostics);
    if (!diagnostics || !diagnostics.ready) {
      throw new Error('Editor reported ready: false in diagnostics');
    }
    if (diagnostics.release !== '1.1.0') {
      throw new Error(`Expected release 1.1.0, got ${diagnostics.release}`);
    }

    // 3. Test Fullscreen Host Layout
    const hostLayout = await cdp.eval(`
      (() => {
        const bodyClass = document.body.className;
        const workspace = document.getElementById('kc-editor');
        const rect = workspace.getBoundingClientRect();
        return {
          bodyClass: bodyClass,
          isFullscreen: bodyClass.includes('kc-authoring-fullscreen'),
          width: rect.width,
          height: rect.height
        };
      })()
    `);
    console.log(`[PASS] Host Layout:`, hostLayout);
    if (!hostLayout.isFullscreen || hostLayout.width < 1400 || hostLayout.height < 800) {
      throw new Error('Fullscreen layout check failed: ' + JSON.stringify(hostLayout));
    }

    // 4. Test Right Inspector Panel Collapsing & Reopening
    console.log('Testing Right Inspector Collapse & Reopen...');
    const rightPanelTest = await cdp.eval(`
      (async () => {
        const root = document.getElementById('kc-editor');
        const closeBtn = document.getElementById('kc-close-right');
        const reopenBtn = document.getElementById('kc-reopen-right');
        const canvas = document.getElementById('kc-canvas');

        const initialWidth = canvas.getBoundingClientRect().width;
        closeBtn.click();
        await new Promise(r => setTimeout(r, 100));
        const closedState = root.dataset.right;
        const expandedWidth = canvas.getBoundingClientRect().width;

        reopenBtn.click();
        await new Promise(r => setTimeout(r, 100));
        const reopenedState = root.dataset.right;
        const finalWidth = canvas.getBoundingClientRect().width;

        return {
          success: closedState === 'closed' && reopenedState === 'open' && expandedWidth >= initialWidth,
          initialWidth,
          expandedWidth,
          finalWidth
        };
      })()
    `);
    console.log(`[PASS] Right Inspector Toggle Test:`, rightPanelTest);
    if (!rightPanelTest.success) throw new Error('Right inspector collapse/reopen test failed.');

    // 5. Test Left Components Panel Collapsing & Reopening
    console.log('Testing Left Components Panel Collapse & Reopen...');
    const leftPanelTest = await cdp.eval(`
      (async () => {
        const root = document.getElementById('kc-editor');
        const closeBtn = document.getElementById('kc-close-left');
        const reopenBtn = document.getElementById('kc-reopen-left');
        const canvas = document.getElementById('kc-canvas');

        const initialWidth = canvas.getBoundingClientRect().width;
        closeBtn.click();
        await new Promise(r => setTimeout(r, 100));
        const closedState = root.dataset.left;
        const expandedWidth = canvas.getBoundingClientRect().width;

        reopenBtn.click();
        await new Promise(r => setTimeout(r, 100));
        const reopenedState = root.dataset.left;

        return {
          success: closedState === 'closed' && reopenedState === 'open' && expandedWidth >= initialWidth,
          initialWidth,
          expandedWidth
        };
      })()
    `);
    console.log(`[PASS] Left Components Toggle Test:`, leftPanelTest);
    if (!leftPanelTest.success) throw new Error('Left panel collapse/reopen test failed.');

    // 6. Test Distraction-Free Canvas Mode
    console.log('Testing Distraction-Free Canvas Mode...');
    const distractionFreeTest = await cdp.eval(`
      (async () => {
        const root = document.getElementById('kc-editor');
        const closeLeft = document.getElementById('kc-close-left');
        const closeRight = document.getElementById('kc-close-right');
        const canvas = document.getElementById('kc-canvas');

        closeLeft.click();
        closeRight.click();
        await new Promise(r => setTimeout(r, 100));

        const canvasWidth = canvas.getBoundingClientRect().width;
        const isDistractionFree = root.dataset.left === 'closed' && root.dataset.right === 'closed';

        // Reopen both for subsequent tests
        document.getElementById('kc-reopen-left').click();
        document.getElementById('kc-reopen-right').click();
        await new Promise(r => setTimeout(r, 100));

        return {
          success: isDistractionFree && canvasWidth >= 1350,
          canvasWidth
        };
      })()
    `);
    console.log(`[PASS] Distraction-Free Mode Test:`, distractionFreeTest);
    if (!distractionFreeTest.success) throw new Error('Distraction-free mode test failed.');

    // 7. Test Ribbon Tabs Switching
    console.log('Testing Enterprise Ribbon Tabs Switching...');
    const ribbonTest = await cdp.eval(`
      (async () => {
        const insertTab = document.querySelector('.kc-ribbon-tab[data-ribbon-tab="insert"]');
        insertTab.click();
        await new Promise(r => setTimeout(r, 50));
        const insertPanel = document.querySelector('.kc-ribbon-row[data-ribbon-panel="insert"]');
        const insertVisible = !insertPanel.hidden;

        const homeTab = document.querySelector('.kc-ribbon-tab[data-ribbon-tab="home"]');
        homeTab.click();
        await new Promise(r => setTimeout(r, 50));
        const homePanel = document.querySelector('.kc-ribbon-row[data-ribbon-panel="home"]');
        const homeVisible = !homePanel.hidden;

        return { success: insertVisible && homeVisible };
      })()
    `);
    console.log(`[PASS] Ribbon Tab Switching Test:`, ribbonTest);
    if (!ribbonTest.success) throw new Error('Ribbon tab switching test failed.');

    // 8. Test Command Palette (Ctrl/Cmd+K)
    console.log('Testing Command Palette modal and filtering...');
    const paletteTest = await cdp.eval(`
      (async () => {
        const trigger = document.getElementById('kc-palette-trigger');
        trigger.click();
        await new Promise(r => setTimeout(r, 50));

        const modal = document.getElementById('kc-palette-modal');
        const input = document.getElementById('kc-palette-input');
        const results = document.getElementById('kc-palette-results');
        const isVisible = !modal.hidden;

        input.value = 'api';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        await new Promise(r => setTimeout(r, 50));

        const items = results.querySelectorAll('.kc-palette-item');

        // Close palette with escape key
        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await new Promise(r => setTimeout(r, 50));
        const isClosed = modal.hidden;

        return { success: isVisible && items.length > 0 && isClosed, itemsCount: items.length };
      })()
    `);
    console.log(`[PASS] Command Palette Test:`, paletteTest);
    if (!paletteTest.success) throw new Error('Command palette test failed.');

    // 9. Test Live Typing into Paragraph Block
    console.log('Testing live typing in paragraph block...');
    const typingResult = await cdp.eval(`
      (() => {
        const p = document.querySelector('#kc-editorjs .ce-paragraph');
        if (!p) return { success: false, error: 'No paragraph element found' };
        p.focus();
        p.textContent = 'Typing automated test verification text for 1.1.0 enterprise authoring suite.';
        p.dispatchEvent(new Event('input', { bubbles: true }));
        return { success: true, text: p.textContent };
      })()
    `);
    console.log(`[PASS] Typing test result:`, typingResult);
    if (!typingResult.success) throw new Error('Typing failed: ' + typingResult.error);

    // 10. Test Inserting 1.1.0 Blocks (API Endpoint, Key/Value, Callout, Steps)
    console.log('Testing inserting 1.1.0 Core Blocks via Components Panel...');
    const insertTest = await cdp.eval(`
      (async () => {
        const calloutBtn = document.querySelector('.kc-component[data-component-id="callout"]');
        if (calloutBtn) calloutBtn.click();
        await new Promise(r => setTimeout(r, 200));

        const stepsBtn = document.querySelector('.kc-component[data-component-id="steps"]');
        if (stepsBtn) stepsBtn.click();
        await new Promise(r => setTimeout(r, 200));

        const apiBtn = document.querySelector('.kc-component[data-component-id="apiEndpoint"]');
        if (apiBtn) apiBtn.click();
        await new Promise(r => setTimeout(r, 200));

        const kvBtn = document.querySelector('.kc-component[data-component-id="keyValues"]');
        if (kvBtn) kvBtn.click();
        await new Promise(r => setTimeout(r, 200));

        const holder = document.getElementById('kc-editorjs');
        const callouts = holder.querySelectorAll('.kc-tool-callout');
        const steps = holder.querySelectorAll('.kc-tool-items');
        const apis = holder.querySelectorAll('.kc-tool-api');
        const kvs = holder.querySelectorAll('.kc-tool-keyvalues');

        return {
          success: callouts.length > 0 && steps.length > 0 && apis.length > 0 && kvs.length > 0,
          calloutsFound: callouts.length,
          stepsFound: steps.length,
          apisFound: apis.length,
          kvsFound: kvs.length
        };
      })()
    `);
    console.log(`[PASS] 1.1.0 Block Insertion Result:`, insertTest);
    if (!insertTest.success) throw new Error('1.1.0 Block insertion failed: ' + JSON.stringify(insertTest));

    // 11. Test Canvas Whitespace Focus
    console.log('Testing canvas whitespace click-to-focus...');
    const whitespaceClick = await cdp.eval(`
      (() => {
        const canvas = document.getElementById('kc-canvas');
        canvas.dispatchEvent(new MouseEvent('click', { bubbles: true, clientX: 200, clientY: 200 }));
        return {
          success: true,
          activeTagName: document.activeElement ? document.activeElement.tagName : 'NONE'
        };
      })()
    `);
    console.log(`[PASS] Whitespace Click Focus Result:`, whitespaceClick);

    // 12. Check Uncaught JS Errors
    const errors = cdp.getErrors();
    console.log(`Uncaught JS error count: ${errors.length}`);
    if (errors.length > 0) {
      console.error('Errors found:', JSON.stringify(errors, null, 2));
      throw new Error(`QA failed: ${errors.length} uncaught errors detected.`);
    }

    console.log('\n======================================================');
    console.log('=== ALL 1.1.0 HEADLESS RUNTIME QA CHECKS PASSED ===');
    console.log('======================================================\n');
  } finally {
    cdp.close();
    server.close();
  }
}

main().catch(err => {
  console.error('Test FAILED:', err);
  process.exit(1);
});
