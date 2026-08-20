(function (global) {
  'use strict';

  class KcLink {
    static get toolbox() {
      return { title: 'Link', icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M10 13a5 5 0 0 0 7.07 0l1.41-1.41a5 5 0 0 0-7.07-7.07L10 5.93" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M14 11a5 5 0 0 0-7.07 0L5.52 12.41a5 5 0 0 0 7.07 7.07L14 18.07" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>' };
    }
    static get isReadOnlySupported() { return true; }
    static get sanitize() { return { url: {}, title: {}, text: { b: true, i: true, code: true } }; }
    constructor({ data, readOnly }) {
      this.readOnly = !!readOnly;
      this.data = { url: data.url || data.link || '', title: data.title || '', text: data.text || '' };
    }
    render() {
      const wrap = document.createElement('div');
      wrap.className = 'kc-tool kc-tool-link';
      wrap.innerHTML = '<label>URL</label><input class="kc-link-url" type="url" placeholder="https://…"><label>Title</label><input class="kc-link-title" type="text" placeholder="Link title"><label>Description</label><textarea class="kc-link-text" rows="2" placeholder="Optional description"></textarea>';
      wrap.querySelector('.kc-link-url').value = this.data.url;
      wrap.querySelector('.kc-link-title').value = this.data.title;
      wrap.querySelector('.kc-link-text').value = this.strip(this.data.text);
      if (this.readOnly) wrap.querySelectorAll('input,textarea').forEach((el) => { el.disabled = true; });
      this.nodes = wrap;
      return wrap;
    }
    strip(html) {
      const d = document.createElement('div');
      d.innerHTML = html || '';
      return d.textContent || '';
    }
    save() {
      return {
        url: this.nodes.querySelector('.kc-link-url').value.trim(),
        title: this.nodes.querySelector('.kc-link-title').value.trim(),
        text: this.nodes.querySelector('.kc-link-text').value.trim()
      };
    }
  }

  if (global.KcEditorRegistry) {
    global.KcEditorRegistry.register('link', KcLink, { label: 'Link', group: 'media' });
    global.KcEditorRegistry.register('linkCard', KcLink, { label: 'Link card', group: 'media' });
  }
  global.KcLink = KcLink;
  global.KcLinkCard = KcLink;
})(window);
