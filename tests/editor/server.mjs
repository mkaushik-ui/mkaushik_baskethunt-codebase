import http from 'http';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const rootDir = path.resolve(__dirname, '../../');

const PORT = 9333;

function escapeHtml(str) {
  return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
const mimeTypes = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.svg': 'image/svg+xml'
};

const documentsStore = {
  'app-registration': {
    id: 1,
    title: 'App Registration',
    slug: 'app-registration',
    status: 'published',
    category: 'Developer Docs',
    updated_at: 'Jul 3, 2026',
    read_time: '1 min read',
    view_url: '/docs/app-registration',
    blocks: [
      { type: 'paragraph', data: { text: 'Before a CMS can upload, an administrator must register the application in Files Service Admin.' } },
      { type: 'header', data: { level: 2, text: 'Manual Registration Steps' } },
      { type: 'list', data: { style: 'ordered', items: [
        'Sign in to Files Service Admin',
        'Open Registered Apps',
        'Complete Manual Register Application: App ID (slug), name, allowed domains',
        'Click Register App',
        'Click + Generate Key on the new row',
        'Copy Client ID and Client Secret immediately into CMS server config'
      ] } },
      { type: 'header', data: { level: 2, text: 'Configuration Fields' } },
      { type: 'table', data: { withHeadings: true, content: [
        ['FIELD', 'NOTES'],
        ['App ID', 'Lowercase slug, e.g. my-app'],
        ['Allowed Domains', 'Comma-separated hostnames without scheme'],
        ['Status', 'Disabled apps reject all API calls'],
        ['Permissions', 'Upload, download flags per credential']
      ] } }
    ]
  }
};
let lastActiveSlug = 'app-registration';

