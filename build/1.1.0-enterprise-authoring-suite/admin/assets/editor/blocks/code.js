(function (global) {
  'use strict';

  const LANGS = ['', 'text', 'php', 'javascript', 'typescript', 'json', 'html', 'css', 'sql', 'bash', 'yaml', 'xml', 'python', 'go'];

  class KcCode {
    static get toolbox() {
      return { title: 'Code', icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M8 8 4 12l4 4M16 8l4 4-4 4M13 6l-2 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' };
    }
    static get isReadOnlySupported() { return true; }
    static get enableLineBreaks() { return true; }
    static get sanitize() { return { code: true, language: {}, caption: {} }; }
    constructor({ data, readOnly }) {
      this.readOnly = !!readOnly;
      this.data = { code: data.code || '', language: data.language || '', caption: data.caption || '' };
    }
    render() {
      const wrap = document.createElement('div');
      wrap.className = 'kc-tool kc-tool-code';
      const bar = document.createElement('div');
      bar.className = 'kc-code-bar';
      const lang = document.createElement('select');
      LANGS.forEach((item) => {
        const opt = document.createElement('option');
        opt.value = item;
        opt.textContent = item || 'Language';
        if (item === this.data.language) opt.selected = true;
        lang.appendChild(opt);
      });
      const caption = document.createElement('input');
      caption.type = 'text';
      caption.placeholder = 'Optional title';
      caption.value = this.data.caption;
      const area = document.createElement('textarea');
      area.className = 'kc-code-area';
      area.spellcheck = false;
      area.value = this.data.code;
      area.placeholder = 'Paste code…';
      if (this.readOnly) {
        lang.disabled = true;
        caption.disabled = true;
        area.readOnly = true;
      }
      bar.appendChild(lang);
      bar.appendChild(caption);
      wrap.appendChild(bar);
      wrap.appendChild(area);
      this.nodes = { lang, caption, area };
      return wrap;
    }
    save() {
      return {
        code: this.nodes.area.value,
        language: this.nodes.lang.value,
        caption: this.nodes.caption.value.trim()
      };
    }
  }

  if (global.KcEditorRegistry) {
    global.KcEditorRegistry.register('code', KcCode, { label: 'Code', group: 'technical' });
  }
  global.KcCode = KcCode;
})(window);
