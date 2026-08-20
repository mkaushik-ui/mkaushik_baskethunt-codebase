(function (global) {
  'use strict';

  class KcLegacy {
    static get toolbox() {
      return { title: 'Legacy HTML', icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M5 5h14v14H5z" stroke="currentColor" stroke-width="2"/><path d="M8 12h8" stroke="currentColor" stroke-width="2"/></svg>' };
    }
    static get isReadOnlySupported() { return true; }
    static get enableLineBreaks() { return true; }
    static get sanitize() { return { html: true }; }
    constructor({ data, readOnly }) {
      this.readOnly = !!readOnly;
      this.data = { html: data.html || data.text || '' };
    }
    render() {
      const wrap = document.createElement('div');
      wrap.className = 'kc-tool kc-tool-legacy';
      const note = document.createElement('div');
      note.className = 'kc-selected-meta';
      note.textContent = 'Preserved HTML from the previous editor. It is sanitized on save and is not executed as a script.';
      const area = document.createElement('textarea');
      area.className = 'kc-legacy-html';
      area.value = this.data.html;
      area.rows = 10;
      if (this.readOnly) area.readOnly = true;
      wrap.appendChild(note);
      wrap.appendChild(area);
      this.nodes = { area };
      return wrap;
    }
    save() {
      return { html: this.nodes.area.value };
    }
  }

  if (global.KcEditorRegistry) {
    global.KcEditorRegistry.register('legacy', KcLegacy, { label: 'Legacy HTML', group: 'system' });
  }
  global.KcLegacy = KcLegacy;
})(window);
