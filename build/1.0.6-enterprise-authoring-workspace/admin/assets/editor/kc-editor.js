/**
 * Knowledge Center enterprise authoring workspace.
 * Workspace UI only — Editor.js remains the document engine.
 */
(function () {
  'use strict';

  const root = document.getElementById('kc-editor');
  if (!root) return;

  const cfg = JSON.parse(document.getElementById('kc-editor-config').textContent);
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

  let editor = null;
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

  const GROUP_LABELS = {
    basic: 'Basic',
    media: 'Media',
    structured: 'Structured content',
    legacy: 'Legacy',
    layout: 'Layout'
  };

  function catalog() {
    return Array.isArray(cfg.catalog) ? cfg.catalog : [];
  }

  function catalogItem(id) {
    return catalog().find(function (item) { return item.id === id || item.type === id; }) || null;
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
    if (!cfg.autosave || !Number(idField.value)) return;
    clearTimeout(saveTimer);
    saveTimer = setTimeout(function () { save(true); }, 2500);
  }

  async function api(action, body) {
    const payload = Object.assign({
      _action: action,
      _csrf: cfg.csrf,
      entity: cfg.entity,
      id: Number(idField.value || 0)
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
      title: titleInput.value.trim(),
      slug: slugInput ? slugInput.value.trim() : '',
      status: statusSelect.value,
      meta_title: (form.querySelector('[name="meta_title"]') || {}).value || '',
      meta_desc: (form.querySelector('[name="meta_desc"]') || {}).value || '',
      excerpt: (form.querySelector('[name="excerpt"]') || {}).value || '',
      categories: cats,
      expected_updated_at: updatedField.value,
      mode: modeField.value
    };
  }

  async function save(isAutosave) {
    if (saving) return;
    if (!titleInput.value.trim()) {
      if (!isAutosave) setStatus('Title is required', 'error');
      return;
    }
    saving = true;
    setStatus('Saving…', 'saving');
    try {
      const fields = collectFields();
      const payload = fields;
      if (fields.mode === 'structured' && editor) {
        const output = await editor.save();
        documentField.value = JSON.stringify(output);
        payload.document = output;
      } else {
        payload.legacy_html = (document.getElementById('kc-legacy-html') || {}).value || '';
      }
      const result = await api(isAutosave ? 'autosave' : 'save', payload);
      idField.value = String(result.id);
      updatedField.value = result.updated_at || '';
      if (result.slug && slugInput && !slugInput.value) slugInput.value = result.slug;
      if (result.view_url && liveLink) {
        liveLink.href = result.view_url;
        liveLink.classList.remove('is-disabled');
        liveLink.removeAttribute('aria-disabled');
      }
      dirty = false;
      setStatus('Saved', 'saved');
      if (barState) barState.textContent = (result.status || statusSelect.value || 'draft').replace(/^./, function (c) { return c.toUpperCase(); });
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
    mediaModal.hidden = false;
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
          mediaModal.hidden = true;
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

  function buildTools() {
    const custom = (window.KcEditorRegistry && window.KcEditorRegistry.getTools()) || {};
    const imageConfig = { onBrowse: browseMedia, uploader: uploadFile };
    return {
      paragraph: { inlineToolbar: ['bold', 'italic', 'underline', 'inlineCode', 'link'] },
      header: { class: Header, inlineToolbar: ['italic', 'underline'], config: { levels: [2, 3, 4, 5, 6], defaultLevel: 2 } },
      list: { class: EditorjsList, inlineToolbar: true, config: { defaultStyle: 'unordered' } },
      quote: { class: Quote, inlineToolbar: true },
      delimiter: Delimiter,
      table: { class: Table, inlineToolbar: true, config: { withHeadings: true } },
      underline: Underline,
      inlineCode: { class: InlineCode },
      image: { class: custom.image || KcImage, config: imageConfig },
      link: { class: custom.link || KcLink },
      code: { class: custom.code || KcCode },
      callout: { class: custom.callout || KcCallout },
      file: { class: custom.file || KcFile, config: imageConfig },
      legacy: { class: custom.legacy || KcLegacy }
    };
  }

  async function initEditor() {
    if (cfg.mode !== 'structured') return;
    editor = new EditorJS({
      holder: 'kc-editorjs',
      autofocus: !cfg.initialData || !(cfg.initialData.blocks || []).length,
      placeholder: 'Start writing or press / to insert a block',
      data: cfg.initialData || { blocks: [] },
      tools: buildTools(),
      onChange: function () {
        markDirty();
        refreshOutline();
        refreshSelection();
        refreshEmptyState();
      }
    });
    await editor.isReady;
    refreshOutline();
    refreshSelection();
    refreshEmptyState();
    refreshCounts();
    bindSlash();
    bindBlockChrome();
    paintComponents('');
  }

  function currentIndex() {
    if (!editor) return 0;
    try { return editor.blocks.getCurrentBlockIndex(); } catch (e) { return lastSelectedIndex || 0; }
  }

  async function snapshot() {
    if (!editor) return { blocks: [] };
    lastSnapshot = await editor.save();
    return lastSnapshot;
  }

  async function insertCatalogItem(item, atIndex, replaceCurrent) {
    if (!editor || !item) return;
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
    const holder = node && node.closest ? node.closest('.ce-block') : null;
    return holder ? (holder.innerText || '').trim() : '';
  }

  function bindSlash() {
    const holder = document.getElementById('kc-editorjs');
    if (!holder) return;
    slashMenu = document.createElement('div');
    slashMenu.className = 'kc-slash';
    slashMenu.hidden = true;
    document.body.appendChild(slashMenu);

    holder.addEventListener('keyup', function (event) {
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
      const hay = ((item.label || '') + ' ' + (item.alias || '') + ' ' + (item.description || '')).toLowerCase();
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
      btn.innerHTML = '<span class="kc-component-ico">' + escapeHtml((item.label || '?').charAt(0)) + '</span><span><strong>' + escapeHtml(item.label) + '</strong><small>' + escapeHtml(item.description || '') + '</small></span>';
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
    if (!editor) return;
    const index = currentIndex();
    const next = index + delta;
    if (next < 0 || next >= editor.blocks.getBlocksCount()) return;
    await editor.blocks.move(next, index);
    markDirty();
  }

  async function duplicateBlock() {
    if (!editor) return;
    const index = currentIndex();
    const data = await snapshot();
    const block = data.blocks[index];
    if (!block) return;
    await editor.blocks.insert(block.type, block.data, undefined, index + 1, true);
    markDirty();
  }

  async function deleteBlock() {
    if (!editor) return;
    await editor.blocks.delete(currentIndex());
    markDirty();
    refreshEmptyState();
  }

  async function convertBlock(type, extra) {
    if (!editor) return;
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
  }

  function applyInline(cmd) {
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
      const url = window.prompt('Link URL');
      if (!url) return;
      document.execCommand('createLink', false, url);
      markDirty();
    }
  }

  function paintComponents(query) {
    if (!componentList) return;
    const q = (query || '').toLowerCase();
    const groups = {};
    catalog().forEach(function (item) {
      const hay = ((item.label || '') + ' ' + (item.alias || '') + ' ' + (item.description || '')).toLowerCase();
      if (q && hay.indexOf(q) === -1) return;
      const group = item.group || 'basic';
      if (!groups[group]) groups[group] = [];
      groups[group].push(item);
    });
    componentList.innerHTML = '';
    Object.keys(groups).forEach(function (group) {
      const wrap = document.createElement('div');
      wrap.className = 'kc-component-group';
      wrap.innerHTML = '<h4>' + escapeHtml(GROUP_LABELS[group] || group) + '</h4>';
      groups[group].forEach(function (item) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'kc-component';
        btn.draggable = true;
        btn.dataset.componentId = item.id;
        btn.innerHTML = '<span class="kc-component-ico">' + escapeHtml((item.label || '?').charAt(0)) + '</span><span><strong>' + escapeHtml(item.label) + '</strong><span>' + escapeHtml(item.description || '') + '</span></span>';
        btn.addEventListener('click', function () { insertById(item.id, false); });
        btn.addEventListener('dragstart', function (event) {
          event.dataTransfer.setData('text/plain', item.id);
          event.dataTransfer.effectAllowed = 'copy';
        });
        wrap.appendChild(btn);
      });
      componentList.appendChild(wrap);
    });
    if (!componentList.children.length) {
      componentList.innerHTML = '<div class="kc-empty-hint">No matching components.</div>';
    }
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
    } else if (type === 'link') {
      fields.push(textField('url', 'URL', data.url || ''));
      fields.push(textField('title', 'Title', data.title || ''));
    } else if (type === 'quote') {
      fields.push(textField('caption', 'Caption', data.caption || ''));
    } else if (type === 'table') {
      fields.push(selectField('withHeadings', 'Header row', data.withHeadings ? '1' : '0', ['1', '0']));
    } else {
      blockFields.innerHTML = '<p class="kc-empty-hint">No extra settings for this block.</p>';
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
    if (!editor || !block) return;
    const key = input.getAttribute('data-block-field');
    let value = input.value;
    if (key === 'level') value = parseInt(value, 10);
    if (key === 'withHeadings') value = value === '1';
    const next = Object.assign({}, block.data || {});
    next[key] = value;
    if (typeof editor.blocks.update === 'function' && block.id) {
      try { editor.blocks.update(block.id, next); } catch (e) { /* fallback below */ }
    }
    block.data = next;
    markDirty();
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
          text += ' ' + strip(d.text || d.code || d.caption || d.title || '');
          if (Array.isArray(d.items)) {
            d.items.forEach(function (item) { text += ' ' + strip(item.content || item.text || ''); });
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
      text = legacy ? strip(legacy.value) : (titleInput.value || '');
    }
    text = text.replace(/\s+/g, ' ').trim();
    const words = text ? text.split(' ').length : 0;
    wordCountEl.textContent = words + (words === 1 ? ' word' : ' words');
    if (charCountEl) charCountEl.textContent = text.length + ' characters';
  }

  function bindBlockChrome() {
    const holder = document.getElementById('kc-editorjs');
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
      if (!act) return;
      if (act === 'up') moveBlock(-1);
      else if (act === 'down') moveBlock(1);
      else if (act === 'dup') duplicateBlock();
      else if (act === 'del') deleteBlock();
      else if (act === 'before') insertCatalogItem(catalogItem('paragraph'), currentIndex() - 1, false);
      else if (act === 'after') insertById('paragraph', false);
    });
    holder.addEventListener('click', function () { refreshSelection(); placeChrome(); });
    holder.addEventListener('keyup', function () { refreshSelection(); placeChrome(); });
    holder.addEventListener('dragover', function (event) { event.preventDefault(); });
    holder.addEventListener('drop', function (event) {
      event.preventDefault();
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

  function setPane(side, open) {
    root.dataset[side] = open ? 'open' : 'closed';
    const btn = document.getElementById(side === 'left' ? 'kc-toggle-left' : 'kc-toggle-right');
    if (btn) btn.setAttribute('aria-pressed', open ? 'true' : 'false');
  }

  document.querySelectorAll('[data-ribbon-tab]').forEach(function (tab) {
    tab.addEventListener('click', function () {
      document.querySelectorAll('[data-ribbon-tab]').forEach(function (el) { el.classList.toggle('is-active', el === tab); });
      document.querySelectorAll('[data-ribbon-panel]').forEach(function (panel) {
        panel.hidden = panel.getAttribute('data-ribbon-panel') !== tab.getAttribute('data-ribbon-tab');
      });
    });
  });
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
    btn.addEventListener('mousedown', function (event) { event.preventDefault(); applyInline(btn.getAttribute('data-inline')); });
  });
  document.querySelectorAll('[data-insert]').forEach(function (btn) {
    btn.addEventListener('click', function () { insertById(btn.getAttribute('data-insert'), false); });
  });
  document.querySelectorAll('[data-cmd]').forEach(function (btn) {
    btn.addEventListener('mousedown', function (event) {
      event.preventDefault();
      document.execCommand(btn.getAttribute('data-cmd'));
      markDirty();
    });
  });
  const styleSelect = document.getElementById('kc-block-style');
  if (styleSelect) {
    styleSelect.addEventListener('change', function () {
      const value = styleSelect.value;
      if (value === 'paragraph') convertBlock('paragraph');
      else convertBlock('header', { level: parseInt(value.replace('h', ''), 10) || 2 });
    });
  }
  const search = document.getElementById('kc-component-search');
  if (search) search.addEventListener('input', function () { paintComponents(search.value); });
  const emptyInsert = document.getElementById('kc-empty-insert');
  if (emptyInsert) {
    emptyInsert.addEventListener('click', function () {
      setPane('left', true);
      document.querySelector('[data-left-tab="components"]')?.click();
    });
  }

  const saveBtn = document.getElementById('kc-save-btn');
  if (saveBtn) saveBtn.addEventListener('click', function (e) { e.preventDefault(); save(false); });
  const previewBtn = document.getElementById('kc-preview-btn');
  if (previewBtn) {
    previewBtn.addEventListener('click', async function (e) {
      e.preventDefault();
      if (!editor) return;
      try {
        const output = await editor.save();
        const result = await api('preview', { document: output, mode: 'structured' });
        previewBody.innerHTML = result.html || '<p>Nothing to preview yet.</p>';
        previewModal.hidden = false;
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
    if (cfg.mode !== 'structured' || !editor) return;
    event.preventDefault();
    save(false);
  });
  document.addEventListener('keydown', function (event) {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
      event.preventDefault();
      save(false);
    }
  });

  if (window.matchMedia('(max-width: 1100px)').matches) {
    setPane('left', false);
    setPane('right', false);
  }

  if (cfg.mode === 'structured') {
    initEditor().catch(function (err) { setStatus(err.message || 'Editor failed to load', 'error'); });
  } else {
    setStatus('Legacy HTML document', '');
    refreshCounts();
  }
})();
