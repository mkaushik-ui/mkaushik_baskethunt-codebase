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

        mime: data.mime || file.mime || '',

        size: data.size || file.size || 0,

        extension: data.extension || file.extension || ''
      };
    }
    render() {

    const wrap = document.createElement('div');

    wrap.className = 'kc-tool kc-tool-file';

    wrap.innerHTML = [

        '<div class="kc-file-frame"></div>',

        '<div class="kc-file-controls">',

        '   <div class="kc-file-actions"></div>',

        '</div>'

    ].join('');

    this.nodes = {

        wrap,

        frame: wrap.querySelector('.kc-file-frame'),

        actions: wrap.querySelector('.kc-file-actions')

    };

    this.paint();

    if (!this.readOnly) {

        this.bindActions();

    }

    return wrap;

} 
  bindActions() {

    this.nodes.actions.innerHTML = '';

    const input = document.createElement('input');

    input.type = 'file';

    input.hidden = true;

    input.addEventListener('change', () => {

        if (input.files && input.files[0]) {

            this.uploadFile(input.files[0]);

        }

    });

    if (!this.data.url) {

        const upload = document.createElement('button');

        upload.type = 'button';

        upload.className = 'kc-image-btn kc-image-btn-primary';

        upload.textContent = 'Upload file';

        upload.onclick = () => input.click();

        const browse = document.createElement('button');

        browse.type = 'button';

        browse.className = 'kc-image-btn';

        browse.textContent = 'Media library';

        browse.onclick = () => {

            if (typeof this.config.onBrowse === 'function') {

                this.config.onBrowse('all', asset => this.applyAsset(asset));

            }

            else {

                input.click();

            }

        };

        this.nodes.actions.appendChild(upload);

        this.nodes.actions.appendChild(browse);

    }

    else {

        const replaceBtn = document.createElement('button');

        replaceBtn.type = 'button';

        replaceBtn.className = 'kc-image-btn kc-image-btn-primary';

        replaceBtn.textContent = 'Replace';

        replaceBtn.onclick = () => input.click();

        const removeBtn = document.createElement('button');

        removeBtn.type = 'button';

        removeBtn.className = 'kc-image-btn';

        removeBtn.textContent = 'Remove';

        removeBtn.onclick = () => {

            this.data = {

                assetId: null,

                url: '',

                name: '',

                mime: '',

                size: 0,

                extension: ''

            };

            this.paint();

            this.bindActions();

        };

        this.nodes.actions.appendChild(replaceBtn);

        this.nodes.actions.appendChild(removeBtn);

    }

    this.nodes.actions.appendChild(input);

}
    paint() {

    this.nodes.frame.innerHTML = '';

    if (!this.data.url) {

        this.nodes.frame.innerHTML =
            '<div class="kc-file-empty">' +
            '<span class="kc-file-icon">📄</span>' +
            '<span>No file attached</span>' +
            '</div>';

        return;
    }

    const ext = (this.data.extension || '').toUpperCase() || 'FILE';

    const card = document.createElement('a');

    card.className = 'kc-file-card';

    card.href = this.data.url;

    card.download = '';

    card.innerHTML =
        '<div class="kc-file-badge">' + ext + '</div>' +
        '<div class="kc-file-info">' +
            '<div class="kc-file-name">' + (this.data.name || 'Unnamed File') + '</div>' +
            '<div class="kc-file-size">' + this.formatSize(this.data.size) + '</div>' +
        '</div>';

    this.nodes.frame.appendChild(card);

}
formatSize(size) {

    size = Number(size) || 0;

    if (size >= 1024 * 1024) {

        return (size / 1024 / 1024).toFixed(1) + ' MB';

    }

    if (size >= 1024) {

        return (size / 1024).toFixed(1) + ' KB';

    }

    return size + ' B';

}
    applyAsset(asset) {

    this.data.assetId = asset.id || asset.assetId || null;

    this.data.url = asset.url || '';

    this.data.name = asset.name || '';

    this.data.mime = asset.mime || '';

    this.data.size = asset.size || 0;

    this.data.extension =
        asset.extension ||
        (this.data.name.split('.').pop() || '').toLowerCase();

    this.paint();

    if (!this.readOnly) {

        this.bindActions();

    }

}
    async uploadFile(file) {

    if (typeof this.config.uploader === 'function') {

        const result = await this.config.uploader(file, 'file');

        if (result && result.url) {

            this.applyAsset({

                assetId: result.assetId || result.id,

                url: result.url,

                name: result.name || file.name,

                mime: result.mime || file.type,

                size: result.size || file.size,

                extension:
                    result.extension ||
                    file.name.split('.').pop().toLowerCase()

            });

            return;

        }

    }

    this.applyAsset({

        url: URL.createObjectURL(file),

        name: file.name,

        mime: file.type,

        size: file.size,

        extension: file.name.split('.').pop().toLowerCase()

    });

}
   save() {

    return {

        assetId: this.data.assetId,

        url: this.data.url,

        name: this.data.name,

        mime: this.data.mime,

        size: this.data.size,

        extension: this.data.extension

    };

}
  }

  if (global.KcEditorRegistry) {
    global.KcEditorRegistry.register('file', KcFile, { label: 'File', group: 'media' });
  }
  global.KcFile = KcFile;
})(window);
