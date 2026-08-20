/**
 * Knowledge Center Callout Block (1.0.9).
 * Semantic notice container with 6 controlled tones: info, note, tip, warning, danger, success.
 */
(function (global) {
  'use strict';

  const TONES = [
    { id: 'info', label: 'Info', icon: 'ℹ', desc: 'Informational notice' },
    { id: 'note', label: 'Note', icon: '📝', desc: 'Editorial note' },
    { id: 'tip', label: 'Tip', icon: '💡', desc: 'Helpful recommendation' },
    { id: 'warning', label: 'Warning', icon: '⚠️', desc: 'Important precaution' },
    { id: 'danger', label: 'Danger', icon: '⛔', desc: 'Critical alert' },
    { id: 'success', label: 'Success', icon: '✓', desc: 'Success confirmation' }
  ];

  class KcCallout {
    static get toolbox() {
      return {
        title: 'Callout',
        icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 8v5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="16.5" r="1" fill="currentColor"/><rect x="4" y="4" width="16" height="16" rx="3" stroke="currentColor" stroke-width="2"/></svg>'
      };
    }
    static get isReadOnlySupported() { return true; }
    static get conversionConfig() { return { export: 'text', import: 'text' }; }
    static get sanitize() {
      return {
        text: { b: true, strong: true, i: true, em: true, u: true, s: true, code: true, a: { href: true }, br: true },
        title: {},
        tone: {}
      };
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

      const header = document.createElement('div');
      header.className = 'kc-callout-header';

      const badge = document.createElement('span');
      badge.className = 'kc-callout-badge-icon';
      badge.textContent = this.getToneIcon(this.data.tone);

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

      const title = document.createElement('input');
      title.className = 'kc-tool-title';
      title.placeholder = 'Callout title (optional)';
      title.value = this.data.title;
      title.disabled = this.readOnly;

      select.addEventListener('change', () => {
        this.data.tone = select.value;
        wrap.className = 'kc-tool kc-tool-callout kc-callout-' + select.value;
        wrap.dataset.tone = select.value;
        badge.textContent = this.getToneIcon(select.value);
      });

      title.addEventListener('input', () => {
        this.data.title = title.value;
      });

      header.appendChild(badge);
      header.appendChild(select);
      header.appendChild(title);

      const body = document.createElement('div');
      body.className = 'kc-tool-body';
      body.contentEditable = this.readOnly ? 'false' : 'true';
      body.innerHTML = this.data.text;
      body.dataset.placeholder = 'Write the callout content…';

      body.addEventListener('input', () => {
        this.data.text = body.innerHTML;
      });

      wrap.appendChild(header);
      wrap.appendChild(body);
      this.nodes = { wrap, select, title, body, badge };
      return wrap;
    }

    getToneIcon(tone) {
      const found = TONES.find(t => t.id === tone);
      return found ? found.icon : 'ℹ';
    }

    save() {
      return {
        tone: this.nodes.select ? this.nodes.select.value : (this.data.tone || 'info'),
        title: this.nodes.title ? this.nodes.title.value.trim() : (this.data.title || ''),
        text: this.nodes.body ? this.nodes.body.innerHTML : (this.data.text || '')
      };
    }
  }

  if (global.KcEditorRegistry) {
    global.KcEditorRegistry.register('callout', KcCallout, { label: 'Callout', group: 'notice' });
  }
  global.KcCallout = KcCallout;
})(window);