const server = http.createServer((req, res) => {
  const parsed = new URL(req.url, `http://localhost:${PORT}`);
  let pathname = parsed.pathname;

  if (pathname === '/admin/editor-api.php') {
    if (req.method === 'POST') {
      let bodyStr = '';
      req.on('data', chunk => { bodyStr += chunk; });
      req.on('end', () => {
        try {
          const payload = JSON.parse(bodyStr);
          if (payload && (payload._action === 'save' || payload._action === 'autosave')) {
            const slug = (payload.slug || '').trim() || 'app-registration';
            const title = (payload.title || '').trim() || 'Untitled Document';
            const status = payload.status || 'draft';
            const blocks = payload.document ? (payload.document.blocks || []) : [];

            const docEntry = {
              id: payload.id || 1,
              title: title,
              slug: slug,
              status: status,
              category: 'Developer Docs',
              updated_at: new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }),
              read_time: Math.max(1, Math.ceil(JSON.stringify(blocks).length / 400)) + ' min read',
              view_url: '/docs/' + slug,
              blocks: blocks
            };

            documentsStore[slug] = docEntry;
            lastActiveSlug = slug;

            res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
            res.end(JSON.stringify({
              ok: true,
              id: docEntry.id,
              slug: docEntry.slug,
              status: docEntry.status,
              updated_at: new Date().toISOString(),
              view_url: docEntry.view_url
            }));
            return;
          }
        } catch (e) {}

        res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
        res.end(JSON.stringify({ ok: true, id: 1, updated_at: new Date().toISOString() }));
      });
      return;
    }

    res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
    res.end(JSON.stringify({
      ok: true,
      id: 1,
      status: 'draft',
      updated_at: new Date().toISOString(),
      html: '<div class="preview-output"><h3>1.1.0 Enterprise Authoring Suite Demo</h3><p>Document preview rendered.</p></div>',
      revisions: [
        { id: 1, revision_num: 1, user_name: 'Admin User', created_at: new Date().toISOString(), content_bytes: 1024 }
      ]
    }));
    return;
  }

  if (pathname === '/api/search') {
    const q = (parsed.searchParams.get('q') || '').toLowerCase().trim();
    const spaceFilter = parsed.searchParams.get('space') || 'all';

    const results = [];
    Object.values(documentsStore).forEach(doc => {
      if (spaceFilter !== 'all' && doc.space_slug && doc.space_slug !== spaceFilter) return;
      const title = (doc.title || '').toLowerCase();
      const slug = (doc.slug || '').toLowerCase();
      const text = (doc.extracted_text || JSON.stringify(doc.blocks || [])).toLowerCase();

      if (q === '' || title.includes(q) || slug.includes(q) || text.includes(q)) {
        results.push({
          id: doc.id,
          title: doc.title,
          slug: doc.slug,
          space_name: doc.space_name || 'Files Service Docs',
          category: doc.category || 'Developer Docs',
          view_url: doc.view_url || ('/docs/' + doc.slug),
          snippet: `Matching documentation post in ${doc.category || 'Developer Docs'} section.`
        });
      }
    });

    res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
    res.end(JSON.stringify({ ok: true, results }));
    return;
  }

  if (pathname === '/' || pathname === '/test-editor' || pathname === '/editor') {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(getTestHtml());
    return;
  }

  if (pathname.startsWith('/docs/')) {
    const slug = pathname.replace(/^\/docs\//, '').replace(/\/$/, '') || 'app-registration';
    const doc = documentsStore[slug] || documentsStore[lastActiveSlug] || documentsStore['app-registration'];
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(getReaderHtml(pathname.includes('/space/'), doc));
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
  <title>SOI Knowledge Center 1.1.0 Enterprise Authoring Workspace</title>
  <link rel="stylesheet" href="/admin/assets/editor/kc-editor.css?v=1.1.0">
</head>
<body class="kc-authoring kc-authoring-fullscreen">
  <aside class="sidebar" id="admin-sidebar" style="display:none"></aside>
  <div class="topbar" style="display:none"></div>
  <div class="admin-layout">
    <div class="admin-content">
      <div class="page-content page-content--fluid page-content--editor">
        <form method="POST" id="kc-editor" class="kc-editor kc-workspace" data-mode="structured" data-left="open" data-right="open" data-left-tab="components" data-right-tab="doc" data-page-mode="continuous" data-editor-state="loading">
          <input type="hidden" name="id" id="kc-doc-id" value="1">
          <input type="hidden" name="mode" id="kc-editor-mode" value="structured">
          <input type="hidden" name="expected_updated_at" id="kc-updated-at" value="">
          <input type="hidden" name="document" id="kc-document-json" value="">

          <!-- Top Header Navigation -->
          <header class="kc-workspace-head">
            <div class="kc-head-left">
              <a class="kc-head-btn-exit" href="/docs/app-registration" title="Return to Knowledge Center document list">←</a>
              <div class="kc-head-title-stack">
                <strong class="kc-head-title">Edit Doc Article</strong>
                <a class="kc-doc-slug-path" id="kc-head-slug-path" href="/docs/oauth" target="_blank" title="View Public Reader Article">https://mkaushik.test.soi.co.in/n3i2fo3</a>
              </div>
            </div>
            <div class="kc-head-right">
              <span class="kc-save-chip" id="kc-save-status" data-state="saved">Saved</span>
              <span class="kc-card-badge is-published" id="kc-card-status-badge">PUBLISHED</span>
              <button type="button" class="kc-head-btn" id="kc-palette-trigger" title="Command Palette (Ctrl/Cmd+K)">⌨ Palette</button>
              <button type="button" class="kc-head-btn" id="kc-toggle-left" aria-pressed="true" title="Open Components Panel">Components</button>
              <button type="button" class="kc-head-btn" id="kc-preview-btn" title="Preview formatted document">Preview</button>
              <a class="kc-head-btn" id="kc-live-link" href="/docs/oauth" target="_blank" rel="noopener" title="Open Public Reader View in new tab">Open live ↗</a>
              <button type="button" class="kc-head-btn" id="kc-toggle-right" aria-pressed="true" title="Toggle Inspector & Settings">Settings</button>
              <button type="submit" class="kc-head-btn kc-head-btn-primary" id="kc-save-btn" title="Save document (Ctrl/Cmd+S)">Save article</button>
            </div>
          </header>

          <!-- Document Title Section -->
          <div class="kc-title-wrapper">
            <label for="kc-doc-title" class="kc-title-label">DOCUMENT TITLE</label>
            <input class="kc-title-input" id="kc-doc-title" name="title" value="OAuth" required autocomplete="off">
          </div>

          <!-- Enterprise Ribbon Toolbar -->
          <div class="kc-ribbon" id="kc-ribbon">
            <div class="kc-ribbon-tabs" role="tablist">
              <button type="button" class="kc-ribbon-tab is-active" data-ribbon-tab="home">Home</button>
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
              <div class="kc-ribbon-group" title="Insert Elements">
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
              <div class="kc-ribbon-group" title="Table Operations">
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

          <div class="kc-workspace-body">
            <!-- Left Pane: Components -->
            <aside class="kc-left-pane" id="kc-left-pane" aria-label="Component Library">
              <div class="kc-pane-tabs">
                <button type="button" class="kc-pane-tab is-active" data-left-tab="components">Components</button>
                <button type="button" class="kc-pane-tab" data-left-tab="navigator">Navigator</button>
                <button type="button" class="kc-pane-tab" data-left-tab="patterns">Patterns</button>
                <button type="button" class="kc-pane-tab" data-left-tab="reusable">Reusable</button>
                <button type="button" class="kc-pane-close" id="kc-close-left">✕</button>
              </div>
              <div class="kc-left-panel" data-left-panel="components">
                <div class="kc-search-wrap">
                  <input class="kc-search" id="kc-component-search" type="search" placeholder="Search components (e.g. notice, steps, api)..." autocomplete="off">
                </div>
                <div id="kc-component-list" class="kc-component-list">
                  <div class="kc-component-group" data-group="frequent">
                    <h4>★ FREQUENTLY USED</h4>
                    <div class="kc-component-grid">
                      <button type="button" class="kc-component" draggable="true" data-component-id="image">
                        <span class="kc-component-ico">🖼</span>
                        <strong>Image</strong>
                        <span class="kc-component-desc">Picture from media library</span>
                      </button>
                      <button type="button" class="kc-component" draggable="true" data-component-id="faq">
                        <span class="kc-component-ico">?</span>
                        <strong>FAQ</strong>
                        <span class="kc-component-desc">Questions and answers with...</span>
                      </button>
                      <button type="button" class="kc-component" draggable="true" data-component-id="code">
                        <span class="kc-component-ico">&lt;/&gt;</span>
                        <strong>Code</strong>
                        <span class="kc-component-desc">Technical snippet</span>
                      </button>
                      <button type="button" class="kc-component" draggable="true" data-component-id="list-ordered">
                        <span class="kc-component-ico">1</span>
                        <strong>Numbered list</strong>
                        <span class="kc-component-desc">Ordered list</span>
                      </button>
                      <button type="button" class="kc-component" draggable="true" data-component-id="apiEndpoint">
                        <span class="kc-component-ico">⚡</span>
                        <strong>API Endpoint</strong>
                        <span class="kc-component-desc">Structured API specification wit...</span>
                      </button>
                      <button type="button" class="kc-component" draggable="true" data-component-id="definitionList">
                        <span class="kc-component-ico">≡</span>
                        <strong>Definition List</strong>
                        <span class="kc-component-desc">Terms and definitions...</span>
                      </button>
                    </div>
                  </div>
                  <div class="kc-component-group" data-group="basic">
                    <h4>BASIC</h4>
                    <div class="kc-component-grid">
                      <button type="button" class="kc-component" draggable="true" data-component-id="paragraph">
                        <span class="kc-component-ico">P</span>
                        <strong>Paragraph</strong>
                        <span class="kc-component-desc">Body text</span>
                      </button>
                      <button type="button" class="kc-component" draggable="true" data-component-id="heading">
                        <span class="kc-component-ico">H</span>
                        <strong>Heading</strong>
                        <span class="kc-component-desc">Section title</span>
                      </button>
                      <button type="button" class="kc-component" draggable="true" data-component-id="list-unordered">
                        <span class="kc-component-ico">•</span>
                        <strong>Bulleted list</strong>
                        <span class="kc-component-desc">Unordered list</span>
                      </button>
                      <button type="button" class="kc-component" draggable="true" data-component-id="list-ordered">
                        <span class="kc-component-ico">1</span>
                        <strong>Numbered list</strong>
                        <span class="kc-component-desc">Ordered list</span>
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </aside>
            <div class="kc-resizer kc-resizer-left" id="kc-resizer-left"></div>

            <!-- Main Canvas Area -->
            <main class="kc-canvas" id="kc-canvas">
              <div class="kc-doc-stage" id="kc-doc-stage">
                <div class="kc-doc-surface kc-page-sheet" id="kc-doc-surface">
                  <div id="kc-editorjs"></div>
                  <div id="kc-editor-error" class="kc-editor-error-panel" hidden role="alert"></div>
                </div>
              </div>
            </main>
            <div class="kc-resizer kc-resizer-right" id="kc-resizer-right"></div>

            <!-- Right Pane: Inspector -->
            <aside class="kc-right-pane" id="kc-right-pane" aria-label="Document settings and Inspector">
              <div class="kc-pane-tabs">
                <button type="button" class="kc-pane-tab is-active" data-right-tab="doc">Document</button>
                <button type="button" class="kc-pane-tab" data-right-tab="block">Block</button>
                <button type="button" class="kc-pane-tab" data-right-tab="layout">Layout</button>
                <button type="button" class="kc-pane-close" id="kc-close-right">✕</button>
              </div>
              <div class="kc-right-panel" data-right-panel="doc">
                <div class="kc-settings-card">
                  <div class="kc-card-head">
                    <strong>Publish</strong>
                    <span class="kc-card-badge is-published">PUBLISHED</span>
                  </div>
                  <div class="kc-field">
                    <label for="kc-doc-status">Document status</label>
                    <select class="kc-tool-select" id="kc-doc-status" name="status">
                      <option value="published" selected>Published</option>
                      <option value="draft">Draft</option>
                    </select>
                  </div>
                  <div class="kc-field">
                    <label for="kc-doc-slug">URL slug</label>
                    <input class="kc-card-input" id="kc-doc-slug" name="slug" value="oauth">
                    <span class="kc-slug-preview-link">/docs/oauth</span>
                  </div>
                  <div class="kc-cat-box">
                    <div class="kc-cat-badge-row">
                      <span class="kc-cat-badge">Category</span>
                      <strong class="kc-cat-name">Developer Docs</strong>
                    </div>
                    <p class="kc-cat-note">Category assignment remains automatic and cannot be changed from this workspace.</p>
                  </div>
                  <div class="kc-card-actions">
                    <button type="submit" class="kc-card-btn kc-card-btn-primary" id="kc-card-save-btn">Save article</button>
                    <button type="button" class="kc-card-btn kc-card-btn-secondary" id="kc-card-preview-btn">Preview article</button>
                    <a class="kc-card-btn kc-card-btn-secondary" href="#" target="_blank">View public article</a>
                  </div>
                </div>
              </div>
            </aside>
          </div>

          <!-- Footer Status Bar -->
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
              <span id="kc-status-bar-state">Published</span>
              <span class="kc-status-sep">·</span>
              <span id="kc-status-bar-save">Saved</span>
            </div>
          </footer>
        </form>

        <script type="application/json" id="kc-editor-config">{
          "apiUrl": "/admin/editor-api.php",
          "csrf": "demo-csrf-token",
          "entity": "page",
          "id": 1,
          "mode": "structured",
          "catalog": [
            { "id": "paragraph", "editorType": "paragraph", "label": "Paragraph", "group": "basic", "description": "Body text" },
            { "id": "heading", "editorType": "header", "label": "Heading", "group": "basic", "description": "Section title" },
            { "id": "list-unordered", "editorType": "list", "label": "Bulleted list", "group": "basic", "description": "Unordered list" },
            { "id": "list-ordered", "editorType": "list", "label": "Numbered list", "group": "basic", "description": "Ordered list" },
            { "id": "quote", "editorType": "quote", "label": "Quote", "group": "basic", "description": "Pull quote" },
            { "id": "divider", "editorType": "delimiter", "label": "Divider", "group": "basic", "description": "Horizontal rule" }
          ],
          "document": {
            "schemaVersion": 1,
            "blocks": [
              { "id": "b1", "type": "paragraph", "data": { "text": "" } }
            ]
          }
        }</script>
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
        <script src="/admin/assets/editor/blocks/steps.js?v=1.1.0"></script>
        <script src="/admin/assets/editor/blocks/accordion.js?v=1.1.0"></script>
        <script src="/admin/assets/editor/blocks/divider.js?v=1.1.0"></script>
        <script src="/admin/assets/editor/runtime/bootstrap.js?v=1.1.0"></script>
        <script src="/admin/assets/editor/ui/inspector-shell.js?v=1.1.0"></script>
        <script src="/admin/assets/editor/layout/drag-drop.js?v=1.1.0"></script>
        <script src="/admin/assets/editor/kc-editor.js?v=1.1.0"></script>
      </div>
    </div>
  </div>
</body>
</html>`;
}
