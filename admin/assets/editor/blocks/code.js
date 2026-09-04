(function (global) {
  'use strict';

  const LANGS = ['', 'text', 'php', 'javascript', 'typescript', 'json', 'html', 'css', 'sql', 'bash', 'yaml', 'xml', 'python', 'go'];

  class KcCode {
    static get toolbox() {
      return {
        title: 'Code',
        icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M8 8 4 12l4 4M16 8l4 4-4 4M13 6l-2 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
      };
    }
    static get isReadOnlySupported() { return true; }
    static get enableLineBreaks() { return true; }
    static get sanitize() { return { code: true, language: {}, caption: {} }; }

    constructor({ data, readOnly }) {
      this.readOnly = !!readOnly;
      this.data = {
        code: data.code || '',
        language: data.language || '',
        caption: data.caption || ''
      };
    }

    render() {
      const wrap = document.createElement('div');
      wrap.className = 'kc-tool kc-tool-code kc-dark-code-tool';

      // Header bar with window controls & actions
      const bar = document.createElement('div');
      bar.className = 'kc-code-bar';

      // macOS style dots
      const dots = document.createElement('div');
      dots.className = 'kc-code-dots';
      dots.innerHTML = '<span class="kc-code-dot kc-dot-red"></span><span class="kc-code-dot kc-dot-yellow"></span><span class="kc-code-dot kc-dot-green"></span>';

      // Language select pill
      const langWrap = document.createElement('div');
      langWrap.className = 'kc-code-lang-wrap';
      const lang = document.createElement('select');
      lang.className = 'kc-code-lang-select';
      LANGS.forEach((item) => {
        const opt = document.createElement('option');
        opt.value = item;
        opt.textContent = item ? item.toUpperCase() : 'PLAIN TEXT';
        if (item === this.data.language) opt.selected = true;
        lang.appendChild(opt);
      });
      langWrap.appendChild(lang);

      // Title/caption input
      const caption = document.createElement('input');
      caption.type = 'text';
      caption.className = 'kc-code-caption-input';
      caption.placeholder = 'Filename or snippet title…';
      caption.value = this.data.caption;

      // Copy button
      const copyBtn = document.createElement('button');
      copyBtn.type = 'button';
      copyBtn.className = 'kc-code-copy-btn';
      copyBtn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg><span>Copy</span>';
      copyBtn.addEventListener('click', (e) => {
        e.preventDefault();
        const codeText = area.value;
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(codeText).then(() => {
            copyBtn.classList.add('is-copied');
            copyBtn.innerHTML = '✓ <span>Copied!</span>';
            setTimeout(() => {
              copyBtn.classList.remove('is-copied');
              copyBtn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg><span>Copy</span>';
            }, 1800);
          });
        }
      });

      bar.appendChild(dots);
      bar.appendChild(langWrap);
      bar.appendChild(caption);
      bar.appendChild(copyBtn);

      // Editor body with line numbers gutter
      const editorBody = document.createElement('div');
      editorBody.className = 'kc-code-editor-body';

      const gutter = document.createElement('div');
      gutter.className = 'kc-code-gutter';

      const area = document.createElement('textarea');
      area.className = 'kc-code-area';
      area.spellcheck = false;
      area.value = this.data.code;
      area.placeholder = '// Paste or write code snippet here…';

      const updateGutter = () => {
        const lines = (area.value || '').split('\n').length;
        let numbers = '';
        for (let i = 1; i <= Math.max(lines, 3); i++) {
          numbers += i + '\n';
        }
        gutter.textContent = numbers;
      };

      area.addEventListener('input', updateGutter);
      area.addEventListener('scroll', () => {
        gutter.scrollTop = area.scrollTop;
      });

      if (this.readOnly) {
        lang.disabled = true;
        caption.disabled = true;
        area.readOnly = true;
      }

      editorBody.appendChild(gutter);
      editorBody.appendChild(area);

      wrap.appendChild(bar);
      wrap.appendChild(editorBody);

      this.nodes = { lang, caption, area, gutter };
      updateGutter();
      return wrap;
    }

    save() {
      return {
        code: this.nodes.area ? this.nodes.area.value : (this.data.code || ''),
        language: this.nodes.lang ? this.nodes.lang.value : (this.data.language || ''),
        caption: this.nodes.caption ? this.nodes.caption.value.trim() : (this.data.caption || '')
      };
    }
  }

  if (global.KcEditorRegistry) {
    global.KcEditorRegistry.register('code', KcCode, { label: 'Code', group: 'technical' });
  }
  global.KcCode = KcCode;
})(window);
