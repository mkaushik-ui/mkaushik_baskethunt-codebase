/**
 * Knowledge Center Legacy HTML Block (1.0.9).
 * Preserves existing raw HTML safely with a clean rendered preview and explicit Edit HTML mode.
 */
(function (global) {
  'use strict';

  class KcLegacy {
    static get toolbox() {
      return {
        title: 'Legacy HTML',
        icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" stroke="currentColor" stroke-width="2"/><polyline points="14 2 14 8 20 8" stroke="currentColor" stroke-width="2"/><line x1="16" y1="13" x2="8" y2="13" stroke="currentColor" stroke-width="2"/><line x1="16" y1="17" x2="8" y2="17" stroke="currentColor" stroke-width="2"/></svg>'
      };
    }
    static get isReadOnlySupported() { return true; }
    static get enableLineBreaks() { return true; }
    static get sanitize() { return { html: true }; }

    constructor({ data, readOnly }) {
      this.readOnly = !!readOnly;
      this.data = { html: data.html || data.text || '' };
      this.mode = 'preview'; // 'preview' | 'edit'
    }

    render() {
      const wrap = document.createElement('div');
      wrap.className = 'kc-tool kc-tool-legacy';

      const head = document.createElement('div');
      head.className = 'kc-legacy-head';

      const titleWrap = document.createElement('div');
      titleWrap.className = 'kc-legacy-title-wrap';
      titleWrap.innerHTML = '<span class="kc-legacy-badge">LEGACY CONTENT</span><strong>Preserved HTML</strong>';

      const controls = document.createElement('div');
      controls.className = 'kc-legacy-controls';

      const btnEdit = document.createElement('button');
      btnEdit.type = 'button';
      btnEdit.className = 'kc-legacy-btn kc-legacy-btn-edit';
      btnEdit.textContent = '✏ Edit HTML';

      const btnPreview = document.createElement('button');
      btnPreview.type = 'button';
      btnPreview.className = 'kc-legacy-btn kc-legacy-btn-preview is-active';
      btnPreview.textContent = '👁 Preview';

      controls.appendChild(btnPreview);
      if (!this.readOnly) {
        controls.appendChild(btnEdit);
      }

      head.appendChild(titleWrap);
      head.appendChild(controls);

      const hint = document.createElement('p');
      hint.className = 'kc-legacy-hint';
      hint.textContent = 'This content was created in the previous editor. It is preserved for compatibility and rendered safely.';

      const preview = document.createElement('div');
      preview.className = 'kc-legacy-preview entry-content';
      preview.innerHTML = this.data.html || '<em style="color:#94a3b8;">Empty legacy content.</em>';

      const editorWrap = document.createElement('div');
      editorWrap.className = 'kc-legacy-edit-wrap';
      editorWrap.hidden = true;

      const area = document.createElement('textarea');
      area.className = 'kc-legacy-html';
      area.value = this.data.html;
      area.rows = 8;
      area.placeholder = '<p>Enter raw HTML content…</p>';
      if (this.readOnly) area.readOnly = true;

      area.addEventListener('input', () => {
        this.data.html = area.value;
        preview.innerHTML = area.value || '<em style="color:#94a3b8;">Empty legacy content.</em>';
      });

      btnEdit.addEventListener('click', () => {
        this.mode = 'edit';
        editorWrap.hidden = false;
        preview.hidden = true;
        btnEdit.classList.add('is-active');
        btnPreview.classList.remove('is-active');
        area.focus();
      });

      btnPreview.addEventListener('click', () => {
        this.mode = 'preview';
        editorWrap.hidden = true;
        preview.hidden = false;
        preview.innerHTML = area.value || '<em style="color:#94a3b8;">Empty legacy content.</em>';
        btnPreview.classList.add('is-active');
        btnEdit.classList.remove('is-active');
      });

      editorWrap.appendChild(area);

      wrap.appendChild(head);
      wrap.appendChild(hint);
      wrap.appendChild(preview);
      wrap.appendChild(editorWrap);

      this.nodes = { wrap, area, preview, btnEdit, btnPreview, editorWrap };
      return wrap;
    }

    save() {
      return { html: this.nodes.area ? this.nodes.area.value : (this.data.html || '') };
    }
  }

  if (global.KcEditorRegistry) {
    global.KcEditorRegistry.register('legacy', KcLegacy, { label: 'Legacy HTML', group: 'legacy' });
  }
  global.KcLegacy = KcLegacy;
})(window);
