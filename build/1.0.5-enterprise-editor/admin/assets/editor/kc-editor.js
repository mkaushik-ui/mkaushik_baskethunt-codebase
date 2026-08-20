/**
 * Knowledge Center structured editor chrome.
 * Editor.js owns the canvas; this file owns save, slash, outline, preview, media.
 */
(function () {
  'use strict';

  const root = document.getElementById('kc-editor');
  if (!root) return;

  const cfg = JSON.parse(document.getElementById('kc-editor-config').textContent);
  const form = document.getElementById('kc-editor');
  const titleInput = document.getElementById('kc-doc-title');
  const slugInput = document.getElementById('kc-doc-slug');
  const statusSelect = document.getElementById('kc-doc-status');
  const statusEl = document.getElementById('kc-save-status');
  const outlineEl = document.getElementById('kc-outline-list');
  const selectedMeta = document.getElementById('kc-selected-meta');
  const previewModal = document.getElementById('kc-preview-modal');
  const previewBody = document.getElementById('kc-preview-body');
  const mediaModal = document.getElementById('kc-media-modal');
  const mediaGrid = document.getElementById('kc-media-grid');
  const mediaSearch = document.getElementById('kc-media-search');
  const documentField = document.getElementById('kc-document-json');
  const updatedField = document.getElementById('kc-updated-at');
  const idField = document.getElementById('kc-doc-id');
  const modeField = document.getElementById('kc-editor-mode');

  let editor = null;
  let dirty = false;
  let saving = false;
  let saveTimer = null;
  let mediaCallback = null;
  let mediaKind = 'image';
  let lastSelectedIndex = 0;

  const slashItems = [
    { type: 'paragraph', label: 'Paragraph', alias: 'text p' },
    { type: 'header', label: 'Heading', alias: 'h2 h3 title' },
    { type: 'list', label: 'Bulleted list', alias: 'ul bullet', data: { style: 'unordered' } },
    { type: 'list', label: 'Numbered list', alias: 'ol numbered', data: { style: 'ordered' } },
    { type: 'list', label: 'Checklist', alias: 'todo check', data: { style: 'checklist' } },
    { type: 'quote', label: 'Quote', alias: 'blockquote' },
    { type: 'delimiter', label: 'Divider', alias: 'hr rule' },
    { type: 'image', label: 'Image', alias: 'photo media' },
    { type: 'link', label: 'Link', alias: 'url' },
    { type: 'table', label: 'Table', alias: 'grid' },
    { type: 'code', label: 'Code', alias: 'snippet pre' },
    { type: 'callout', label: 'Callout', alias: 'notice warning tip info danger success note' },
    { type: 'file', label: 'File', alias: 'attachment download' }
  ];

  function setStatus(text, state) {
    if (!statusEl) return;
    statusEl.textContent = text;
    statusEl.className = 'kc-editor-status' + (state ? ' is-' + state : '');
  }

  function markDirty() {
    dirty = true;
    setStatus('Unsaved changes', '');
    scheduleAutosave();
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
        'Accept': 'application/json'
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
      slug: slugInput.value.trim(),
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
    setStatus(isAutosave ? 'Saving…' : 'Saving…', 'saving');
    try {
      const fields = collectFields();
      let payload = fields;
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
      if (result.slug && !slugInput.value) slugInput.value = result.slug;
      dirty = false;
      setStatus(isAutosave ? 'Saved' : 'Saved', 'saved');
      if (!isAutosave && result.id && cfg.editUrl) {
        const next = cfg.editUrl + result.id;
        if (window.location.href.indexOf('id=' + result.id) === -1) {
          window.history.replaceState({}, '', next);
        }
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
    mediaSearch.value = '';
    mediaSearch.focus();
  }

  async function loadMedia(q) {
    mediaGrid.innerHTML = '<div class="kc-outline-empty">Loading…</div>';
    try {
      const data = await api('media_list', { q: q, kind: mediaKind });
      mediaGrid.innerHTML = '';
      if (!data.items.length) {
        mediaGrid.innerHTML = '<div class="kc-outline-empty">No matching files.</div>';
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
      mediaGrid.innerHTML = '<div class="kc-outline-empty">' + escapeHtml(err.message) + '</div>';
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
  function escapeAttr(value) {
    return escapeHtml(value);
  }

  function buildTools() {
    const custom = (window.KcEditorRegistry && window.KcEditorRegistry.getTools()) || {};
    const imageConfig = {
      onBrowse: browseMedia,
      uploader: uploadFile
    };
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
      placeholder: 'Type / to add a block',
      data: cfg.initialData || { blocks: [] },
      tools: buildTools(),
      onChange: function () {
        markDirty();
        refreshOutline();
        refreshSelection();
      }
    });
    await editor.isReady;
    refreshOutline();
    bindSlash();
  }

  async function refreshOutline() {
    if (!editor || !outlineEl) return;
    try {
      const data = await editor.save();
      const headings = (data.blocks || []).filter(function (b) { return b.type === 'header' || b.type === 'heading'; });
      outlineEl.innerHTML = '';
      if (!headings.length) {
        outlineEl.innerHTML = '<div class="kc-outline-empty">Headings will appear here.</div>';
        return;
      }
      headings.forEach(function (block) {
        const btn = document.createElement('button');
        btn.type = 'button';
        const level = (block.data && block.data.level) || 2;
        btn.className = 'kc-outline-item kc-outline-h' + level;
        btn.textContent = strip(block.data && block.data.text ? block.data.text : 'Heading');
        btn.addEventListener('click', function () {
          const el = document.querySelector('[data-id="' + block.id + '"]');
          if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
        outlineEl.appendChild(btn);
      });
    } catch (e) { /* ignore while editor is still mounting */ }
  }

  function strip(html) {
    const d = document.createElement('div');
    d.innerHTML = html || '';
    return d.textContent || 'Heading';
  }

  function refreshSelection() {
    if (!editor || !selectedMeta) return;
    try {
      const index = editor.blocks.getCurrentBlockIndex();
      lastSelectedIndex = index;
      const block = editor.blocks.getBlockByIndex(index);
      selectedMeta.textContent = block ? ('Selected: ' + (block.name || 'block')) : 'No block selected';
    } catch (e) {
      selectedMeta.textContent = 'No block selected';
    }
  }

  function currentBlockText() {
    const sel = window.getSelection();
    if (!sel || !sel.anchorNode) return '';
    const block = sel.anchorNode.nodeType === 1 ? sel.anchorNode : sel.anchorNode.parentElement;
    const holder = block && block.closest ? block.closest('.ce-block') : null;
    return holder ? (holder.innerText || '').trim() : '';
  }

  let slashMenu = null;
  let slashIndex = 0;
  let slashMatches = [];

  function bindSlash() {
    const holder = document.getElementById('kc-editorjs');
    slashMenu = document.createElement('div');
    slashMenu.className = 'kc-slash';
    slashMenu.hidden = true;
    document.body.appendChild(slashMenu);

    holder.addEventListener('keyup', function (event) {
      const text = currentBlockText();
      if (text.charAt(0) === '/') {
        showSlash(text.slice(1), event);
      } else {
        hideSlash();
      }
    });
    holder.addEventListener('keydown', function (event) {
      if (slashMenu.hidden) return;
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        slashIndex = Math.min(slashIndex + 1, slashMatches.length - 1);
        paintSlash();
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        slashIndex = Math.max(slashIndex - 1, 0);
        paintSlash();
      } else if (event.key === 'Enter' && slashMatches[slashIndex]) {
        event.preventDefault();
        insertSlash(slashMatches[slashIndex]);
      } else if (event.key === 'Escape') {
        hideSlash();
      }
    });
  }

  function showSlash(query, event) {
    if (!slashMenu) return;
    const q = (query || '').toLowerCase();
    slashMatches = slashItems.filter(function (item) {
      return !q || item.label.toLowerCase().indexOf(q) !== -1 || item.alias.indexOf(q) !== -1;
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
      btn.textContent = item.label;
      if (i === slashIndex) btn.className = 'is-active';
      btn.addEventListener('click', function () { insertSlash(item); });
      slashMenu.appendChild(btn);
    });
  }

  async function insertSlash(item) {
    hideSlash();
    if (!editor) return;
    const index = editor.blocks.getCurrentBlockIndex();
    try {
      await editor.blocks.delete(index);
    } catch (e) { /* keep going */ }
    await editor.blocks.insert(item.type, item.data || {}, undefined, index, true);
    editor.caret.setToBlock(index, 'start');
    markDirty();
  }

  function hideSlash() {
    if (slashMenu) slashMenu.hidden = true;
  }

  async function addBlock(type, data) {
    if (!editor) return;
    const index = editor.blocks.getCurrentBlockIndex();
    await editor.blocks.insert(type, data || {}, undefined, index + 1, true);
    editor.caret.setToBlock(index + 1, 'start');
    markDirty();
  }

  async function moveBlock(delta) {
    if (!editor) return;
    const index = editor.blocks.getCurrentBlockIndex();
    const next = index + delta;
    if (next < 0 || next >= editor.blocks.getBlocksCount()) return;
    await editor.blocks.move(next, index);
    markDirty();
  }

  async function duplicateBlock() {
    if (!editor) return;
    const index = editor.blocks.getCurrentBlockIndex();
    const saved = await editor.save();
    const block = saved.blocks[index];
    if (!block) return;
    await editor.blocks.insert(block.type, block.data, undefined, index + 1, true);
    markDirty();
  }

  async function deleteBlock() {
    if (!editor) return;
    const index = editor.blocks.getCurrentBlockIndex();
    await editor.blocks.delete(index);
    markDirty();
  }

  async function convertBlock(type) {
    if (!editor) return;
    const index = editor.blocks.getCurrentBlockIndex();
    const saved = await editor.save();
    const block = saved.blocks[index];
    if (!block) return;
    const text = (block.data && (block.data.text || block.data.code || '')) || '';
    const data = type === 'header' ? { text: text, level: 2 }
      : type === 'quote' ? { text: text }
      : type === 'code' ? { code: strip(text) }
      : { text: text };
    await editor.blocks.delete(index);
    await editor.blocks.insert(type, data, undefined, index, true);
    markDirty();
  }

  const saveBtn = document.getElementById('kc-save-btn');
  if (saveBtn) {
    saveBtn.addEventListener('click', function (e) {
      e.preventDefault();
      save(false);
    });
  }
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
  const addBtn = document.getElementById('kc-add-block');
  if (addBtn) {
    addBtn.addEventListener('click', function () {
      showSlash('', { target: addBtn });
      if (slashMenu) slashMenu.hidden = false;
    });
  }
  const bindClick = function (id, handler) {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', handler);
  };
  bindClick('kc-block-up', function () { moveBlock(-1); });
  bindClick('kc-block-down', function () { moveBlock(1); });
  bindClick('kc-block-dup', function () { duplicateBlock(); });
  bindClick('kc-block-del', function () { deleteBlock(); });
  document.querySelectorAll('[data-convert]').forEach(function (btn) {
    btn.addEventListener('click', function () { convertBlock(btn.getAttribute('data-convert')); });
  });
  titleInput.addEventListener('input', markDirty);
  slugInput.addEventListener('input', markDirty);
  statusSelect.addEventListener('change', markDirty);
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
    if (dirty) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

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

  if (cfg.mode === 'structured') {
    initEditor().catch(function (err) {
      setStatus(err.message || 'Editor failed to load', 'error');
    });
  } else {
    setStatus('Legacy HTML document', '');
  }
})();
