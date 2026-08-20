(function (global) {
  'use strict';

  class KcFile {
    static get toolbox() {
      return { title: 'File', icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M7 3h7l5 5v13H7V3z" stroke="currentColor" stroke-width="2"/><path d="M14 3v5h5" stroke="currentColor" stroke-width="2"/></svg>' };
    }
    static get isReadOnlySupported() { return true; }
    static get sanitize() { return { assetId: {}, url: {}, name: {}, mime: {} }; }
    constructor({ data, readOnly, config }) {
      this.readOnly = !!readOnly;
      this.config = config || {};
      const file = data.file || {};
      this.data = {
        assetId: data.assetId || file.assetId || null,
        url: data.url || file.url || '',
        name: data.name || file.name || '',
        mime: data.mime || file.mime || ''
      };
    }
    render() {
      const wrap = document.createElement('div');
      wrap.className = 'kc-tool kc-tool-file';
      this.label = document.createElement('div');
      this.label.className = 'kc-file-name';
      wrap.appendChild(this.label);
      this.paint();
      if (!this.readOnly) {
        const actions = document.createElement('div');
        actions.className = 'kc-image-actions';
        const upload = document.createElement('button');
        upload.type = 'button';
        upload.className = 'btn btn-ghost btn-sm';
        upload.textContent = 'Upload file';
        const browse = document.createElement('button');
        browse.type = 'button';
        browse.className = 'btn btn-ghost btn-sm';
        browse.textContent = 'Media library';
        const input = document.createElement('input');
        input.type = 'file';
        input.hidden = true;
        upload.addEventListener('click', () => input.click());
        input.addEventListener('change', () => {
          if (input.files && input.files[0]) this.uploadFile(input.files[0]);
        });
        browse.addEventListener('click', () => {
          if (typeof this.config.onBrowse === 'function') {
            this.config.onBrowse('all', (asset) => this.applyAsset(asset));
          }
        });
        actions.appendChild(upload);
        actions.appendChild(browse);
        actions.appendChild(input);
        wrap.appendChild(actions);
      }
      return wrap;
    }
    paint() {
      this.label.textContent = this.data.name || this.data.url || 'No file attached';
    }
    applyAsset(asset) {
      this.data.assetId = asset.id || asset.assetId || null;
      this.data.url = asset.url || '';
      this.data.name = asset.name || this.data.name;
      this.data.mime = asset.mime || '';
      this.paint();
    }
    async uploadFile(file) {
      if (typeof this.config.uploader !== 'function') return;
      const result = await this.config.uploader(file, 'file');
      if (result) this.applyAsset(Object.assign({ name: file.name, mime: file.type }, result));
    }
    save() {
      return this.data;
    }
  }

  if (global.KcEditorRegistry) {
    global.KcEditorRegistry.register('file', KcFile, { label: 'File', group: 'media' });
  }
  global.KcFile = KcFile;
})(window);
