/**
 * Knowledge Center enterprise authoring workspace (1.0.8).
 * Editor.js is the document engine; this file owns chrome, readiness, interactions, and controls.
 */
(function () {
  'use strict';

  // Ensure fullscreen authoring shell classes are active immediately on document.body
  document.body.classList.add('kc-authoring');
  document.body.classList.add('kc-authoring-fullscreen');

  const root = document.getElementById('kc-editor');
  if (!root) return;

  const cfgNode = document.getElementById('kc-editor-config');
  if (!cfgNode) {
    showBootError('Editor configuration is missing from the page.');
    return;
  }

  let cfg;
  try {
    cfg = JSON.parse(cfgNode.textContent);
  } catch (err) {
    showBootError('Editor configuration JSON is invalid.');
    return;
  }

  const form = root;
  const titleInput = document.getElementById('kc-doc-title');
  const slugInput = document.getElementById('kc-doc-slug');
  const statusSelect = document.getElementById('kc-doc-status');
  const statusEl = document.getElementById('kc-save-status');
  const outlineEl = document.getElementById('kc-outline-list');
  const selectedMeta = document.getElementById('kc-selected-meta');
  const blockFields = document.getElementById('kc-block-fields');
  const previewModal = document.getElementById('kc-preview-modal');
  const previewBody = document.getElementById('kc-preview-body');
  const mediaModal = document.getElementById('kc-media-modal');
  const mediaGrid = document.getElementById('kc-media-grid');
  const mediaSearch = document.getElementById('kc-media-search');
  const documentField = document.getElementById('kc-document-json');
  const updatedField = document.getElementById('kc-updated-at');
  const idField = document.getElementById('kc-doc-id');
  const modeField = document.getElementById('kc-editor-mode');
  const componentList = document.getElementById('kc-component-list');
  const emptyState = document.getElementById('kc-empty-state');
  const wordCountEl = document.getElementById('kc-word-count');
  const charCountEl = document.getElementById('kc-char-count');
  const barState = document.getElementById('kc-status-bar-state');
  const barSave = document.getElementById('kc-status-bar-save');
  const liveLink = document.getElementById('kc-live-link');
  const holder = document.getElementById('kc-editorjs');
  const errorBanner = document.getElementById('kc-editor-error');
  const canvasEl = document.querySelector('.kc-canvas');
  const stageEl = document.querySelector('.kc-doc-stage');
  const surfaceEl = document.querySelector('.kc-doc-surface');

  let editor = null;
  let editorReady = false;
  let dirty = false;
  let saving = false;
  let saveTimer = null;
  let mediaCallback = null;
  let mediaKind = 'image';
  let lastSelectedIndex = 0;
  let lastSnapshot = null;
  let chromeEl = null;
  let slashMenu = null;
  let slashIndex = 0;
  let slashMatches = [];
  let history = [];
  let historyIndex = -1;
  let historyLock = false;
  let suppressChange = false;
  let builtToolsResult = null;
  const runtimeErrors = [];

  const GROUP_LABELS = {
    basic: 'Basic',
    media: 'Media',
    structured: 'Structured content',
    technical: 'Technical',
    notice: 'Notices',
    layout: 'Layout',
    legacy: 'Legacy'
  };

  function updateDiagnostics() {
    window.__KC_EDITOR_DIAGNOSTICS = {
      release: '1.0.8',
      editorJsVersion: window.EditorJS ? (window.EditorJS.version || '2.30.8') : null,
      ready: editorReady,
      toolCount: builtToolsResult ? Object.keys(builtToolsResult.tools || {}).length : 0,
      catalogCount: catalog().length,
      bootstrapCount: 1,
      errors: runtimeErrors.slice()
    };
  }

  function showBootError(message) {
    runtimeErrors.push(message);
    updateDiagnostics();
    const target = document.getElementById('kc-editor-error') || document.getElementById('kc-editorjs');
    if (target && target.id === 'kc-editor-error') {
      target.hidden = false;
      target.textContent = message;
    } else if (target) {
      target.innerHTML = '<div class="kc-editor-error-panel" role="alert">' + escapeHtml(message) + '</div>';
    }
    root.dataset.editorState = 'error';
    if (statusEl) {
      statusEl.textContent = 'Editor failed';
      statusEl.dataset.state = 'error';
    }
    console.error('[KC Editor 1.0.8]', message);
  }

  function catalog() {
    return Array.isArray(cfg.catalog) ? cfg.catalog : [];
  }

  function catalogItem(id) {
    return catalog().find(function (item) {
      return item.id === id || item.type === id || item.editorType === id;
    }) || null;
  }

  function setStatus(text, state) {
    if (statusEl) {
      statusEl.textContent = text;
      statusEl.dataset.state = state || '';
    }
    if (barSave) barSave.textContent = text;
  }

  function markDirty() {
    dirty = true;
    setStatus('Unsaved changes', 'dirty');
    scheduleAutosave();
    refreshCountsSoon();
  }

  function scheduleAutosave() {
    if (!cfg.autosave || !Number(idField && idField.value)) return;
    clearTimeout(saveTimer);
    saveTimer = setTimeout(function () { save(true); }, 2500);
  }

  async function api(action, body) {
    const payload = Object.assign({
      _action: action,
      _csrf: cfg.csrf,
      entity: cfg.entity,
      id: Number((idField && idField.value) || 0)
    }, body || {});
    const res = await fetch(cfg.apiUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': cfg.csrf,
        Accept: 'application/json'
      },
      body: JSON.stringify(payload)
    });
    const data = await res.json().catch(function () { return { ok: false, error: 'Invalid server response.' }; });
    if (!res.ok || !data.ok) {
      throw new Error(data.error || 'Request failed.');
    }
    return data;
  }

  function collectFields() {
    const cats = [];
    form.querySelectorAll('input[name="categories[]"]:checked').forEach(function (el) {
      cats.push(Number(el.value));
    });
    return {
      title: titleInput ? titleInput.value.trim() : '',
      slug: slugInput ? slugInput.value.trim() : '',
      status: statusSelect ? statusSelect.value : 'draft',
      meta_title: (form.querySelector('[name="meta_title"]') || {}).value || '',
      meta_desc: (form.querySelector('[name="meta_desc"]') || {}).value || '',
      excerpt: (form.querySelector('[name="excerpt"]') || {}).value || '',
      categories: cats,
      expected_updated_at: updatedField ? updatedField.value : '',
      mode: modeField ? modeField.value : cfg.mode
    };
  }

  async function save(isAutosave) {
    if (saving) return;
    if (!titleInput || !titleInput.value.trim()) {
      if (!isAutosave) setStatus('Title is required', 'error');
      return;
    }
    if (cfg.mode === 'structured' && !editorReady) {
      if (!isAutosave) setStatus('Editor is not ready', 'error');
      return;
    }
    saving = true;
    setStatus('Saving…', 'saving');
    try {
      const fields = collectFields();
      const payload = fields;
      if (fields.mode === 'structured' && editor) {
        const output = await editor.save();
        if (documentField) documentField.value = JSON.stringify(output);
        payload.document = output;
      } else {
        payload.legacy_html = (document.getElementById('kc-legacy-html') || {}).value || '';
      }
      const result = await api(isAutosave ? 'autosave' : 'save', payload);
      if (idField) idField.value = String(result.id);
      if (updatedField) updatedField.value = result.updated_at || '';
      if (result.slug && slugInput && !slugInput.value) slugInput.value = result.slug;
      if (result.view_url && liveLink) {
        liveLink.href = result.view_url;
        liveLink.classList.remove('is-disabled');
        liveLink.removeAttribute('aria-disabled');
      }
      dirty = false;
      setStatus('Saved', 'saved');
      if (barState) {
        barState.textContent = (result.status || (statusSelect && statusSelect.value) || 'draft').replace(/^./, function (c) {
          return c.toUpperCase();
        });
      }
      if (!isAutosave && result.id && cfg.editUrl && window.location.href.indexOf('id=' + result.id) === -1) {
        window.history.replaceState({}, '', cfg.editUrl + result.id);
      }
    } catch (err) {
      setStatus(err.message || 'Save failed', 'error');
    } finally {
      saving = false;
    }
  }

  function browseMedia(kind, cb) {
    mediaKind = kind || 'image';
    mediaCallback = cb;
    if (mediaModal) mediaModal.hidden = false;
    loadMedia('');
    if (mediaSearch) {
      mediaSearch.value = '';
      mediaSearch.focus();
    }
  }

  async function loadMedia(q) {
    if (!mediaGrid) return;
    mediaGrid.innerHTML = '<div class="kc-empty-hint">Loading…</div>';
    try {
      const data = await api('media_list', { q: q, kind: mediaKind });
      mediaGrid.innerHTML = '';
      if (!data.items.length) {
        mediaGrid.innerHTML = '<div class="kc-empty-hint">No matching files.</div>';
        return;
      }
      data.items.forEach(function (item) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'kc-media-item';
        if ((item.mime || '').indexOf('image/') === 0) {
          btn.innerHTML = '<img src="' + escapeAttr(item.url) + '" alt=""><span></span>';
          btn.querySelector('span').textContent = item.name;
        } else {
          btn.innerHTML = '<span></span>';
          btn.querySelector('span').textContent = item.name;
        }
        btn.addEventListener('click', function () {
          if (mediaCallback) mediaCallback(item);
          if (mediaModal) mediaModal.hidden = true;
        });
        mediaGrid.appendChild(btn);
      });
    } catch (err) {
      mediaGrid.innerHTML = '<div class="kc-empty-hint">' + escapeHtml(err.message) + '</div>';
    }
  }

  async function uploadFile(file, kind) {
    const body = new FormData();
    body.append('file', file);
    body.append('_csrf', cfg.csrf);
    body.append('kind', kind || 'image');
    const res = await fetch(cfg.uploadUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': cfg.csrf },
      body: body
    });
    const data = await res.json().catch(function () { return { success: 0 }; });
    if (!res.ok || !data.success) throw new Error(data.error || 'Upload failed.');
    return {
      id: data.file.assetId,
      assetId: data.file.assetId,
      url: data.file.url,
      name: data.file.name,
      mime: data.file.mime
    };
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (ch) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
  }
  function escapeAttr(value) { return escapeHtml(value); }

  function strip(html) {
    const d = document.createElement('div');
    d.innerHTML = html || '';
    return d.textContent || '';
  }

  function requireGlobal(name) {
    const value = window[name];
    if (typeof value === 'undefined' || value === null) {
      throw new Error('Required editor library is missing: ' + name + '. Check that vendor scripts loaded.');
    }
    return value;
  }

  function buildTools() {
    const custom = (window.KcEditorRegistry && window.KcEditorRegistry.getTools()) || {};
    const imageConfig = { onBrowse: browseMedia, uploader: uploadFile };
    const EditorJS = requireGlobal('EditorJS');
    const Header = requireGlobal('Header');
    const EditorjsList = requireGlobal('EditorjsList');
    const Quote = requireGlobal('Quote');
    const Delimiter = requireGlobal('Delimiter');
    const Table = requireGlobal('Table');
    const Underline = requireGlobal('Underline');
    const InlineCode = requireGlobal('InlineCode');
    const KcImage = custom.image || requireGlobal('KcImage');
    const KcLink = custom.linkCard || custom.link || requireGlobal('KcLink');
    const KcCode = custom.code || requireGlobal('KcCode');
    const KcCallout = custom.callout || requireGlobal('KcCallout');
    const KcFile = custom.file || requireGlobal('KcFile');
    const KcLegacy = custom.legacy || window.KcLegacy;
    const KcSteps = custom.steps || window.KcSteps;
    const KcAccordion = custom.accordion || window.KcAccordion;
    const KcFaq = custom.faq || window.KcFaq;
    const KcTabs = custom.tabs || window.KcTabs;
    const KcCodeGroup = custom.codeGroup || window.KcCodeGroup;
    const KcDefinitionList = custom.definitionList || window.KcDefinitionList;
    const KcStatusBadge = custom.statusBadge || window.KcStatusBadge;
    const KcGroup = custom.group || window.KcGroup;
    const KcColumns = custom.columns || window.KcColumns;
    const KcCards = custom.cards || window.KcCards;

    // Do NOT register a block tool named "link" — it replaces Editor.js built-in Link inline tool.
    const tools = {
      paragraph: {
        inlineToolbar: ['bold', 'italic', 'underline', 'inlineCode', 'link'],
        config: { placeholder: 'Start writing… Type / to insert a block' }
      },
      header: { class: Header, inlineToolbar: ['italic', 'underline', 'link'], config: { levels: [2, 3, 4, 5, 6], defaultLevel: 2 } },
      list: { class: EditorjsList, inlineToolbar: true, config: { defaultStyle: 'unordered' } },
      quote: { class: Quote, inlineToolbar: true },
      delimiter: Delimiter,
      table: { class: Table, inlineToolbar: true, config: { withHeadings: true } },
      underline: Underline,
      inlineCode: { class: InlineCode },
      image: { class: KcImage, config: imageConfig },
      linkCard: { class: KcLink },
      code: { class: KcCode },
      callout: { class: KcCallout },
      file: { class: KcFile, config: imageConfig }
    };

    if (KcLegacy) tools.legacy = { class: KcLegacy };
    if (KcSteps) tools.steps = { class: KcSteps };
    if (KcAccordion) tools.accordion = { class: KcAccordion };
    if (KcFaq) tools.faq = { class: KcFaq };
    if (KcTabs) tools.tabs = { class: KcTabs };
    if (KcCodeGroup) tools.codeGroup = { class: KcCodeGroup };
    if (KcDefinitionList) tools.definitionList = { class: KcDefinitionList };
    if (KcStatusBadge) tools.statusBadge = { class: KcStatusBadge };
    if (KcGroup) tools.group = { class: KcGroup };
    if (KcColumns) tools.columns = { class: KcColumns };
    if (KcCards) tools.cards = { class: KcCards };

    builtToolsResult = { EditorJS: EditorJS, tools: tools };
    return builtToolsResult;
  }

  function starterData(raw) {
    const data = raw && typeof raw === 'object' ? raw : { blocks: [] };
    const blocks = Array.isArray(data.blocks) ? data.blocks.slice() : [];
    // Normalize any legacy editor tool name "link" block payloads that may collide.
    const normalized = blocks.map(function (block) {
      if (!block || typeof block !== 'object') return block;
      if (block.type === 'link' && block.data && (block.data.url !== undefined || block.data.title !== undefined)) {
        return Object.assign({}, block, { type: 'linkCard' });
      }
      return block;
    });
    if (!normalized.length) {
      normalized.push({ type: 'paragraph', data: { text: '' } });
    }
    return {
      time: data.time || Date.now(),
      blocks: normalized,
      version: data.version || '2.30.8'
    };
  }

  async function initEditor() {
    if (cfg.mode !== 'structured') return;
    if (!holder) throw new Error('Editor holder #kc-editorjs was not found.');

    const built = buildTools();
    const EditorJS = built.EditorJS;

    editor = new EditorJS({
      holder: holder,
      autofocus: true,
      defaultBlock: 'paragraph',
      placeholder: 'Start writing… Type / to insert a block',
      data: starterData(cfg.initialData),
      tools: built.tools,
      onChange: function () {
        if (suppressChange) return;
        markDirty();
        pushHistorySoon();
        refreshOutline();
        refreshSelection();
        refreshEmptyState();
      },
      onReady: function () {
        editorReady = true;
        root.classList.add('is-editor-ready');
        root.dataset.editorReady = '1';
        root.dataset.editorState = 'ready';
        updateDiagnostics();
      }
    });

    await editor.isReady;
    editorReady = true;
    root.classList.add('is-editor-ready');
    root.dataset.editorReady = '1';
    root.dataset.editorState = 'ready';
    if (errorBanner) errorBanner.hidden = true;

    await pushHistory(true);
    refreshOutline();
    refreshSelection();
    refreshEmptyState();
    refreshCounts();
    bindSlash();
    bindBlockChrome();
    bindCanvasClickToFocus();
    updateDiagnostics();

    try { editor.caret.focus(true); } catch (e) { /* ignore */ }
  }

  function ensureReady() {
    return !!(editor && editorReady);
  }

  function currentIndex() {
    if (!editor) return 0;
    try {
      const idx = editor.blocks.getCurrentBlockIndex();
      return typeof idx === 'number' && idx >= 0 ? idx : (lastSelectedIndex || 0);
    } catch (e) {
      return lastSelectedIndex || 0;
    }
  }

  async function snapshot() {
    if (!editor) return { blocks: [] };
    lastSnapshot = await editor.save();
    return lastSnapshot;
  }

  async function pushHistory(force) {
    if (!editor || historyLock) return;
    try {
      const data = await editor.save();
      const json = JSON.stringify(data.blocks || []);
      if (!force && historyIndex >= 0 && history[historyIndex] === json) return;
      history = history.slice(0, historyIndex + 1);
      history.push(json);
      if (history.length > 80) history.shift();
      historyIndex = history.length - 1;
    } catch (e) { /* ignore */ }
  }

  let historyTimer = null;
  function pushHistorySoon() {
    clearTimeout(historyTimer);
    historyTimer = setTimeout(function () { pushHistory(false); }, 400);
  }

  async function restoreHistory(index) {
    if (!editor || index < 0 || index >= history.length) return;
    historyLock = true;
    suppressChange = true;
    try {
      const blocks = JSON.parse(history[index]);
      await editor.blocks.render({ blocks: blocks });
      historyIndex = index;
      markDirty();
      refreshOutline();
      refreshSelection();
      refreshEmptyState();
    } finally {
      suppressChange = false;
      historyLock = false;
    }
  }

  async function undo() {
    if (historyIndex <= 0) return;
    await restoreHistory(historyIndex - 1);
  }

  async function redo() {
    if (historyIndex >= history.length - 1) return;
    await restoreHistory(historyIndex + 1);
  }

  async function insertCatalogItem(item, atIndex, replaceCurrent) {
    if (!item) return;
    if (!ensureReady()) {
      setStatus('Editor is initializing…', 'saving');
      return;
    }
    const type = item.editorType || item.type;
    const data = item.data || {};
    let index = typeof atIndex === 'number' ? atIndex : currentIndex();
    if (replaceCurrent) {
      try { await editor.blocks.delete(index); } catch (e) { /* keep going */ }
      await editor.blocks.insert(type, data, undefined, index, true);
    } else {
      index = Math.max(0, index + 1);
      await editor.blocks.insert(type, data, undefined, index, true);
    }
    try { editor.caret.setToBlock(index, 'start'); } catch (e) { /* ignore */ }
    markDirty();
    refreshEmptyState();
    pushHistorySoon();
    refreshOutline();
    refreshSelection();
  }

  async function insertById(id, replaceCurrent) {
    await insertCatalogItem(catalogItem(id), currentIndex(), !!replaceCurrent);
  }

  async function refreshOutline() {
    if (!editor || !outlineEl) return;
    try {
      const data = await snapshot();
      const headings = (data.blocks || []).filter(function (b) { return b.type === 'header' || b.type === 'heading'; });
      outlineEl.innerHTML = '';
      if (!headings.length) {
        outlineEl.innerHTML = '<div class="kc-empty-hint">Headings will appear here.</div>';
        return;
      }
      headings.forEach(function (block) {
        const btn = document.createElement('button');
        btn.type = 'button';
        const level = (block.data && block.data.level) || 2;
        btn.className = 'kc-outline-item kc-outline-h' + level;
        btn.textContent = strip(block.data && block.data.text ? block.data.text : 'Heading') || 'Heading';
        btn.addEventListener('click', function () {
          const el = document.querySelector('[data-id="' + block.id + '"]');
          if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
        outlineEl.appendChild(btn);
      });
    } catch (e) { /* mounting */ }
  }

  function currentBlockText() {
    const sel = window.getSelection();
    if (!sel || !sel.anchorNode) return '';
    const node = sel.anchorNode.nodeType === 1 ? sel.anchorNode : sel.anchorNode.parentElement;
    const block = node && node.closest ? node.closest('.ce-block') : null;
    return block ? (block.innerText || '').trim() : '';
  }

  function bindSlash() {
    if (!holder) return;
    slashMenu = document.createElement('div');
    slashMenu.className = 'kc-slash';
    slashMenu.hidden = true;
    document.body.appendChild(slashMenu);

    holder.addEventListener('keyup', function (event) {
      if (!ensureReady()) return;
      const text = currentBlockText();
      if (text.charAt(0) === '/') showSlash(text.slice(1), event);
      else hideSlash();
    });
    holder.addEventListener('keydown', function (event) {
      if (!slashMenu || slashMenu.hidden) return;
      if (event.key === 'ArrowDown') { event.preventDefault(); slashIndex = Math.min(slashIndex + 1, slashMatches.length - 1); paintSlash(); }
      else if (event.key === 'ArrowUp') { event.preventDefault(); slashIndex = Math.max(slashIndex - 1, 0); paintSlash(); }
      else if (event.key === 'Enter' && slashMatches[slashIndex]) { event.preventDefault(); insertSlash(slashMatches[slashIndex]); }
      else if (event.key === 'Escape') hideSlash();
    });
  }

  function showSlash(query, event) {
    if (!slashMenu) return;
    const q = (query || '').toLowerCase();
    slashMatches = catalog().filter(function (item) {
      const hay = ((item.label || '') + ' ' + (item.alias || '') + ' ' + (item.keywords || '') + ' ' + (item.description || '')).toLowerCase();
      return !q || hay.indexOf(q) !== -1;
    });
    slashIndex = 0;
    paintSlash();
    slashMenu.hidden = slashMatches.length === 0;
    if (!slashMenu.hidden && event && event.target && event.target.getBoundingClientRect) {
      const rect = event.target.getBoundingClientRect();
      slashMenu.style.left = Math.max(16, rect.left) + 'px';
      slashMenu.style.top = (rect.bottom + window.scrollY + 6) + 'px';
    }
  }

  function paintSlash() {
    slashMenu.innerHTML = '';
    slashMatches.forEach(function (item, i) {
      const btn = document.createElement('button');
      btn.type = 'button';
      const icon = item.icon || (item.label || '?').charAt(0);
      btn.innerHTML = '<span class="kc-component-ico">' + escapeHtml(icon) + '</span><span><strong>' + escapeHtml(item.label) + '</strong><small>' + escapeHtml(item.description || '') + '</small></span>';
      if (i === slashIndex) btn.className = 'is-active';
      btn.addEventListener('click', function () { insertSlash(item); });
      slashMenu.appendChild(btn);
    });
  }

  async function insertSlash(item) {
    hideSlash();
    await insertCatalogItem(item, currentIndex(), true);
  }

  function hideSlash() {
    if (slashMenu) slashMenu.hidden = true;
  }

  async function moveBlock(delta) {
    if (!ensureReady()) return;
    const index = currentIndex();
    const next = index + delta;
    if (next < 0 || next >= editor.blocks.getBlocksCount()) return;
    await editor.blocks.move(next, index);
    markDirty();
    pushHistorySoon();
  }

  async function duplicateBlock() {
    if (!ensureReady()) return;
    const index = currentIndex();
    const data = await snapshot();
    const block = data.blocks[index];
    if (!block) return;
    await editor.blocks.insert(block.type, block.data, undefined, index + 1, true);
    markDirty();
    pushHistorySoon();
  }

  async function deleteBlock() {
    if (!ensureReady()) return;
    await editor.blocks.delete(currentIndex());
    markDirty();
    refreshEmptyState();
    pushHistorySoon();
  }

  async function convertBlock(type, extra) {
    if (!ensureReady()) return;
    const index = currentIndex();
    const data = await snapshot();
    const block = data.blocks[index];
    if (!block) return;
    const text = (block.data && (block.data.text || block.data.code || '')) || '';
    let next = extra || {};
    if (type === 'header') next = Object.assign({ text: text, level: 2 }, extra || {});
    else if (type === 'quote') next = { text: text };
    else if (type === 'code') next = { code: strip(text) };
    else if (type === 'list') next = Object.assign({ style: 'unordered', items: [] }, extra || {});
    else next = { text: text };
    await editor.blocks.delete(index);
    await editor.blocks.insert(type, next, undefined, index, true);
    markDirty();
    pushHistorySoon();
  }

  function applyInline(cmd) {
    if (!ensureReady()) return;
    const map = { bold: 'bold', italic: 'italic', underline: 'underline', strike: 'strikeThrough' };
    if (map[cmd]) {
      document.execCommand(map[cmd]);
      markDirty();
      return;
    }
    if (cmd === 'inlineCode') {
      const inlineBtn = document.querySelector('.ce-inline-tool[data-tool="inlineCode"], .ce-inline-toolbar [data-item-name="inlineCode"]');
      if (inlineBtn) inlineBtn.click();
      else document.execCommand('insertHTML', false, '<code>' + (window.getSelection() || '') + '</code>');
      markDirty();
      return;
    }
    if (cmd === 'link') {
      // Prefer built-in Editor.js link tool when available.
      const linkBtn = document.querySelector('.ce-inline-tool--link, .ce-inline-tool[data-tool="link"], .ce-inline-toolbar [data-item-name="link"]');
      if (linkBtn) {
        linkBtn.click();
        return;
      }
      const url = window.prompt('Link URL');
      if (!url) return;
      document.execCommand('createLink', false, url);
      markDirty();
    }
  }

  /**
   * Component library search and filtering (enhances server-rendered cards).
   */
  function filterComponents(query) {
    if (!componentList) return;
    const q = (query || '').toLowerCase().trim();
    const groups = componentList.querySelectorAll('.kc-component-group');
    let visibleCount = 0;

    groups.forEach(function (grp) {
      const items = grp.querySelectorAll('.kc-component');
      let grpVisible = 0;
      items.forEach(function (btn) {
        const text = (btn.textContent + ' ' + (btn.dataset.keywords || '') + ' ' + (btn.dataset.componentId || '')).toLowerCase();
        const match = !q || text.indexOf(q) !== -1;
        btn.hidden = !match;
        if (match) {
          grpVisible++;
          visibleCount++;
        }
      });
      grp.hidden = grpVisible === 0;
    });

    let emptyHint = componentList.querySelector('.kc-empty-hint');
    if (visibleCount === 0) {
      if (!emptyHint) {
        emptyHint = document.createElement('div');
        emptyHint.className = 'kc-empty-hint';
        emptyHint.textContent = 'No matching components.';
        componentList.appendChild(emptyHint);
      }
      emptyHint.hidden = false;
    } else if (emptyHint) {
      emptyHint.hidden = true;
    }
  }

  /**
   * Bind event listeners for component library (delegated on componentList).
   */
  function bindComponents() {
    if (!componentList) return;
    componentList.addEventListener('click', function (event) {
      const btn = event.target.closest('.kc-component');
      if (!btn) return;
      const id = btn.dataset.componentId;
      if (!id) return;
      if (!ensureReady()) {
        setStatus('Editor is initializing…', 'saving');
        return;
      }
      insertById(id, false);
    });

    componentList.addEventListener('dragstart', function (event) {
      const btn = event.target.closest('.kc-component');
      if (!btn) return;
      const id = btn.dataset.componentId;
      if (id && event.dataTransfer) {
        event.dataTransfer.setData('text/plain', id);
        event.dataTransfer.effectAllowed = 'copy';
      }
    });
  }

  async function refreshSelection() {
    if (!editor) return;
    try {
      lastSelectedIndex = editor.blocks.getCurrentBlockIndex();
      const data = lastSnapshot || await snapshot();
      const block = (data.blocks || [])[lastSelectedIndex];
      renderInspector(block || null);
      const style = document.getElementById('kc-block-style');
      if (style && block) {
        if (block.type === 'header') style.value = 'h' + ((block.data && block.data.level) || 2);
        else if (block.type === 'paragraph') style.value = 'paragraph';
      }
    } catch (e) {
      renderInspector(null);
    }
  }

  function renderInspector(block) {
    if (!blockFields) return;
    if (!block) {
      if (selectedMeta) selectedMeta.textContent = 'Select a block to edit its settings.';
      blockFields.innerHTML = '';
      return;
    }
    if (selectedMeta) selectedMeta.textContent = '';
    const type = block.type;
    const data = block.data || {};
    const fields = [];
    if (type === 'header' || type === 'heading') {
      fields.push(selectField('level', 'Heading level', String(data.level || 2), ['2', '3', '4', '5', '6']));
    } else if (type === 'callout') {
      fields.push(selectField('tone', 'Callout type', data.tone || 'info', ['info', 'note', 'tip', 'warning', 'danger', 'success']));
      fields.push(textField('title', 'Title', data.title || ''));
    } else if (type === 'code') {
      fields.push(textField('language', 'Language', data.language || ''));
      fields.push(textField('caption', 'Caption', data.caption || ''));
    } else if (type === 'image') {
      fields.push(textField('alt', 'Alt text', data.alt || ''));
      fields.push(textField('caption', 'Caption', data.caption || ''));
      fields.push(selectField('align', 'Alignment', data.align || 'center', ['left', 'center', 'right']));
      fields.push(selectField('width', 'Width', data.width || 'default', ['default', 'wide', 'full']));
    } else if (type === 'link' || type === 'linkCard') {
      fields.push(textField('url', 'URL', data.url || ''));
      fields.push(textField('title', 'Title', data.title || ''));
    } else if (type === 'quote') {
      fields.push(textField('caption', 'Caption', data.caption || ''));
    } else if (type === 'table') {
      fields.push(selectField('withHeadings', 'Header row', data.withHeadings ? '1' : '0', ['1', '0']));
    } else if (type === 'statusBadge') {
      fields.push(selectField('status', 'Status', data.status || 'stable', ['stable', 'beta', 'deprecated', 'internal', 'experimental', 'recommended']));
      fields.push(textField('label', 'Label', data.label || ''));
    } else if (type === 'columns') {
      fields.push(selectField('layout', 'Layout', data.layout || '50-50', ['50-50', '33-67', '67-33', '33-33-33']));
    } else if (type === 'group') {
      fields.push(textField('title', 'Title', data.title || ''));
    } else {
      blockFields.innerHTML = '<p class="kc-empty-hint">No extra settings for this block. Edit it directly in the canvas.</p>';
      return;
    }
    blockFields.innerHTML = fields.join('');
    blockFields.querySelectorAll('[data-block-field]').forEach(function (input) {
      input.addEventListener('change', function () { applyInspectorField(block, input); });
      input.addEventListener('input', function () { applyInspectorField(block, input); });
    });
  }

  function textField(name, label, value) {
    return '<div class="kc-field"><label>' + escapeHtml(label) + '</label><input data-block-field="' + name + '" value="' + escapeAttr(value) + '"></div>';
  }
  function selectField(name, label, value, options) {
    const opts = options.map(function (opt) {
      return '<option value="' + escapeAttr(opt) + '"' + (String(opt) === String(value) ? ' selected' : '') + '>' + escapeHtml(opt) + '</option>';
    }).join('');
    return '<div class="kc-field"><label>' + escapeHtml(label) + '</label><select data-block-field="' + name + '">' + opts + '</select></div>';
  }

  async function applyInspectorField(block, input) {
    if (!ensureReady() || !block) return;
    const key = input.getAttribute('data-block-field');
    let value = input.value;
    if (key === 'level') value = parseInt(value, 10);
    if (key === 'withHeadings') value = value === '1';
    const next = Object.assign({}, block.data || {});
    next[key] = value;
    if (typeof editor.blocks.update === 'function' && block.id) {
      await editor.blocks.update(block.id, next);
    }
    block.data = next;
    markDirty();
    pushHistorySoon();
  }

  function refreshEmptyState() {
    if (!emptyState || !editor) return;
    const count = editor.blocks.getBlocksCount();
    let empty = count === 0;
    if (count === 1) {
      const first = document.querySelector('#kc-editorjs .ce-block');
      const text = first ? (first.innerText || '').trim() : '';
      empty = text === '' || text === '/';
    }
    emptyState.hidden = !empty;
  }

  function refreshCountsSoon() {
    clearTimeout(refreshCountsSoon.t);
    refreshCountsSoon.t = setTimeout(refreshCounts, 200);
  }

  async function refreshCounts() {
    if (!wordCountEl) return;
    let text = '';
    if (editor) {
      try {
        const data = await snapshot();
        (data.blocks || []).forEach(function (block) {
          const d = block.data || {};
          text += ' ' + strip(d.text || d.code || d.caption || d.title || d.question || d.term || d.label || '');
          if (Array.isArray(d.items)) {
            d.items.forEach(function (item) {
              if (typeof item === 'string') text += ' ' + strip(item);
              else if (item && typeof item === 'object') {
                text += ' ' + strip(item.content || item.text || item.title || item.question || item.answer || item.term || item.description || item.code || '');
              }
            });
          }
          if (Array.isArray(d.columns)) {
            d.columns.forEach(function (col) {
              if (col && typeof col === 'object') text += ' ' + strip(col.content || '');
            });
          }
          if (Array.isArray(d.content)) {
            d.content.forEach(function (row) {
              if (Array.isArray(row)) text += ' ' + row.join(' ');
            });
          }
        });
      } catch (e) { text = ''; }
    } else {
      const legacy = document.getElementById('kc-legacy-html');
      text = legacy ? strip(legacy.value) : ((titleInput && titleInput.value) || '');
    }
    text = text.replace(/\s+/g, ' ').trim();
    const words = text ? text.split(' ').length : 0;
    wordCountEl.textContent = words + (words === 1 ? ' word' : ' words');
    if (charCountEl) charCountEl.textContent = text.length + ' characters';
  }

  function bindBlockChrome() {
    if (!holder) return;
    chromeEl = document.createElement('div');
    chromeEl.className = 'kc-block-chrome';
    chromeEl.hidden = true;
    chromeEl.innerHTML = [
      '<button type="button" data-act="before" title="Insert before">+</button>',
      '<button type="button" data-act="up" title="Move up">↑</button>',
      '<button type="button" data-act="down" title="Move down">↓</button>',
      '<button type="button" data-act="dup" title="Duplicate">⧉</button>',
      '<button type="button" data-act="after" title="Insert after">+</button>',
      '<button type="button" data-act="del" title="Delete">✕</button>'
    ].join('');
    document.body.appendChild(chromeEl);
    chromeEl.addEventListener('click', function (event) {
      const act = event.target.getAttribute('data-act');
      if (!act || !ensureReady()) return;
      if (act === 'up') moveBlock(-1);
      else if (act === 'down') moveBlock(1);
      else if (act === 'dup') duplicateBlock();
      else if (act === 'del') deleteBlock();
      else if (act === 'before') {
        const idx = Math.max(0, currentIndex() - 1);
        insertCatalogItem(catalogItem('paragraph'), idx, false);
      } else if (act === 'after') insertById('paragraph', false);
    });
    holder.addEventListener('click', function () { refreshSelection(); placeChrome(); });
    holder.addEventListener('keyup', function () { refreshSelection(); placeChrome(); });
    holder.addEventListener('dragover', function (event) { event.preventDefault(); });
    holder.addEventListener('drop', function (event) {
      event.preventDefault();
      if (!ensureReady()) return;
      const id = event.dataTransfer.getData('text/plain');
      if (id) insertById(id, false);
    });
  }

  function placeChrome() {
    if (!chromeEl) return;
    const block = document.querySelector('#kc-editorjs .ce-block--focused, #kc-editorjs .ce-block--selected, #kc-editorjs .ce-block:hover');
    if (!block) { chromeEl.hidden = true; return; }
    const rect = block.getBoundingClientRect();
    chromeEl.hidden = false;
    chromeEl.style.top = (rect.top + window.scrollY - 28) + 'px';
    chromeEl.style.left = Math.max(12, rect.right + window.scrollX - 210) + 'px';
  }

  /**
   * Whitespace click-to-focus on canvas stage/surface.
   */
  function bindCanvasClickToFocus() {
    const targets = [surfaceEl, stageEl, canvasEl].filter(Boolean);
    targets.forEach(function (el) {
      el.addEventListener('click', function (e) {
        if (!ensureReady()) return;
        if (e.target.closest('button, a, input, select, textarea, [contenteditable="true"], .kc-tool, .ce-toolbar, .ce-popover, .kc-slash, .kc-block-chrome')) {
          return;
        }
        try {
          const count = editor.blocks.getBlocksCount();
          if (count > 0) {
            editor.caret.setToBlock(count - 1, 'end');
          } else {
            editor.caret.focus(true);
          }
        } catch (err) {
          try { editor.caret.focus(true); } catch (e2) {}
        }
      });
    });
  }

  function setPane(side, open) {
    root.dataset[side] = open ? 'open' : 'closed';
    const btn = document.getElementById(side === 'left' ? 'kc-toggle-left' : 'kc-toggle-right');
    if (btn) btn.setAttribute('aria-pressed', open ? 'true' : 'false');
  }

  // Ribbon tabs (Home / Insert)
  document.querySelectorAll('[data-ribbon-tab]').forEach(function (tab) {
    tab.addEventListener('click', function () {
      document.querySelectorAll('[data-ribbon-tab]').forEach(function (el) { el.classList.toggle('is-active', el === tab); });
      document.querySelectorAll('[data-ribbon-panel]').forEach(function (panel) {
        panel.hidden = panel.getAttribute('data-ribbon-panel') !== tab.getAttribute('data-ribbon-tab');
      });
    });
  });

  // Left pane tabs (Components / Outline)
  document.querySelectorAll('[data-left-tab]').forEach(function (tab) {
    tab.addEventListener('click', function () {
      const name = tab.getAttribute('data-left-tab');
      document.querySelectorAll('[data-left-tab]').forEach(function (el) { el.classList.toggle('is-active', el === tab); });
      document.querySelectorAll('[data-left-panel]').forEach(function (panel) {
        panel.hidden = panel.getAttribute('data-left-panel') !== name;
      });
      setPane('left', true);
    });
  });

  const leftToggle = document.getElementById('kc-toggle-left');
  if (leftToggle) leftToggle.addEventListener('click', function () { setPane('left', root.dataset.left !== 'open'); });
  const rightToggle = document.getElementById('kc-toggle-right');
  if (rightToggle) rightToggle.addEventListener('click', function () { setPane('right', root.dataset.right !== 'open'); });

  document.querySelectorAll('[data-inline]').forEach(function (btn) {
    btn.addEventListener('mousedown', function (event) {
      event.preventDefault();
      applyInline(btn.getAttribute('data-inline'));
    });
  });

  document.querySelectorAll('[data-insert]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!ensureReady()) {
        setStatus('Editor is initializing…', 'saving');
        return;
      }
      insertById(btn.getAttribute('data-insert'), false);
    });
  });

  document.querySelectorAll('[data-cmd]').forEach(function (btn) {
    btn.addEventListener('mousedown', function (event) {
      event.preventDefault();
      const cmd = btn.getAttribute('data-cmd');
      if (cmd === 'undo') undo();
      else if (cmd === 'redo') redo();
    });
  });

  const styleSelect = document.getElementById('kc-block-style');
  if (styleSelect) {
    styleSelect.addEventListener('change', function () {
      if (!ensureReady()) return;
      const value = styleSelect.value;
      if (value === 'paragraph') convertBlock('paragraph');
      else convertBlock('header', { level: parseInt(value.replace('h', ''), 10) || 2 });
    });
  }

  const search = document.getElementById('kc-component-search');
  if (search) search.addEventListener('input', function () { filterComponents(search.value); });

  const emptyInsert = document.getElementById('kc-empty-insert');
  if (emptyInsert) {
    emptyInsert.addEventListener('click', function () {
      setPane('left', true);
      const tab = document.querySelector('[data-left-tab="components"]');
      if (tab) tab.click();
      if (ensureReady()) {
        try { editor.caret.focus(true); } catch (e) { /* ignore */ }
      }
    });
  }

  const saveBtn = document.getElementById('kc-save-btn');
  if (saveBtn) saveBtn.addEventListener('click', function (e) { e.preventDefault(); save(false); });

  const previewBtn = document.getElementById('kc-preview-btn');
  if (previewBtn) {
    previewBtn.addEventListener('click', async function (e) {
      e.preventDefault();
      if (!ensureReady()) {
        setStatus('Editor is not ready', 'error');
        return;
      }
      try {
        const output = await editor.save();
        const result = await api('preview', { document: output, mode: 'structured' });
        if (previewBody) previewBody.innerHTML = result.html || '<p>Nothing to preview yet.</p>';
        if (previewModal) previewModal.hidden = false;
      } catch (err) {
        setStatus(err.message || 'Preview failed', 'error');
      }
    });
  }

  document.querySelectorAll('[data-kc-close]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (previewModal) previewModal.hidden = true;
      if (mediaModal) mediaModal.hidden = true;
    });
  });

  if (titleInput) titleInput.addEventListener('input', markDirty);
  if (slugInput) slugInput.addEventListener('input', markDirty);
  if (statusSelect) statusSelect.addEventListener('change', function () {
    markDirty();
    if (barState) barState.textContent = statusSelect.options[statusSelect.selectedIndex].text;
  });

  form.querySelectorAll('input,textarea,select').forEach(function (el) {
    if (el.closest('#kc-editorjs')) return;
    el.addEventListener('input', markDirty);
    el.addEventListener('change', markDirty);
  });

  if (mediaSearch) {
    let t = null;
    mediaSearch.addEventListener('input', function () {
      clearTimeout(t);
      t = setTimeout(function () { loadMedia(mediaSearch.value.trim()); }, 250);
    });
  }

  const convertBtn = document.getElementById('kc-convert-structured');
  if (convertBtn) {
    convertBtn.addEventListener('click', function (event) {
      if (!window.confirm('Open this document in the structured editor? The existing HTML is kept as a legacy block and is not mass-converted.')) {
        event.preventDefault();
      }
    });
  }

  window.addEventListener('beforeunload', function (event) {
    if (dirty) { event.preventDefault(); event.returnValue = ''; }
  });
  window.addEventListener('scroll', placeChrome, true);

  form.addEventListener('submit', function (event) {
    if (cfg.mode !== 'structured') return;
    event.preventDefault();
    save(false);
  });

  document.addEventListener('keydown', function (event) {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
      event.preventDefault();
      save(false);
    }
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'z' && !event.shiftKey) {
      if (ensureReady() && document.activeElement && holder && holder.contains(document.activeElement)) {
        event.preventDefault();
        undo();
      }
    }
    if ((event.metaKey || event.ctrlKey) && (event.key.toLowerCase() === 'y' || (event.shiftKey && event.key.toLowerCase() === 'z'))) {
      if (ensureReady() && document.activeElement && holder && holder.contains(document.activeElement)) {
        event.preventDefault();
        redo();
      }
    }
  });

  if (window.matchMedia('(max-width: 1100px)').matches) {
    setPane('left', false);
    setPane('right', false);
  }

  // Bind component cards immediately
  bindComponents();
  updateDiagnostics();

  // Run initialization
  if (cfg.mode === 'structured') {
    setStatus('Loading editor…', 'saving');
    initEditor().then(function () {
      setStatus(Number(idField && idField.value) ? 'Saved' : 'Not saved', Number(idField && idField.value) ? 'saved' : 'new');
    }).catch(function (err) {
      editorReady = false;
      editor = null;
      const message = (err && err.message) ? err.message : 'Editor failed to load';
      showBootError(message + ' Refresh the page. If this persists after update, hard-reload to clear cached scripts.');
      setStatus(message, 'error');
      if (errorBanner) {
        errorBanner.hidden = false;
        errorBanner.textContent = message;
      }
    });
  } else {
    setStatus('Legacy HTML document', '');
    refreshCounts();
  }
})();
