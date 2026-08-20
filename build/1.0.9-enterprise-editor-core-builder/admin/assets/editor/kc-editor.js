/**
 * Knowledge Center enterprise authoring workspace (1.0.9).
 * Editor.js is the document engine; this file owns chrome, panels, readiness, interactions, and controls.
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
  const canvasEl = document.getElementById('kc-canvas') || document.querySelector('.kc-canvas');
  const stageEl = document.querySelector('.kc-doc-stage');
  const surfaceEl = document.querySelector('.kc-doc-surface');

  // Collapsible panel controls
  const toggleLeftBtn = document.getElementById('kc-toggle-left');
  const closeLeftBtn = document.getElementById('kc-close-left');
  const reopenLeftBtn = document.getElementById('kc-reopen-left');

  const toggleRightBtn = document.getElementById('kc-toggle-right');
  const closeRightBtn = document.getElementById('kc-close-right');
  const reopenRightBtn = document.getElementById('kc-reopen-right');

  const moreComponentsBtn = document.getElementById('kc-ribbon-more-components');

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

  function updateDiagnostics() {
    window.__KC_EDITOR_DIAGNOSTICS = {
      release: '1.0.9',
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
    console.error('[KC Editor 1.0.9]', message);
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
      let docPayload = null;
      let legacyHtml = null;
      if (cfg.mode === 'structured') {
        docPayload = await editor.save();
        if (documentField) documentField.value = JSON.stringify(docPayload);
      } else {
        const legacyArea = document.getElementById('kc-legacy-html');
        legacyHtml = legacyArea ? legacyArea.value : '';
      }
      const fields = collectFields();
      const payload = Object.assign({}, fields, {
        document: docPayload,
        legacy_html: legacyHtml
      });
      const res = await api('save', payload);
      if (res.id && idField) idField.value = res.id;
      if (res.updated_at && updatedField) updatedField.value = res.updated_at;
      if (res.view_url && liveLink) {
        liveLink.href = res.view_url;
        liveLink.classList.remove('is-disabled');
        liveLink.removeAttribute('aria-disabled');
      }
      if (res.status && barState) {
        barState.textContent = res.status.charAt(0).toUpperCase() + res.status.slice(1);
      }
      dirty = false;
      setStatus('Saved', 'saved');
      if (isAutosave) {
        setTimeout(function () {
          if (!dirty) setStatus('Saved', 'saved');
        }, 1500);
      }
    } catch (err) {
      console.error('[KC Editor] Save failed:', err);
      setStatus(err.message || 'Save failed', 'error');
    } finally {
      saving = false;
    }
  }

  // --- Collapsible Panel Management (1.0.9) ---
  function setPane(side, open, savePref) {
    root.dataset[side] = open ? 'open' : 'closed';
    const toggleBtn = side === 'left' ? toggleLeftBtn : toggleRightBtn;
    if (toggleBtn) {
      toggleBtn.setAttribute('aria-pressed', open ? 'true' : 'false');
      toggleBtn.classList.toggle('is-active', open);
    }
    const reopenBtn = side === 'left' ? reopenLeftBtn : reopenRightBtn;
    if (reopenBtn) {
      reopenBtn.hidden = open;
    }
    if (savePref !== false) {
      try {
        localStorage.setItem('kc_pane_' + side, open ? 'open' : 'closed');
      } catch (e) {}
    }
    // Notify window resize so editor layout can adjust smoothly
    window.dispatchEvent(new Event('resize'));
  }

  function initPanels() {
    // Restore stored panel preferences or default based on screen width
    try {
      const leftPref = localStorage.getItem('kc_pane_left');
      const rightPref = localStorage.getItem('kc_pane_right');
      if (window.innerWidth <= 1040) {
        setPane('left', false, false);
        setPane('right', false, false);
      } else {
        if (leftPref) setPane('left', leftPref === 'open', false);
        else setPane('left', true, false);

        if (rightPref) setPane('right', rightPref === 'open', false);
        else setPane('right', true, false);
      }
    } catch (e) {
      setPane('left', true, false);
      setPane('right', true, false);
    }

    if (toggleLeftBtn) {
      toggleLeftBtn.addEventListener('click', function () {
        const isOpen = root.dataset.left === 'open';
        setPane('left', !isOpen, true);
      });
    }
    if (closeLeftBtn) {
      closeLeftBtn.addEventListener('click', function () {
        setPane('left', false, true);
      });
    }
    if (reopenLeftBtn) {
      reopenLeftBtn.addEventListener('click', function () {
        setPane('left', true, true);
      });
    }

    if (toggleRightBtn) {
      toggleRightBtn.addEventListener('click', function () {
        const isOpen = root.dataset.right === 'open';
        setPane('right', !isOpen, true);
      });
    }
    if (closeRightBtn) {
      closeRightBtn.addEventListener('click', function () {
        setPane('right', false, true);
      });
    }
    if (reopenRightBtn) {
      reopenRightBtn.addEventListener('click', function () {
        setPane('right', true, true);
      });
    }

    if (moreComponentsBtn) {
      moreComponentsBtn.addEventListener('click', function () {
        setPane('left', true, true);
        const compTab = document.querySelector('.kc-pane-tab[data-left-tab="components"]');
        if (compTab) compTab.click();
        const searchInput = document.getElementById('kc-component-search');
        if (searchInput) searchInput.focus();
      });
    }
  }

  // --- Left Pane Tabs (Components / Outline) ---
  function initLeftTabs() {
    const tabs = document.querySelectorAll('.kc-pane-tab[data-left-tab]');
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        const target = tab.dataset.leftTab;
        tabs.forEach(function (t) {
          const isActive = t === tab;
          t.classList.toggle('is-active', isActive);
          t.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        document.querySelectorAll('.kc-left-panel[data-left-panel]').forEach(function (panel) {
          panel.hidden = panel.dataset.leftPanel !== target;
        });
        if (target === 'outline') refreshOutline();
      });
    });
  }

  // --- Component Library Filtering & Insertion ---
  function initComponentLibrary() {
    const searchInput = document.getElementById('kc-component-search');
    if (searchInput) {
      searchInput.addEventListener('input', function () {
        const query = searchInput.value.trim().toLowerCase();
        filterComponents(query);
      });
    }

    // Click to insert
    if (componentList) {
      componentList.addEventListener('click', function (ev) {
        const btn = ev.target.closest('.kc-component');
        if (!btn) return;
        ev.preventDefault();
        const compId = btn.dataset.componentId;
        if (compId) insertFromCatalogId(compId);
      });

      // Drag to insert
      componentList.addEventListener('dragstart', function (ev) {
        const btn = ev.target.closest('.kc-component');
        if (!btn) return;
        const compId = btn.dataset.componentId;
        if (compId) {
          ev.dataTransfer.setData('text/plain', compId);
          ev.dataTransfer.setData('application/x-kc-component', compId);
          ev.dataTransfer.effectAllowed = 'copy';
        }
      });
    }

    // Ribbon insert buttons
    document.querySelectorAll('.kc-ribbon [data-insert]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const id = btn.dataset.insert;
        if (id) insertFromCatalogId(id);
      });
    });

    // Drop on canvas
    if (canvasEl) {
      canvasEl.addEventListener('dragover', function (ev) {
        ev.preventDefault();
        ev.dataTransfer.dropEffect = 'copy';
      });
      canvasEl.addEventListener('drop', function (ev) {
        const compId = ev.dataTransfer.getData('application/x-kc-component') || ev.dataTransfer.getData('text/plain');
        if (compId) {
          ev.preventDefault();
          insertFromCatalogId(compId);
        }
      });
    }
  }

  function filterComponents(query) {
    if (!componentList) return;
    const groups = componentList.querySelectorAll('.kc-component-group');
    groups.forEach(function (grp) {
      let groupHasMatch = false;
      const items = grp.querySelectorAll('.kc-component');
      items.forEach(function (item) {
        const id = (item.dataset.componentId || '').toLowerCase();
        const group = (item.dataset.group || '').toLowerCase();
        const keywords = (item.dataset.keywords || '').toLowerCase();
        const label = (item.querySelector('strong') ? item.querySelector('strong').textContent : '').toLowerCase();
        const desc = (item.querySelector('.kc-component-desc') ? item.querySelector('.kc-component-desc').textContent : '').toLowerCase();

        const matches = !query ||
          id.includes(query) ||
          group.includes(query) ||
          keywords.includes(query) ||
          label.includes(query) ||
          desc.includes(query);

        item.style.display = matches ? '' : 'none';
        if (matches) groupHasMatch = true;
      });
      grp.style.display = groupHasMatch ? '' : 'none';
    });
  }

  async function insertFromCatalogId(id) {
    if (!editor || !editorReady) return;
    const item = catalogItem(id);
    if (!item) {
      console.warn('[KC Editor] Catalog item not found:', id);
      return;
    }
    const editorType = item.editorType || item.type || id;
    const defaultData = item.data || item.preset || {};

    try {
      const currentIdx = typeof editor.blocks.getCurrentBlockIndex === 'function' ? editor.blocks.getCurrentBlockIndex() : lastSelectedIndex;
      const targetIdx = (currentIdx >= 0 && currentIdx < editor.blocks.getBlocksCount()) ? (currentIdx + 1) : editor.blocks.getBlocksCount();

      editor.blocks.insert(editorType, defaultData, {}, targetIdx, true);
      editor.caret.setToBlock(targetIdx, 'end');
      markDirty();
      refreshOutline();
    } catch (err) {
      console.error('[KC Editor] Block insertion failed:', err);
    }
  }

  // --- Document Outline ---
  async function refreshOutline() {
    if (!outlineEl || !editor || !editorReady) return;
    try {
      const data = await editor.save();
      const headings = (data.blocks || []).filter(function (b) {
        return b.type === 'header' || b.type === 'heading';
      });

      if (headings.length === 0) {
        outlineEl.innerHTML = '<div class="kc-empty-hint">Headings added to the document will appear here.</div>';
        return;
      }

      outlineEl.innerHTML = '';
      headings.forEach(function (h, idx) {
        const level = (h.data && h.data.level) || 2;
        const text = (h.data && h.data.text) ? h.data.text.replace(/<[^>]+>/g, '').trim() : 'Untitled heading';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'kc-outline-item kc-outline-h' + level;
        btn.textContent = text || 'Untitled heading';
        btn.addEventListener('click', function () {
          scrollToHeading(idx);
        });
        outlineEl.appendChild(btn);
      });
    } catch (e) {}
  }

  function scrollToHeading(headingIndex) {
    if (!holder) return;
    const headers = holder.querySelectorAll('.ce-header');
    if (headers[headingIndex]) {
      headers[headingIndex].scrollIntoView({ behavior: 'smooth', block: 'center' });
      headers[headingIndex].focus();
    }
  }

  // --- Whitespace Canvas Click-to-Focus ---
  function bindCanvasClickToFocus() {
    const targets = [canvasEl, stageEl, surfaceEl].filter(Boolean);
    targets.forEach(function (el) {
      el.addEventListener('click', function (ev) {
        if (!editor || !editorReady) return;
        if (ev.target.closest('#kc-editorjs, .kc-modal, .kc-ribbon, .kc-left-pane, .kc-right-pane, .kc-workspace-head, .kc-statusbar, .kc-block-chrome')) {
          return;
        }
        try {
          const count = editor.blocks.getBlocksCount();
          if (count > 0) {
            editor.caret.setToBlock(count - 1, 'end');
          } else {
            editor.caret.focus(true);
          }
        } catch (e) {}
      });
    });
  }

  // --- Inspector Block Synchronization ---
  function updateBlockInspector(blockIndex) {
    if (!blockFields || !selectedMeta || !editor || !editorReady) return;
    try {
      const count = editor.blocks.getBlocksCount();
      if (blockIndex < 0 || blockIndex >= count) {
        selectedMeta.textContent = 'Select a block on the canvas to configure settings.';
        blockFields.innerHTML = '';
        return;
      }
      const block = editor.blocks.getBlockByIndex(blockIndex);
      if (!block) return;
      const type = block.name || '';
      const item = catalogItem(type);
      const label = item ? item.label : (type.charAt(0).toUpperCase() + type.slice(1));

      selectedMeta.innerHTML = '<strong>' + escapeHtml(label) + ' block</strong> (position ' + (blockIndex + 1) + ' of ' + count + ')';

      // Build contextual field controls where useful
      blockFields.innerHTML = '';
      if (type === 'callout') {
        const holderEl = block.holder;
        const select = holderEl ? holderEl.querySelector('.kc-tool-tone') : null;
        if (select) {
          const field = document.createElement('div');
          field.className = 'kc-field';
          field.innerHTML = '<label>Callout tone</label>';
          const toneSelect = document.createElement('select');
          toneSelect.innerHTML = select.innerHTML;
          toneSelect.value = select.value;
          toneSelect.addEventListener('change', function () {
            select.value = toneSelect.value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
          });
          field.appendChild(toneSelect);
          blockFields.appendChild(field);
        }
      }
    } catch (e) {}
  }

  // --- Ribbon Style Selector & Inline Tools ---
  function initRibbon() {
    // Style select (Paragraph, H2, H3, H4)
    const blockStyleSelect = document.getElementById('kc-block-style');
    if (blockStyleSelect) {
      blockStyleSelect.addEventListener('change', async function () {
        if (!editor || !editorReady) return;
        const val = blockStyleSelect.value;
        const idx = typeof editor.blocks.getCurrentBlockIndex === 'function' ? editor.blocks.getCurrentBlockIndex() : lastSelectedIndex;
        if (idx < 0) return;

        try {
          const block = editor.blocks.getBlockByIndex(idx);
          if (!block) return;
          const currentData = await block.save();
          const currentText = currentData.data ? (currentData.data.text || '') : '';

          if (val === 'paragraph') {
            editor.blocks.insert('paragraph', { text: currentText }, {}, idx, true);
            editor.blocks.delete(idx + 1);
          } else if (val.startsWith('h')) {
            const level = Number(val.replace('h', '')) || 2;
            editor.blocks.insert('header', { text: currentText, level: level }, {}, idx, true);
            editor.blocks.delete(idx + 1);
          }
          editor.caret.setToBlock(idx, 'end');
          markDirty();
        } catch (e) {
          console.error('[KC Editor] Style switch failed:', e);
        }
      });
    }

    // Ribbon tabs (Home / Insert)
    document.querySelectorAll('.kc-ribbon-tab[data-ribbon-tab]').forEach(function (tab) {
      tab.addEventListener('click', function () {
        const target = tab.dataset.ribbonTab;
        document.querySelectorAll('.kc-ribbon-tab').forEach(function (t) {
          t.classList.toggle('is-active', t === tab);
        });
        document.querySelectorAll('.kc-ribbon-row[data-ribbon-panel]').forEach(function (row) {
          row.hidden = row.dataset.ribbonPanel !== target;
        });
      });
    });

    // Inline formatting commands
    document.querySelectorAll('.kc-tool[data-cmd]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const cmd = btn.dataset.cmd;
        if (cmd === 'undo') document.execCommand('undo');
        if (cmd === 'redo') document.execCommand('redo');
      });
    });

    document.querySelectorAll('.kc-tool[data-inline]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const inline = btn.dataset.inline;
        if (inline === 'bold') document.execCommand('bold');
        else if (inline === 'italic') document.execCommand('italic');
        else if (inline === 'underline') document.execCommand('underline');
        else if (inline === 'strike') document.execCommand('strikeThrough');
      });
    });
  }

  // --- Word and Character Counts ---
  let countTimer = null;
  function refreshCountsSoon() {
    clearTimeout(countTimer);
    countTimer = setTimeout(refreshCounts, 400);
  }

  async function refreshCounts() {
    if (!editor || !editorReady) return;
    try {
      const data = await editor.save();
      let text = '';
      (data.blocks || []).forEach(function (b) {
        if (b.data && b.data.text) text += ' ' + b.data.text.replace(/<[^>]+>/g, ' ');
      });
      text = text.trim();
      const chars = text.length;
      const words = text ? text.split(/\s+/).length : 0;
      if (wordCountEl) wordCountEl.textContent = words + ' words';
      if (charCountEl) charCountEl.textContent = chars + ' characters';
    } catch (e) {}
  }

  // --- Legacy Mode View Switcher (1.0.9) ---
  function initLegacyViewSwitcher() {
    const tabPreview = document.getElementById('kc-legacy-tab-preview');
    const tabEdit = document.getElementById('kc-legacy-tab-edit');
    const previewContainer = document.getElementById('kc-legacy-preview-container');
    const editorContainer = document.getElementById('kc-legacy-editor-container');
    const legacyArea = document.getElementById('kc-legacy-html');

    if (tabPreview && tabEdit && previewContainer && editorContainer) {
      tabPreview.addEventListener('click', function () {
        tabPreview.classList.add('is-active');
        tabEdit.classList.remove('is-active');
        previewContainer.hidden = false;
        editorContainer.hidden = true;
        if (legacyArea) previewContainer.innerHTML = legacyArea.value;
      });

      tabEdit.addEventListener('click', function () {
        tabEdit.classList.add('is-active');
        tabPreview.classList.remove('is-active');
        previewContainer.hidden = true;
        editorContainer.hidden = false;
        if (legacyArea) legacyArea.focus();
      });

      if (legacyArea) {
        legacyArea.addEventListener('input', function () {
          markDirty();
        });
      }
    }
  }

  // --- Keyboard Shortcuts ---
  function initKeybindings() {
    window.addEventListener('keydown', function (ev) {
      if ((ev.ctrlKey || ev.metaKey) && ev.key === 's') {
        ev.preventDefault();
        save(false);
      }
    });
  }

  function escapeHtml(str) {
    return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // --- Main Editor.js Initialization ---
  async function bootStructuredEditor() {
    if (!window.EditorJS) {
      showBootError('EditorJS vendor library failed to load.');
      return;
    }

    const custom = (window.KcEditorRegistry && typeof window.KcEditorRegistry.getTools === 'function') ? window.KcEditorRegistry.getTools() : {};

    const tools = {
      header: {
        class: window.Header || custom.header,
        inlineToolbar: ['italic', 'underline', 'link'],
        config: { levels: [2, 3, 4, 5, 6], defaultLevel: 2 }
      },
      list: {
        class: window.EditorjsList || custom.list,
        inlineToolbar: true
      },
      quote: {
        class: window.Quote || custom.quote,
        inlineToolbar: true
      },
      delimiter: window.Delimiter || custom.delimiter,
      table: {
        class: window.Table || custom.table,
        inlineToolbar: true
      },
      underline: window.Underline || custom.underline,
      inlineCode: window.InlineCode || custom.inlineCode,
      callout: { class: window.KcCallout || custom.callout },
      linkCard: { class: window.KcLink || custom.linkCard || custom.link },
      code: { class: window.KcCode || custom.code },
      steps: { class: window.KcSteps || custom.steps },
      accordion: { class: window.KcAccordion || custom.accordion },
      faq: { class: window.KcFaq || custom.faq },
      tabs: { class: window.KcTabs || custom.tabs },
      codeGroup: { class: window.KcCodeGroup || custom.codeGroup },
      definitionList: { class: window.KcDefinitionList || custom.definitionList },
      statusBadge: { class: window.KcStatusBadge || custom.statusBadge },
      group: { class: window.KcGroup || custom.group },
      columns: { class: window.KcColumns || custom.columns },
      cards: { class: window.KcCards || custom.cards },
      image: { class: window.KcImage || custom.image },
      file: { class: window.KcFile || custom.file },
      legacy: { class: window.KcLegacy || custom.legacy }
    };

    builtToolsResult = { tools };

    try {
      editor = new window.EditorJS({
        holder: 'kc-editorjs',
        tools: tools,
        data: cfg.initialData || { time: Date.now(), blocks: [], version: '2.30.8' },
        placeholder: 'Start writing… Type / to insert a block',
        autofocus: false,
        onChange: function () {
          if (!suppressChange) {
            markDirty();
            refreshOutline();
          }
        },
        onReady: function () {
          editorReady = true;
          root.dataset.editorState = 'ready';
          updateDiagnostics();
          refreshOutline();
          refreshCounts();
          bindCanvasClickToFocus();

          // Monitor block selection
          if (holder) {
            holder.addEventListener('focusin', function (ev) {
              const blockEl = ev.target.closest('.ce-block');
              if (blockEl && editor && typeof editor.blocks.getCurrentBlockIndex === 'function') {
                const idx = editor.blocks.getCurrentBlockIndex();
                lastSelectedIndex = idx;
                updateBlockInspector(idx);
              }
            });
          }
        }
      });

      await editor.isReady;
      editorReady = true;
      root.dataset.editorState = 'ready';
      updateDiagnostics();
    } catch (err) {
      showBootError('Failed to initialize editor: ' + (err.message || String(err)));
    }
  }

  // --- Bootstrap Execution ---
  document.addEventListener('DOMContentLoaded', function () {
    initPanels();
    initLeftTabs();
    initComponentLibrary();
    initRibbon();
    initKeybindings();
    initLegacyViewSwitcher();

    // Form submit handler
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      save(false);
    });

    if (titleInput) {
      titleInput.addEventListener('input', function () {
        markDirty();
      });
    }
    if (slugInput) {
      slugInput.addEventListener('input', function () {
        markDirty();
      });
    }
    if (statusSelect) {
      statusSelect.addEventListener('change', function () {
        markDirty();
      });
    }

    if (cfg.mode === 'structured') {
      bootStructuredEditor();
    } else {
      root.dataset.editorState = 'ready';
      editorReady = true;
      updateDiagnostics();
    }
  });

  // If DOM is already parsed
  if (document.readyState === 'interactive' || document.readyState === 'complete') {
    initPanels();
    initLeftTabs();
    initComponentLibrary();
    initRibbon();
    initKeybindings();
    initLegacyViewSwitcher();

    if (cfg.mode === 'structured' && !editor) {
      bootStructuredEditor();
    }
  }
})();
