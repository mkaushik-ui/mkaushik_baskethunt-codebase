/**
 * SOI Knowledge Center — Complete Enterprise Authoring Suite Engine (1.1.0).
 * Editor.js is the core block runtime; this engine coordinates workspace layout,
 * ribbon tabs, multi-tab sidebars, command palette, revisions, templates, patterns,
 * reusable blocks, local recovery, optimistic concurrency, and diagnostics.
 */
(function () {
  'use strict';

  // Ensure fullscreen authoring shell classes are active immediately on document.body
  document.body.classList.add('kc-authoring');
  document.body.classList.add('kc-authoring-fullscreen');

  const runtimeErrors = [];

  let cfg = window.SOI_EDITOR_CFG || null;
  if (!cfg) {
    const cfgNode = document.getElementById('kc-editor-config');
    if (cfgNode) {
      try {
        cfg = JSON.parse(cfgNode.textContent);
      } catch (err) {
        cfg = null;
      }
    }
  }

  if (!cfg) {
    console.error('[KC Editor] Missing editor configuration (window.SOI_EDITOR_CFG or #kc-editor-config).');
    return;
  }

  const root = document.getElementById('kc-editor');
  if (!root) return;

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
  const revisionsModal = document.getElementById('kc-revisions-modal');
  const revisionsList = document.getElementById('kc-revisions-list');
  const revDiffView = document.getElementById('kc-rev-diff-view');
  const restoreRevBtn = document.getElementById('kc-restore-rev-btn');
  const revSelectedTitle = document.getElementById('kc-rev-selected-title');
  const templatesModal = document.getElementById('kc-templates-modal');
  const paletteModal = document.getElementById('kc-palette-modal');
  const paletteInput = document.getElementById('kc-palette-input');
  const paletteResults = document.getElementById('kc-palette-results');
  const conflictModal = document.getElementById('kc-conflict-modal');
  const documentField = document.getElementById('kc-document-json');
  const updatedField = document.getElementById('kc-updated-at');
  const idField = document.getElementById('kc-doc-id');
  const modeField = document.getElementById('kc-editor-mode');
  const componentList = document.getElementById('kc-component-list');
  const emptyState = document.getElementById('kc-empty-state');
  const wordCountEl = document.getElementById('kc-word-count');
  const charCountEl = document.getElementById('kc-char-count');
  const readTimeEl = document.getElementById('kc-read-time');
  const selectedBlockTypeEl = document.getElementById('kc-selected-block-type');
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
  let selectedRevisionId = null;
  let paletteMatches = [];
  let paletteIndex = 0;

  function updateDiagnostics() {
    window.__KC_EDITOR_DIAGNOSTICS = {
      release: '1.1.0',
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
    console.error('[KC Editor 1.1.0]', message);
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
    saveToLocalRecovery();
    scheduleAutosave();
    refreshCountsSoon();
  }

  function scheduleAutosave() {
    if (!cfg.autosave || !Number(idField && idField.value)) return;
    clearTimeout(saveTimer);
    saveTimer = setTimeout(function () { save(true); }, 2500);
  }

  // --- Local Recovery Buffer ---
  function getDocStorageKey() {
    const id = idField ? idField.value : 'new';
    return 'kc_recovery_draft_' + (cfg.entity || 'page') + '_' + id;
  }

  async function saveToLocalRecovery() {
    if (!editor || !editorReady) return;
    try {
      const data = await editor.save();
      const payload = {
        title: titleInput ? titleInput.value : '',
        slug: slugInput ? slugInput.value : '',
        data: data,
        timestamp: Date.now()
      };
      localStorage.setItem(getDocStorageKey(), JSON.stringify(payload));
    } catch (e) {
      // ignore storage quota errors
    }
  }

  function clearLocalRecovery() {
    try {
      localStorage.removeItem(getDocStorageKey());
    } catch (e) {}
  }

  // --- Panel State Management ---
  function setPane(side, open, savePref) {
    root.dataset[side] = open ? 'open' : 'closed';
    if (side === 'left') {
      if (toggleLeftBtn) toggleLeftBtn.setAttribute('aria-pressed', open ? 'true' : 'false');
      if (reopenLeftBtn) reopenLeftBtn.hidden = open;
      if (savePref !== false) {
        try { localStorage.setItem('kc_pane_left', open ? 'open' : 'closed'); } catch (e) {}
      }
    } else if (side === 'right') {
      if (toggleRightBtn) toggleRightBtn.setAttribute('aria-pressed', open ? 'true' : 'false');
      if (reopenRightBtn) reopenRightBtn.hidden = open;
      if (savePref !== false) {
        try { localStorage.setItem('kc_pane_right', open ? 'open' : 'closed'); } catch (e) {}
      }
    }
  }

  function initResizers() {
    let leftResizer = document.getElementById('kc-resizer-left');
    let rightResizer = document.getElementById('kc-resizer-right');

    const leftPane = document.getElementById('kc-left-pane');
    if (!leftResizer && leftPane) {
      leftResizer = document.createElement('div');
      leftResizer.id = 'kc-resizer-left';
      leftResizer.className = 'kc-resizer kc-resizer-left';
      leftResizer.title = 'Drag to resize left panel';
      leftResizer.setAttribute('role', 'separator');
      leftPane.insertAdjacentElement('afterend', leftResizer);
    }

    const rightPane = document.getElementById('kc-right-pane');
    if (!rightResizer && rightPane) {
      rightResizer = document.createElement('div');
      rightResizer.id = 'kc-resizer-right';
      rightResizer.className = 'kc-resizer kc-resizer-right';
      rightResizer.title = 'Drag to resize right panel';
      rightResizer.setAttribute('role', 'separator');
      rightPane.insertAdjacentElement('beforebegin', rightResizer);
    }

    let savedLeftW = 256;
    let savedRightW = 280;
    try {
      const l = parseInt(localStorage.getItem('kc_pane_left_w'), 10);
      if (l >= 180 && l <= 600) savedLeftW = l;
      const r = parseInt(localStorage.getItem('kc_pane_right_w'), 10);
      if (r >= 200 && r <= 600) savedRightW = r;
    } catch (e) {}

    root.style.setProperty('--kc-left-w', savedLeftW + 'px');
    root.style.setProperty('--kc-right-w', savedRightW + 'px');

    if (leftResizer) {
      bindResizer(leftResizer, 'left', savedLeftW);
    }
    if (rightResizer) {
      bindResizer(rightResizer, 'right', savedRightW);
    }
  }

  function bindResizer(el, side, initialW) {
    let currentW = initialW;

    const onStart = function (e) {
      e.preventDefault();
      const startX = e.clientX || (e.touches && e.touches[0] ? e.touches[0].clientX : 0);
      const startW = currentW;

      el.classList.add('is-dragging');
      document.body.classList.add('kc-resizing');

      const onMove = function (ev) {
        const currentX = ev.clientX || (ev.touches && ev.touches[0] ? ev.touches[0].clientX : startX);
        const delta = currentX - startX;
        const maxW = Math.min(550, Math.floor(window.innerWidth * 0.45));
        const minW = side === 'left' ? 180 : 200;

        let newW;
        if (side === 'left') {
          newW = Math.max(minW, Math.min(maxW, startW + delta));
          root.style.setProperty('--kc-left-w', newW + 'px');
        } else {
          newW = Math.max(minW, Math.min(maxW, startW - delta));
          root.style.setProperty('--kc-right-w', newW + 'px');
        }
        currentW = newW;
      };

      const onEnd = function () {
        window.removeEventListener('mousemove', onMove);
        window.removeEventListener('mouseup', onEnd);
        window.removeEventListener('touchmove', onMove);
        window.removeEventListener('touchend', onEnd);
        window.removeEventListener('pointermove', onMove);
        window.removeEventListener('pointerup', onEnd);
        el.classList.remove('is-dragging');
        document.body.classList.remove('kc-resizing');
        try {
          localStorage.setItem(side === 'left' ? 'kc_pane_left_w' : 'kc_pane_right_w', currentW);
        } catch (err) {}
      };

      window.addEventListener('mousemove', onMove);
      window.addEventListener('mouseup', onEnd);
      window.addEventListener('touchmove', onMove);
      window.addEventListener('touchend', onEnd);
      window.addEventListener('pointermove', onMove);
      window.addEventListener('pointerup', onEnd);
    };

    el.addEventListener('mousedown', onStart);
    el.addEventListener('touchstart', onStart);
    el.addEventListener('pointerdown', onStart);
  }

  function initPanels() {
    let prefLeft = 'open';
    let prefRight = 'open';
    try {
      prefLeft = localStorage.getItem('kc_pane_left') || 'open';
      prefRight = localStorage.getItem('kc_pane_right') || 'open';
    } catch (e) {}

    setPane('left', prefLeft === 'open', false);
    setPane('right', prefRight === 'open', false);
    initResizers();

    if (toggleLeftBtn) {
      toggleLeftBtn.addEventListener('click', function () {
        setPane('left', root.dataset.left !== 'open');
      });
    }
    if (closeLeftBtn) {
      closeLeftBtn.addEventListener('click', function () { setPane('left', false); });
    }
    if (reopenLeftBtn) {
      reopenLeftBtn.addEventListener('click', function () { setPane('left', true); });
    }

    if (toggleRightBtn) {
      toggleRightBtn.addEventListener('click', function () {
        setPane('right', root.dataset.right !== 'open');
      });
    }
    if (closeRightBtn) {
      closeRightBtn.addEventListener('click', function () { setPane('right', false); });
    }
    if (reopenRightBtn) {
      reopenRightBtn.addEventListener('click', function () { setPane('right', true); });
    }
  }

  // --- Undo / Redo History Stack for EditorJS ---
  const undoStack = [];
  const redoStack = [];
  let isHistoryAction = false;
  let historyTimer = null;

  async function pushHistorySnapshot() {
    if (isHistoryAction || !editor || !editor.save) return;
    try {
      const data = await editor.save();
      const json = JSON.stringify(data);
      if (!undoStack.length || undoStack[undoStack.length - 1] !== json) {
        undoStack.push(json);
        if (undoStack.length > 50) undoStack.shift();
        redoStack.length = 0;
      }
    } catch (e) {}
  }

  function recordHistoryDebounced() {
    if (historyTimer) clearTimeout(historyTimer);
    historyTimer = setTimeout(pushHistorySnapshot, 250);
  }

  async function triggerUndo() {
    if (!editor || !editor.render || undoStack.length <= 1) return;
    try {
      const current = undoStack.pop();
      redoStack.push(current);
      const prevJson = undoStack[undoStack.length - 1];
      const prevData = JSON.parse(prevJson);
      isHistoryAction = true;
      await editor.render(prevData);
      isHistoryAction = false;
      markDirty();
      refreshOutline();
      updateDiagnostics();
    } catch (e) {
      isHistoryAction = false;
    }
  }

  async function triggerRedo() {
    if (!editor || !editor.render || !redoStack.length) return;
    try {
      const nextJson = redoStack.pop();
      undoStack.push(nextJson);
      const nextData = JSON.parse(nextJson);
      isHistoryAction = true;
      await editor.render(nextData);
      isHistoryAction = false;
      markDirty();
      refreshOutline();
      updateDiagnostics();
    } catch (e) {
      isHistoryAction = false;
    }
  }

  // --- Ribbon Toolbar Command Handlers ---
  function initRibbon() {
    // Undo / Redo & Commands
    document.querySelectorAll('.kc-tool[data-cmd]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        const cmd = btn.dataset.cmd;
        if (cmd === 'undo') {
          triggerUndo();
        } else if (cmd === 'redo') {
          triggerRedo();
        } else if (cmd === 'delete-block') {
          if (editor && editor.blocks && typeof lastSelectedIndex === 'number' && lastSelectedIndex >= 0) {
            editor.blocks.delete(lastSelectedIndex);
            lastSelectedIndex = Math.max(0, lastSelectedIndex - 1);
            markDirty();
            refreshOutline();
            recordHistoryDebounced();
          }
        }
      });
    });

    // Block style select (Heading level / Paragraph)
    const blockStyleSelect = document.getElementById('kc-block-style');
    if (blockStyleSelect) {
      blockStyleSelect.addEventListener('change', function () {
        const val = blockStyleSelect.value;
        if (val.startsWith('h')) {
          const level = parseInt(val.replace('h', ''), 10) || 2;
          insertBlockByType('heading', { level: level, text: '' });
        } else if (val === 'paragraph') {
          insertBlockByType('paragraph', { text: '' });
        }
      });
    }

    // Inline formatting tools
    document.querySelectorAll('.kc-tool[data-inline]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        const inlineType = btn.dataset.inline;
        if (inlineType === 'bold') {
          document.execCommand('bold');
        } else if (inlineType === 'italic') {
          document.execCommand('italic');
        } else if (inlineType === 'underline') {
          document.execCommand('underline');
        } else if (inlineType === 'strike') {
          document.execCommand('strikeThrough');
        } else if (inlineType === 'link') {
          const url = prompt('Enter link URL (https://...):', 'https://');
          if (url) document.execCommand('createLink', false, url);
        } else if (inlineType === 'inlineCode') {
          const sel = window.getSelection();
          if (sel && !sel.isCollapsed && sel.rangeCount > 0) {
            const range = sel.getRangeAt(0);
            const codeEl = document.createElement('code');
            codeEl.className = 'inline-code';
            codeEl.textContent = range.toString();
            range.deleteContents();
            range.insertNode(codeEl);
          }
        } else if (inlineType === 'highlight') {
          const sel = window.getSelection();
          if (sel && !sel.isCollapsed && sel.rangeCount > 0) {
            const range = sel.getRangeAt(0);
            const markEl = document.createElement('mark');
            markEl.className = 'cdx-highlight';
            markEl.textContent = range.toString();
            range.deleteContents();
            range.insertNode(markEl);
          }
        }
        markDirty();
        recordHistoryDebounced();
      });
    });

    // Text alignment tools
    document.querySelectorAll('.kc-tool[data-align]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        const align = btn.dataset.align;
        if (editor && editor.blocks && typeof lastSelectedIndex === 'number' && lastSelectedIndex >= 0) {
          const block = editor.blocks.getBlockByIndex(lastSelectedIndex);
          if (block && block.holder) {
            block.holder.style.textAlign = align;
          }
        }
        if (align === 'center') document.execCommand('justifyCenter');
        else if (align === 'right') document.execCommand('justifyRight');
        else document.execCommand('justifyLeft');
        markDirty();
        recordHistoryDebounced();
      });
    });

    // Callout tone selector
    const toneSelect = document.getElementById('kc-callout-tone-select');
    if (toneSelect) {
      toneSelect.addEventListener('change', function () {
        const tone = toneSelect.value;
        if (tone) {
          insertBlockByType('callout', { tone: tone, title: tone.toUpperCase(), text: '' });
          toneSelect.selectedIndex = 0;
        }
      });
    }

    // Table operations (+Col, +Row, -Col, -Row)
    document.querySelectorAll('.kc-tool[data-table-op]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        const op = btn.dataset.tableOp;
        if (editor && editor.blocks) {
          let block = (typeof lastSelectedIndex === 'number' && lastSelectedIndex >= 0) ? editor.blocks.getBlockByIndex(lastSelectedIndex) : null;
          if (!block || !block.holder || !block.holder.querySelector('.tc-table, .tc-wrap')) {
            const count = editor.blocks.getBlocksCount();
            for (let i = 0; i < count; i++) {
              const b = editor.blocks.getBlockByIndex(i);
              if (b && b.holder && b.holder.querySelector('.tc-table, .tc-wrap')) {
                block = b;
                lastSelectedIndex = i;
                break;
              }
            }
          }

          if (block && block.holder) {
            const addColBtn = block.holder.querySelector('.tc-add-column');
            const addRowBtn = block.holder.querySelector('.tc-add-row');
            if (op === 'add-col' && addColBtn) {
              addColBtn.click();
            } else if (op === 'add-row' && addRowBtn) {
              addRowBtn.click();
            } else if (op === 'del-col') {
              const rows = block.holder.querySelectorAll('.tc-row');
              if (rows.length && rows[0].querySelectorAll('.tc-cell').length > 1) {
                rows.forEach(function (r) {
                  const cells = r.querySelectorAll('.tc-cell');
                  if (cells.length > 1) cells[cells.length - 1].remove();
                });
              }
            } else if (op === 'del-row') {
              const rows = block.holder.querySelectorAll('.tc-row');
              if (rows.length > 1) {
                rows[rows.length - 1].remove();
              }
            }
          } else if (op === 'add-col' || op === 'add-row') {
            insertBlockByType('table');
          }
        }
        markDirty();
        recordHistoryDebounced();
      });
    });
  }

  // --- Multi-Tab Sidebars ---
  function switchLeftTab(tabName) {
    root.dataset.leftTab = tabName;
    const tabs = document.querySelectorAll('.kc-left-pane .kc-pane-tab');
    tabs.forEach(function (t) {
      const active = t.dataset.leftTab === tabName;
      t.classList.toggle('is-active', active);
      t.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    const panels = document.querySelectorAll('.kc-left-panel');
    panels.forEach(function (p) {
      p.hidden = (p.dataset.leftPanel !== tabName);
    });
  }

  function switchRightTab(tabName) {
    root.dataset.rightTab = tabName;
    const tabs = document.querySelectorAll('.kc-right-pane .kc-pane-tab');
    tabs.forEach(function (t) {
      const active = t.dataset.rightTab === tabName;
      t.classList.toggle('is-active', active);
      t.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    const panels = document.querySelectorAll('.kc-right-panel');
    panels.forEach(function (p) {
      p.hidden = (p.dataset.rightPanel !== tabName);
    });
  }

  function initSidebarTabs() {
    const leftTabs = document.querySelectorAll('.kc-left-pane .kc-pane-tab');
    leftTabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        switchLeftTab(tab.dataset.leftTab);
      });
    });

    const rightTabs = document.querySelectorAll('.kc-right-pane .kc-pane-tab');
    rightTabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        switchRightTab(tab.dataset.rightTab);
      });
    });
  }

  // --- Reusable Blocks Client Subsystem ---
  async function loadReusableSidebar() {
    const listEl = document.getElementById('kc-reusable-sidebar-list');
    if (!listEl) return;
    try {
      const res = await fetch(cfg.apiUrl + '?action=reusable_list', {
        headers: { 'X-CSRF-TOKEN': cfg.csrf }
      });
      const data = await res.json();
      if (data.ok && Array.isArray(data.reusable)) {
        if (!data.reusable.length) {
          listEl.innerHTML = '<div class="kc-empty-hint">No reusable blocks created yet. Select a block to save as reusable.</div>';
          return;
        }
        listEl.innerHTML = '';
        data.reusable.forEach(function (item) {
          const card = document.createElement('div');
          card.className = 'kc-reusable-card';
          card.innerHTML = '<div class="kc-reusable-card-head"><span class="kc-reusable-badge">#' + item.id + '</span> <strong>' + escapeHtml(item.title) + '</strong></div>'
            + '<div class="kc-reusable-card-actions">'
            + '<button type="button" class="kc-btn-mini kc-insert-reusable" data-id="' + item.id + '">+ Insert Ref</button>'
            + '<button type="button" class="kc-btn-mini kc-btn-mini-danger kc-del-reusable" data-id="' + item.id + '">✕</button>'
            + '</div>';
          listEl.appendChild(card);
        });

        listEl.querySelectorAll('.kc-insert-reusable').forEach(function (btn) {
          btn.addEventListener('click', function () {
            const rId = parseInt(btn.dataset.id, 10);
            insertBlockByType('reusable', { reusable_id: rId, title: '' });
          });
        });

        listEl.querySelectorAll('.kc-del-reusable').forEach(function (btn) {
          btn.addEventListener('click', async function () {
            if (!confirm('Delete this reusable block?')) return;
            const rId = parseInt(btn.dataset.id, 10);
            await fetch(cfg.apiUrl, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf },
              body: JSON.stringify({ _action: 'reusable_delete', id: rId, _csrf: cfg.csrf })
            });
            loadReusableSidebar();
          });
        });
      }
    } catch (e) {
      listEl.innerHTML = '<div class="kc-empty-hint">Failed to load reusable blocks.</div>';
    }
  }

  // --- Patterns & Templates Client Subsystem ---
  function initPatterns() {
    document.querySelectorAll('.kc-insert-pattern-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const patternId = btn.dataset.patternId;
        const pattern = (cfg.patterns || []).find(p => p.id === patternId);
        if (pattern && pattern.blocks) {
          insertPatternBlocks(pattern.blocks);
        }
      });
    });
  }

  function initTemplates() {
    const openTmplBtn = document.getElementById('kc-open-templates');
    if (openTmplBtn && templatesModal) {
      openTmplBtn.addEventListener('click', function () {
        templatesModal.hidden = false;
      });
    }

    document.querySelectorAll('.kc-apply-template-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const tmplId = btn.dataset.templateId;
        const tmpl = (cfg.templates || []).find(t => t.id === tmplId);
        if (tmpl && tmpl.document) {
          if (confirm('Apply template "' + tmpl.title + '"? This will replace current canvas blocks.')) {
            const editorJsData = convertDocToEditorJs(tmpl.document);
            if (editor && editor.render) {
              editor.render(editorJsData).then(function () {
                markDirty();
                templatesModal.hidden = true;
              });
            }
          }
        }
      });
    });
  }

  function convertDocToEditorJs(doc) {
    const blocks = [];
    (doc.blocks || []).forEach(function (b) {
      let type = b.type;
      if (type === 'heading') type = 'header';
      if (type === 'link') type = 'linkCard';
      blocks.push({
        id: b.id || Math.random().toString(36).substr(2, 9),
        type: type,
        data: b.data || {}
      });
    });
    return { time: Date.now(), blocks: blocks, version: '2.30.8' };
  }

  async function insertPatternBlocks(blocks) {
    if (!editor || !editor.blocks) return;
    for (const b of blocks) {
      let toolName = b.type;
      if (toolName === 'heading') toolName = 'header';
      if (toolName === 'link') toolName = 'linkCard';
      await editor.blocks.insert(toolName, b.data || {}, {}, undefined, true);
    }
    markDirty();
  }

  // --- Revisions Subsystem ---
  async function openRevisionsModal() {
    if (!revisionsModal) return;
    revisionsModal.hidden = false;
    revisionsList.innerHTML = '<div class="kc-empty-hint">Loading revision history…</div>';
    revDiffView.innerHTML = '<p class="kc-empty-hint">Select a revision from the left to inspect.</p>';
    if (restoreRevBtn) restoreRevBtn.disabled = true;
    selectedRevisionId = null;

    const docId = Number(idField ? idField.value : 0);
    if (!docId) {
      revisionsList.innerHTML = '<div class="kc-empty-hint">Save this document first to start tracking revisions.</div>';
      return;
    }

    try {
      const res = await fetch(cfg.apiUrl + '?action=history&entity=' + encodeURIComponent(cfg.entity) + '&id=' + docId, {
        headers: { 'X-CSRF-TOKEN': cfg.csrf }
      });
      const json = await res.json();
      if (json.ok && Array.isArray(json.revisions)) {
        if (!json.revisions.length) {
          revisionsList.innerHTML = '<div class="kc-empty-hint">No revisions recorded yet.</div>';
          return;
        }
        revisionsList.innerHTML = '';
        json.revisions.forEach(function (rev) {
          const item = document.createElement('div');
          item.className = 'kc-rev-item';
          item.dataset.revId = String(rev.id);
          item.innerHTML = '<div class="kc-rev-num">Revision #' + rev.revision_num + '</div>'
            + '<div class="kc-rev-meta">' + escapeHtml(rev.user_name || 'Author') + ' · ' + rev.created_at + '</div>'
            + '<div class="kc-rev-size">' + Math.round((rev.content_bytes || 0) / 1024) + ' KB</div>';
          item.addEventListener('click', function () {
            document.querySelectorAll('.kc-rev-item').forEach(i => i.classList.remove('is-active'));
            item.classList.add('is-active');
            inspectRevision(rev.id, rev.revision_num);
          });
          revisionsList.appendChild(item);
        });
      }
    } catch (err) {
      revisionsList.innerHTML = '<div class="kc-empty-hint">Failed to load revisions.</div>';
    }
  }

  async function inspectRevision(revId, revNum) {
    selectedRevisionId = revId;
    if (revSelectedTitle) revSelectedTitle.textContent = 'Revision #' + revNum;
    if (restoreRevBtn) restoreRevBtn.disabled = false;
    revDiffView.innerHTML = '<p class="kc-empty-hint">Loading revision snapshot…</p>';

    try {
      const res = await fetch(cfg.apiUrl + '?action=get_revision&revision_id=' + revId, {
        headers: { 'X-CSRF-TOKEN': cfg.csrf }
      });
      const json = await res.json();
      if (json.ok && json.revision) {
        const rev = json.revision;
        let contentHtml = '';
        if (json.editorJsData && json.editorJsData.blocks) {
          contentHtml = '<div class="kc-rev-blocks-summary">';
          json.editorJsData.blocks.forEach(function (b, i) {
            contentHtml += '<div class="kc-rev-block-row"><strong>' + (i + 1) + '. ' + escapeHtml(b.type) + '</strong>: ' + escapeHtml(JSON.stringify(b.data || {}).substring(0, 120)) + '</div>';
          });
          contentHtml += '</div>';
        } else if (rev.content) {
          contentHtml = '<pre class="kc-rev-raw-html"><code>' + escapeHtml(rev.content) + '</code></pre>';
        }
        revDiffView.innerHTML = '<div class="kc-rev-details">'
          + '<div class="kc-rev-meta-bar">Title: <strong>' + escapeHtml(rev.title) + '</strong> | Status: ' + escapeHtml(rev.status) + ' | Date: ' + rev.created_at + '</div>'
          + contentHtml
          + '</div>';
      }
    } catch (err) {
      revDiffView.innerHTML = '<p class="kc-empty-hint">Failed to load revision details.</p>';
    }
  }

  async function restoreSelectedRevision() {
    if (!selectedRevisionId) return;
    const docId = Number(idField ? idField.value : 0);
    if (!confirm('Restore this revision? Your current state will be saved as a new revision before restoring.')) return;

    try {
      const res = await fetch(cfg.apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf },
        body: JSON.stringify({
          _action: 'restore_revision',
          entity: cfg.entity,
          id: docId,
          revision_id: selectedRevisionId,
          _csrf: cfg.csrf
        })
      });
      const json = await res.json();
      if (json.ok) {
        if (titleInput && json.title) titleInput.value = json.title;
        if (statusSelect && json.status) statusSelect.value = json.status;
        if (updatedField && json.updated_at) updatedField.value = json.updated_at;

        if (json.editorJsData && editor && editor.render) {
          await editor.render(json.editorJsData);
        }
        setStatus('Restored revision #' + json.restored_revision, 'saved');
        revisionsModal.hidden = true;
        clearLocalRecovery();
      } else {
        alert('Restore failed: ' + (json.error || 'Unknown error'));
      }
    } catch (err) {
      alert('Restore failed: network error');
    }
  }

  // --- Command Palette Subsystem (Ctrl/Cmd+K) ---
  function getPaletteCommands() {
    const cmds = [
      { id: 'save', title: 'Save Document', icon: '💾', action: () => save(false) },
      { id: 'preview', title: 'Preview Document', icon: '👁', action: openPreview },
      { id: 'revisions', title: 'Revision History', icon: '🕒', action: openRevisionsModal },
      { id: 'templates', title: 'Starter Templates', icon: '📋', action: () => { if (templatesModal) templatesModal.hidden = false; } },
      { id: 'toggle-left', title: 'Toggle Left Components / Navigator', icon: '▦', action: () => setPane('left', root.dataset.left !== 'open') },
      { id: 'toggle-right', title: 'Toggle Right Inspector / Settings', icon: '⚙', action: () => setPane('right', root.dataset.right !== 'open') }
    ];

    catalog().forEach(function (item) {
      cmds.push({
        id: 'insert-' + item.id,
        title: 'Insert ' + item.label,
        icon: item.icon || '➕',
        desc: item.description,
        action: () => insertBlockByType(item.id)
      });
    });
    return cmds;
  }

  function openPalette() {
    if (!paletteModal) return;
    paletteModal.hidden = false;
    if (paletteInput) {
      paletteInput.value = '';
      paletteInput.focus();
    }
    renderPaletteResults('');
  }

  function closePalette() {
    if (paletteModal) paletteModal.hidden = true;
  }

  function renderPaletteResults(query) {
    if (!paletteResults) return;
    const q = (query || '').toLowerCase().trim();
    const all = getPaletteCommands();
    paletteMatches = all.filter(function (cmd) {
      if (!q) return true;
      return cmd.title.toLowerCase().includes(q) || (cmd.desc && cmd.desc.toLowerCase().includes(q));
    });
    paletteIndex = 0;

    paletteResults.innerHTML = '';
    if (!paletteMatches.length) {
      paletteResults.innerHTML = '<div class="kc-empty-hint">No matching commands found.</div>';
      return;
    }

    paletteMatches.forEach(function (cmd, idx) {
      const row = document.createElement('div');
      row.className = 'kc-palette-item' + (idx === 0 ? ' is-active' : '');
      row.innerHTML = '<span class="kc-palette-item-ico">' + escapeHtml(cmd.icon) + '</span>'
        + '<div class="kc-palette-item-info"><strong>' + escapeHtml(cmd.title) + '</strong>'
        + (cmd.desc ? ' <span>' + escapeHtml(cmd.desc) + '</span>' : '') + '</div>';
      row.addEventListener('click', function () {
        closePalette();
        cmd.action();
      });
      paletteResults.appendChild(row);
    });
  }

  function selectPaletteDelta(delta) {
    if (!paletteMatches.length) return;
    paletteIndex = (paletteIndex + delta + paletteMatches.length) % paletteMatches.length;
    const items = paletteResults.querySelectorAll('.kc-palette-item');
    items.forEach((it, idx) => it.classList.toggle('is-active', idx === paletteIndex));
  }

  // --- Dynamic Block Inspector ---
  function updateInspector(blockIndex) {
    if (!editor || !editor.blocks) return;
    try {
      const block = editor.blocks.getBlockByIndex(blockIndex);
      if (!block) {
        if (selectedMeta) selectedMeta.textContent = 'Select a block on the canvas to configure properties.';
        if (blockFields) blockFields.innerHTML = '';
        if (selectedBlockTypeEl) selectedBlockTypeEl.textContent = 'No block selected';
        return;
      }

      const blockType = block.name || 'block';
      if (selectedBlockTypeEl) selectedBlockTypeEl.textContent = 'Selected: ' + blockType;
      if (selectedMeta) selectedMeta.textContent = 'Block: ' + blockType.toUpperCase();

      if (!blockFields) return;
      blockFields.innerHTML = '';

      if (blockType === 'header' || blockType === 'heading') {
        const wrap = document.createElement('div');
        wrap.className = 'kc-field';
        wrap.innerHTML = '<label>Heading Level</label>'
          + '<select class="kc-tool-select" id="kc-insp-hlevel">'
          + '<option value="2">Heading 2 (H2)</option>'
          + '<option value="3">Heading 3 (H3)</option>'
          + '<option value="4">Heading 4 (H4)</option>'
          + '<option value="5">Heading 5 (H5)</option>'
          + '<option value="6">Heading 6 (H6)</option>'
          + '</select>';
        blockFields.appendChild(wrap);
      } else if (blockType === 'callout') {
        const wrap = document.createElement('div');
        wrap.className = 'kc-field';
        wrap.innerHTML = '<label>Callout Tone</label>'
          + '<select class="kc-tool-select" id="kc-insp-callout-tone">'
          + '<option value="info">Info (Blue)</option>'
          + '<option value="note">Note (Slate)</option>'
          + '<option value="tip">Tip (Emerald)</option>'
          + '<option value="warning">Warning (Amber)</option>'
          + '<option value="danger">Danger (Red)</option>'
          + '<option value="success">Success (Green)</option>'
          + '</select>';
        blockFields.appendChild(wrap);
      } else if (blockType === 'columns') {
        const wrap = document.createElement('div');
        wrap.className = 'kc-field';
        wrap.innerHTML = '<label>Layout Ratio</label>'
          + '<select class="kc-tool-select" id="kc-insp-col-layout">'
          + '<option value="50-50">50 / 50 (Two Equal Columns)</option>'
          + '<option value="33-67">33 / 67 (Narrow Left, Wide Right)</option>'
          + '<option value="67-33">67 / 33 (Wide Left, Narrow Right)</option>'
          + '<option value="33-33-33">33 / 33 / 33 (Three Columns)</option>'
          + '</select>';
        blockFields.appendChild(wrap);
      } else {
        blockFields.innerHTML = '<p class="kc-empty-hint">Standard block properties are managed directly on the canvas.</p>';
      }
    } catch (e) {}
  }

  // --- Reading Stats and Counts ---
  let countTimer = null;
  function refreshCountsSoon() {
    clearTimeout(countTimer);
    countTimer = setTimeout(refreshCounts, 300);
  }

  async function refreshCounts() {
    if (!editor || !editor.save) return;
    try {
      const data = await editor.save();
      let text = '';
      (data.blocks || []).forEach(function (b) {
        if (b.data) {
          if (b.data.text) text += ' ' + b.data.text;
          if (b.data.title) text += ' ' + b.data.title;
          if (b.data.content) text += ' ' + b.data.content;
          if (Array.isArray(b.data.items)) {
            b.data.items.forEach(it => {
              if (typeof it === 'string') text += ' ' + it;
              else if (it) text += ' ' + (it.title || it.content || it.question || it.answer || it.term || it.description || '');
            });
          }
        }
      });
      const plain = text.replace(/<[^>]+>/g, ' ').trim();
      const words = plain ? plain.split(/\s+/).length : 0;
      const chars = plain.length;
      const readMinutes = Math.max(1, Math.ceil(words / 200));

      if (wordCountEl) wordCountEl.textContent = words + (words === 1 ? ' word' : ' words');
      if (charCountEl) charCountEl.textContent = chars + (chars === 1 ? ' character' : ' characters');
      if (readTimeEl) readTimeEl.textContent = readMinutes + ' min read';
    } catch (e) {}
  }

  // --- Document Outline Generation ---
  async function refreshOutline() {
    if (!editor || !editor.save || !outlineEl) return;
    try {
      const data = await editor.save();
      const headings = [];
      (data.blocks || []).forEach(function (b, idx) {
        if ((b.type === 'header' || b.type === 'heading') && b.data && b.data.text) {
          const plain = b.data.text.replace(/<[^>]+>/g, '').trim();
          if (plain) {
            headings.push({ level: b.data.level || 2, text: plain, index: idx });
          }
        }
      });

      if (!headings.length) {
        outlineEl.innerHTML = '<div class="kc-empty-hint">Headings added to the document will appear here.</div>';
        return;
      }

      outlineEl.innerHTML = '';
      headings.forEach(function (h) {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'kc-outline-item kc-outline-h' + h.level;
        item.textContent = h.text;
        item.addEventListener('click', function () {
          if (editor && editor.blocks) {
            const block = editor.blocks.getBlockByIndex(h.index);
            if (block && block.holder) {
              block.holder.scrollIntoView({ behavior: 'smooth', block: 'center' });
              editor.caret.setToBlock(h.index, 'end');
            }
          }
        });
        outlineEl.appendChild(item);
      });
    } catch (e) {}
  }

  // --- Block Inserter Helper ---
  async function insertBlockByType(typeId, initialData) {
    if (!editor || !editor.blocks) return;
    let item = catalogItem(typeId);
    let editorType = (item && item.editorType) ? item.editorType : typeId;
    let data = initialData || (item && item.data ? Object.assign({}, item.data) : {});

    if (typeId === 'list-unordered' || typeId === 'list') {
      editorType = 'list';
      data = Object.assign({ style: 'unordered', meta: {}, items: [{ content: '', meta: {}, items: [] }] }, initialData || {});
    } else if (typeId === 'list-ordered') {
      editorType = 'list';
      data = Object.assign({ style: 'ordered', meta: { start: 1, counterType: 'numeric' }, items: [{ content: '', meta: {}, items: [] }] }, initialData || {});
    } else if (typeId === 'list-checklist' || typeId === 'checklist') {
      editorType = 'list';
      data = Object.assign({ style: 'checklist', meta: {}, items: [{ content: '', meta: { checked: false }, items: [] }] }, initialData || {});
    } else if (typeId === 'columns') {
      editorType = 'columns';
      data = Object.assign({ layout: '50-50', columns: [{ content: '' }, { content: '' }] }, initialData || {});
    } else if (typeId === 'grid') {
      editorType = 'grid';
      data = Object.assign({ columns: 2, gap: 'md', items: [{ title: '', content: '' }, { title: '', content: '' }] }, initialData || {});
    } else if (typeId === 'heading') {
      editorType = 'header';
      data = Object.assign({ text: '', level: 2 }, initialData || {});
    } else if (typeId === 'link') {
      editorType = 'linkCard';
    }

    const curIndex = (typeof lastSelectedIndex === 'number' && lastSelectedIndex >= 0) ? (lastSelectedIndex + 1) : editor.blocks.getBlocksCount();
    try {
      await editor.blocks.insert(editorType, data, {}, curIndex, true);
      lastSelectedIndex = curIndex;
      markDirty();
      refreshOutline();
      recordHistoryDebounced();
    } catch (e) {
      console.error('[KC Editor] Failed to insert block:', typeId, e);
    }
  }

  function updateDocumentMetaUI() {
    const statusVal = statusSelect ? statusSelect.value : 'draft';
    const statusChip = document.getElementById('kc-doc-status-chip');
    const statusBadge = document.getElementById('kc-card-status-badge');
    if (statusChip) {
      statusChip.textContent = statusVal.toUpperCase();
      statusChip.className = 'kc-card-badge is-' + statusVal;
      statusChip.setAttribute('data-status', statusVal);
    }
    if (statusBadge) {
      statusBadge.textContent = statusVal.toUpperCase();
      statusBadge.className = 'kc-badge-mode is-' + statusVal;
    }

    const slugVal = slugInput ? slugInput.value.trim() : '';
    const slugPreview = document.getElementById('kc-slug-preview-link');
    if (slugPreview) {
      slugPreview.textContent = '/docs/' + (slugVal || 'untitled');
    }
  }

  // --- Save Handler with Optimistic Concurrency ---
  async function save(isAutosave) {
    if (saving || !editor) return;
    saving = true;
    if (isAutosave) {
      setStatus('Autosaving…', 'saving');
    } else {
      setStatus('Saving…', 'saving');
    }

    try {
      const data = await editor.save();
      const excerptEl = document.getElementById('kc-doc-excerpt');
      const metaTitleEl = document.getElementById('kc-doc-meta-title') || document.querySelector('input[name="meta_title"]');
      const metaDescEl = document.getElementById('kc-doc-meta-desc') || document.querySelector('textarea[name="meta_desc"]');

      const payload = {
        _action: isAutosave ? 'autosave' : 'save',
        entity: cfg.entity,
        id: Number(idField ? idField.value : 0),
        mode: 'structured',
        title: titleInput ? titleInput.value.trim() : '',
        slug: slugInput ? slugInput.value.trim() : '',
        status: statusSelect ? statusSelect.value : 'draft',
        excerpt: excerptEl ? excerptEl.value.trim() : '',
        meta_title: metaTitleEl ? metaTitleEl.value.trim() : '',
        meta_desc: metaDescEl ? metaDescEl.value.trim() : '',
        expected_updated_at: updatedField ? updatedField.value : '',
        document: { schemaVersion: 1, blocks: data.blocks },
        _csrf: cfg.csrf
      };
      if (documentField) {
        documentField.value = JSON.stringify(payload.document);
      }

      const res = await fetch(cfg.apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf },
        body: JSON.stringify(payload)
      });
      const json = await res.json();

      if (json.ok) {
        dirty = false;
        clearLocalRecovery();
        if (idField && json.id) idField.value = json.id;
        if (updatedField && json.updated_at) updatedField.value = json.updated_at;
        if (slugInput && json.slug) slugInput.value = json.slug;
        if (statusSelect && json.status) statusSelect.value = json.status;
        updateDocumentMetaUI();
        if (barState) barState.textContent = json.status ? json.status.charAt(0).toUpperCase() + json.status.slice(1) : 'Draft';
        if (liveLink && json.view_url) {
          liveLink.href = json.view_url;
          liveLink.classList.remove('is-disabled');
          liveLink.removeAttribute('aria-disabled');
        }
        setStatus('Saved', 'saved');
      } else if (json.conflict && conflictModal) {
        setStatus('Edit Conflict', 'error');
        conflictModal.hidden = false;
      } else {
        setStatus('Save failed', 'error');
        alert('Save failed: ' + (json.error || 'Server error'));
      }
    } catch (err) {
      setStatus('Save error', 'error');
    } finally {
      saving = false;
    }
  }

  // --- Formatted Preview Modal ---
  async function openPreview() {
    if (!previewModal || !previewBody || !editor) return;
    previewModal.hidden = false;
    previewBody.innerHTML = '<div class="kc-empty-hint">Rendering document preview…</div>';
    try {
      const data = await editor.save();
      const res = await fetch(cfg.apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf },
        body: JSON.stringify({
          _action: 'preview',
          entity: cfg.entity,
          document: { schemaVersion: 1, blocks: data.blocks },
          _csrf: cfg.csrf
        })
      });
      const json = await res.json();
      if (json.ok && json.html) {
        previewBody.innerHTML = json.html;
      } else {
        previewBody.innerHTML = '<div class="kc-error">Failed to generate preview: ' + escapeHtml(json.error || 'Unknown error') + '</div>';
      }
    } catch (e) {
      previewBody.innerHTML = '<div class="kc-error">Preview network failure.</div>';
    }
  }

  // --- Build Editor.js Tools Map ---
  function buildTools() {
    const map = {};
    const reg = window.KcEditorRegistry;
    const getToolClass = function (type) {
      if (!reg) return undefined;
      if (typeof reg.get === 'function') return reg.get(type);
      if (typeof reg.getTools === 'function') return (reg.getTools() || {})[type];
      return undefined;
    };

    const headerCls = window.Header || getToolClass('heading');
    if (headerCls) {
      map.header = {
        class: headerCls,
        inlineToolbar: true,
        config: { placeholder: 'Heading text…', levels: [2, 3, 4, 5, 6], defaultLevel: 2 }
      };
    }

    const listCls = window.EditorjsList || window.List || getToolClass('list');
    if (listCls) {
      map.list = {
        class: listCls,
        inlineToolbar: true,
        config: { defaultStyle: 'unordered' }
      };
    }

    const quoteCls = window.Quote || getToolClass('quote');
    if (quoteCls) {
      map.quote = {
        class: quoteCls,
        inlineToolbar: true,
        config: { quotePlaceholder: 'Enter quote…', captionPlaceholder: 'Author / citation…' }
      };
    }

    const delimCls = window.Delimiter || getToolClass('divider');
    if (delimCls) {
      map.delimiter = { class: delimCls };
    }

    const tableCls = window.Table || getToolClass('table');
    if (tableCls) {
      map.table = {
        class: tableCls,
        inlineToolbar: true,
        config: { rows: 3, cols: 3 }
      };
    }

    const underCls = window.Underline || getToolClass('underline');
    if (underCls) {
      map.underline = { class: underCls };
    }

    const codeInlCls = window.InlineCode || getToolClass('inlineCode');
    if (codeInlCls) {
      map.inlineCode = { class: codeInlCls };
    }

    if (window.KcCallout) map.callout = { class: window.KcCallout, inlineToolbar: true };
    if (window.KcLink) map.linkCard = { class: window.KcLink };
    if (window.KcCode) map.code = { class: window.KcCode };
    if (window.KcImage) map.image = { class: window.KcImage, config: { openMedia: openMediaLibrary } };
    if (window.KcFile) map.file = { class: window.KcFile, config: { openMedia: openMediaLibrary } };
    if (window.KcLegacy) map.legacy = { class: window.KcLegacy };

    // Enterprise Tools
    if (window.KcSteps) map.steps = { class: window.KcSteps };
    if (window.KcAccordion) map.accordion = { class: window.KcAccordion };
    if (window.KcFaq) map.faq = { class: window.KcFaq };
    if (window.KcTabs) map.tabs = { class: window.KcTabs };
    if (window.KcCodeGroup) map.codeGroup = { class: window.KcCodeGroup };
    if (window.KcDefinitionList) map.definitionList = { class: window.KcDefinitionList };
    if (window.KcStatusBadge) map.statusBadge = { class: window.KcStatusBadge };
    if (window.KcGroup) map.group = { class: window.KcGroup };
    if (window.KcColumns) map.columns = { class: window.KcColumns };
    if (window.KcGrid) map.grid = { class: window.KcGrid };
    if (window.KcCards) map.cards = { class: window.KcCards };
    if (window.KcApiEndpoint) map.apiEndpoint = { class: window.KcApiEndpoint };
    if (window.KcKeyValues) map.keyValues = { class: window.KcKeyValues };
    if (window.KcKbd) map.kbd = { class: window.KcKbd };
    if (window.KcReusable) map.reusable = { class: window.KcReusable };

    builtToolsResult = { tools: map };
    return map;
  }

  // --- Media Library Modal ---
  function openMediaLibrary(kind, cb) {
    mediaKind = kind || 'image';
    mediaCallback = cb;
    if (mediaModal) {
      mediaModal.hidden = false;
      loadMediaGrid('');
    }
  }

  async function loadMediaGrid(q) {
    if (!mediaGrid) return;
    mediaGrid.innerHTML = '<div class="kc-empty-hint">Loading media library…</div>';
    try {
      const res = await fetch(cfg.apiUrl + '?action=media_list&kind=' + encodeURIComponent(mediaKind) + '&q=' + encodeURIComponent(q || ''), {
        headers: { 'X-CSRF-TOKEN': cfg.csrf }
      });
      const json = await res.json();
      if (json.ok && Array.isArray(json.items)) {
        if (!json.items.length) {
          mediaGrid.innerHTML = '<div class="kc-empty-hint">No media items found.</div>';
          return;
        }
        mediaGrid.innerHTML = '';
        json.items.forEach(function (item) {
          const card = document.createElement('div');
          card.className = 'kc-media-item';
          card.innerHTML = '<div class="kc-media-thumb">'
            + (item.mime.startsWith('image/') ? '<img src="' + escapeHtml(item.url) + '" alt="' + escapeHtml(item.alt) + '">' : '📎')
            + '</div><div class="kc-media-info">' + escapeHtml(item.title) + '</div>';
          card.addEventListener('click', function () {
            if (mediaCallback) mediaCallback(item);
            if (mediaModal) mediaModal.hidden = true;
          });
          mediaGrid.appendChild(card);
        });
      }
    } catch (e) {
      mediaGrid.innerHTML = '<div class="kc-empty-hint">Failed to load media.</div>';
    }
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // --- Canvas Whitespace Click-To-Focus ---
  function initCanvasFocus() {
    bindCanvasClickToFocus();
  }

  function bindCanvasClickToFocus() {
    if (!canvasEl) return;
    canvasEl.addEventListener('click', function (e) {
      if (e.target === canvasEl || e.target === stageEl || e.target === surfaceEl) {
        if (editor && editor.blocks) {
          const count = editor.blocks.getBlocksCount();
          if (count > 0) {
            editor.caret.setToBlock(count - 1, 'end');
          }
        }
      }
    });
  }

  function filterComponents(q) {
    if (!componentList) return;
    const query = (q || '').toLowerCase().trim();
    componentList.querySelectorAll('.kc-component').forEach(function (btn) {
      const text = (btn.textContent + ' ' + (btn.dataset.keywords || '')).toLowerCase();
      btn.style.display = (!query || text.includes(query)) ? '' : 'none';
    });
  }

  // --- Main Bootstrap ---
  async function init() {
    initPanels();
    initRibbon();
    initSidebarTabs();
    initPatterns();
    initTemplates();
    initCanvasFocus();
    loadReusableSidebar();

    // Revisions modal trigger
    const revBtn = document.getElementById('kc-revisions-btn');
    const ribbonRevBtn = document.getElementById('kc-ribbon-revisions');
    if (revBtn) revBtn.addEventListener('click', openRevisionsModal);
    if (ribbonRevBtn) ribbonRevBtn.addEventListener('click', openRevisionsModal);
    if (restoreRevBtn) restoreRevBtn.addEventListener('click', restoreSelectedRevision);

    // Preview trigger
    const prevBtn = document.getElementById('kc-preview-btn');
    if (prevBtn) prevBtn.addEventListener('click', openPreview);

    // Document Card action buttons
    const cardSaveBtn = document.getElementById('kc-card-save-btn');
    const cardPrevBtn = document.getElementById('kc-card-preview-btn');
    if (cardSaveBtn) cardSaveBtn.addEventListener('click', function (e) { e.preventDefault(); save(false); });
    if (cardPrevBtn) cardPrevBtn.addEventListener('click', function (e) { e.preventDefault(); openPreview(); });

    // Command palette trigger & shortcut
    const paletteTrigger = document.getElementById('kc-palette-trigger');
    if (paletteTrigger) paletteTrigger.addEventListener('click', openPalette);
    if (paletteInput) {
      paletteInput.addEventListener('input', function () { renderPaletteResults(paletteInput.value); });
      paletteInput.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); selectPaletteDelta(1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); selectPaletteDelta(-1); }
        else if (e.key === 'Enter') {
          e.preventDefault();
          if (paletteMatches[paletteIndex]) {
            closePalette();
            paletteMatches[paletteIndex].action();
          }
        } else if (e.key === 'Escape') {
          closePalette();
        }
      });
    }

    // Close modals on [data-kc-close]
    document.querySelectorAll('[data-kc-close]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const modal = btn.closest('.kc-modal');
        if (modal) modal.hidden = true;
      });
    });

    // Conflict resolution modal buttons
    const overwriteBtn = document.getElementById('kc-conflict-overwrite');
    const reloadBtn = document.getElementById('kc-conflict-reload');
    if (overwriteBtn) {
      overwriteBtn.addEventListener('click', function () {
        if (updatedField) updatedField.value = '';
        if (conflictModal) conflictModal.hidden = true;
        save(false);
      });
    }
    if (reloadBtn) {
      reloadBtn.addEventListener('click', function () { window.location.reload(); });
    }

    // Component search live filtering
    const compSearch = document.getElementById('kc-component-search');
    if (compSearch && componentList) {
      compSearch.addEventListener('input', function () {
        const q = compSearch.value.toLowerCase().trim();
        componentList.querySelectorAll('.kc-component').forEach(function (btn) {
          const text = (btn.textContent + ' ' + (btn.dataset.keywords || '')).toLowerCase();
          btn.style.display = (!q || text.includes(q)) ? '' : 'none';
        });
      });
    }

    // Component tile click-to-insert
    document.querySelectorAll('.kc-component').forEach(function (btn) {
      btn.addEventListener('click', function () {
        insertBlockByType(btn.dataset.componentId);
      });
    });

    // Ribbon tool insert clicks
    document.querySelectorAll('.kc-tool[data-insert]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        insertBlockByType(btn.dataset.insert);
      });
    });

    // Global keyboard shortcuts (Ctrl/Cmd+S, Ctrl/Cmd+K, Ctrl/Cmd+Z, Ctrl/Cmd+Y, Ctrl/Cmd+Shift+Z)
    window.addEventListener('keydown', function (e) {
      const isCmdOrCtrl = e.ctrlKey || e.metaKey;
      if (!isCmdOrCtrl) return;
      const key = e.key.toLowerCase();
      if (key === 's') {
        e.preventDefault();
        save(false);
      } else if (key === 'k') {
        e.preventDefault();
        openPalette();
      } else if (key === 'z') {
        e.preventDefault();
        if (e.shiftKey) {
          triggerRedo();
        } else {
          triggerUndo();
        }
      } else if (key === 'y') {
        e.preventDefault();
        triggerRedo();
      }
    }, true);

    // Mark dirty & update Document metadata UI on title/slug/status changes
    if (titleInput) titleInput.addEventListener('input', markDirty);
    if (slugInput) {
      slugInput.addEventListener('input', function () {
        markDirty();
        updateDocumentMetaUI();
      });
    }
    if (statusSelect) {
      statusSelect.addEventListener('change', function () {
        markDirty();
        updateDocumentMetaUI();
      });
    }
    ['kc-doc-excerpt', 'kc-doc-meta-title', 'kc-doc-meta-desc'].forEach(function (id) {
      const el = document.getElementById(id);
      if (el) el.addEventListener('input', markDirty);
    });
    updateDocumentMetaUI();

    // Form submit prevention
    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        save(false);
      });
    }

    if (cfg.mode === 'structured') {
      if (!window.EditorJS) {
        showBootError('Editor.js runtime library failed to load.');
        return;
      }

      let initialData = cfg.initialData || cfg.document;
      if (initialData && Array.isArray(initialData.blocks)) {
        initialData = {
          time: Date.now(),
          blocks: initialData.blocks.map(function (b) {
            let type = b.type || 'paragraph';
            if (type === 'heading') type = 'header';
            if (type === 'link') type = 'linkCard';
            return {
              id: b.id || ('b_' + Math.random().toString(36).substr(2, 8)),
              type: type,
              data: b.data || {}
            };
          }),
          version: '2.30.8'
        };
      } else {
        initialData = { time: Date.now(), blocks: [], version: '2.30.8' };
      }

      const toolsMap = buildTools();

      try {
        editor = new window.EditorJS({
          holder: 'kc-editorjs',
          tools: toolsMap,
          data: initialData,
          placeholder: 'Start writing… or type / to insert a block',
          autofocus: true,
          onChange: function (api, event) {
            markDirty();
            refreshOutline();
            updatePaginationDebounced();
            recordHistoryDebounced();
            const index = (api && api.blocks) ? api.blocks.getCurrentBlockIndex() : -1;
            if (index >= 0) {
              lastSelectedIndex = index;
              updateInspector(index);
            }
            if (window.KcEditorRuntime) {
              window.KcEditorRuntime.emit('change', { api, event });
              window.KcEditorRuntime.updateActiveBlock();
            }
          },
          onReady: function () {
            editorReady = true;
            root.dataset.editorState = 'ready';
            
            // Capture initial document state for Undo stack
            pushHistorySnapshot();
            
            // Sync singleton EditorRuntime with active EditorJS instance
            if (window.KcEditorRuntime) {
              window.KcEditorRuntime.instance = editor;
              window.KcEditorRuntime.setState('ready');
            }

            // Initialize Drag and Drop, Inspector, and Navigator shells
            try {
              if (window.KcDragDropEngine) {
                const dd = new window.KcDragDropEngine();
                dd.init();
              }
              if (window.KcInspectorShell) {
                const insp = new window.KcInspectorShell('kc-right-pane');
                insp.init();
              }
              if (window.KcNavigatorShell) {
                const nav = new window.KcNavigatorShell('kc-left-pane');
                nav.init();
              }
              if (window.KcBlockToolbar) {
                const tb = new window.KcBlockToolbar();
                tb.init();
              }
              if (window.KcFavorites && typeof window.KcFavorites.init === 'function') {
                window.KcFavorites.init();
              }
            } catch (shellErr) {
              console.error('[KC Editor] Shell sub-component init error:', shellErr);
            }

            updateDiagnostics();
            refreshCounts();
            refreshOutline();
            checkLocalRecovery();
            setStatus(idField && Number(idField.value) ? 'Saved' : 'Not saved', idField && Number(idField.value) ? 'saved' : 'new');
          }
        });
      } catch (err) {
        showBootError('Editor initialization failed: ' + err.message);
      }
    } else {
      editorReady = true;
      root.dataset.editorState = 'ready';
      updateDiagnostics();
    }
  }

  function checkLocalRecovery() {
    try {
      const raw = localStorage.getItem(getDocStorageKey());
      if (!raw) return;
      const payload = JSON.parse(raw);
      if (!payload || !payload.data || !payload.timestamp) return;
      const loadTime = window.__kc_page_load_time || Date.now();
      if (payload.timestamp < loadTime - 600000) return; // older than 10 mins
      
      const timeAgoStr = new Date(payload.timestamp).toLocaleTimeString();
      if (confirm('We found an unsaved local recovery draft from ' + timeAgoStr + '. Would you like to restore it?')) {
        if (editor && payload.data) {
          editor.render(payload.data).then(() => {
            if (payload.title && titleInput) titleInput.value = payload.title;
            if (payload.slug && slugInput) slugInput.value = payload.slug;
            markDirty();
            setStatus('Restored local draft', 'dirty');
          });
        }
      } else {
        clearLocalRecovery();
      }
    } catch (e) {}
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
