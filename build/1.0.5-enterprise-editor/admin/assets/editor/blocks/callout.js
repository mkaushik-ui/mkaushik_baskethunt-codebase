(function (global) {
  'use strict';

  const TONES = [
    { id: 'info', label: 'Info' },
    { id: 'note', label: 'Note' },
    { id: 'tip', label: 'Tip' },
    { id: 'warning', label: 'Warning' },
    { id: 'danger', label: 'Danger' },
    { id: 'success', label: 'Success' }
  ];

  class KcCallout {
    static get toolbox() {
      return { title: 'Callout', icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 8v5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="16.5" r="1" fill="currentColor"/><rect x="4" y="4" width="16" height="16" rx="3" stroke="currentColor" stroke-width="2"/></svg>' };
    }
    static get isReadOnlySupported() { return true; }
    static get conversionConfig() { return { export: 'text', import: 'text' }; }
    static get sanitize() {
      return { text: { b: true, strong: true, i: true, em: true, u: true, s: true, code: true, a: { href: true }, br: true }, title: {}, tone: {} };
    }
    constructor({ data, readOnly }) {
      this.readOnly = !!readOnly;
      this.data = {
        tone: TONES.some(function (t) { return t.id === data.tone; }) ? data.tone : 'info',
        title: data.title || '',
        text: data.text || ''
      };
    }
    render() {
      const wrap = document.createElement('div');
      wrap.className = 'kc-tool kc-tool-callout kc-callout-' + this.data.tone;
      wrap.dataset.tone = this.data.tone;

      const select = document.createElement('select');
      select.className = 'kc-tool-tone';
      TONES.forEach((tone) => {
        const opt = document.createElement('option');
        opt.value = tone.id;
        opt.textContent = tone.label;
        if (tone.id === this.data.tone) opt.selected = true;
        select.appendChild(opt);
      });
      select.disabled = this.readOnly;
      select.addEventListener('change', () => {
        this.data.tone = select.value;
        wrap.className = 'kc-tool kc-tool-callout kc-callout-' + select.value;
        wrap.dataset.tone = select.value;
      });

      const title = document.createElement('input');
      title.className = 'kc-tool-title';
      title.placeholder = 'Callout title';
      title.value = this.data.title;
      title.disabled = this.readOnly;

      const body = document.createElement('div');
      body.className = 'kc-tool-body';
      body.contentEditable = this.readOnly ? 'false' : 'true';
      body.innerHTML = this.data.text;
      body.dataset.placeholder = 'Write the callout…';

      wrap.appendChild(select);
      wrap.appendChild(title);
      wrap.appendChild(body);
      this.nodes = { wrap, select, title, body };
      return wrap;
    }
    save() {
      return {
        tone: this.nodes.select.value,
        title: this.nodes.title.value.trim(),
        text: this.nodes.body.innerHTML
      };
    }
  }

  if (global.KcEditorRegistry) {
    global.KcEditorRegistry.register('callout', KcCallout, { label: 'Callout', group: 'notice' });
  }
  global.KcCallout = KcCallout;
})(window);
