(function (global) {
  'use strict';

  class KcImage {
    static get toolbox() {
      return { title: 'Image', icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="2"/><circle cx="8.5" cy="10" r="1.5" fill="currentColor"/><path d="M21 16l-5.5-5.5L7 19" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>' };
    }
    static get isReadOnlySupported() { return true; }
    static get pasteConfig() { return { files: { mimeTypes: ['image/*'] } }; }
    static get sanitize() {
      return { assetId: {}, url: {}, alt: {}, caption: { b: true, i: true }, align: {}, width: {} };
    }
    constructor({ data, api, readOnly, config }) {
      this.api = api;
      this.readOnly = !!readOnly;
      this.config = config || {};
      const file = data.file || {};
      this.data = {
        assetId: data.assetId || file.assetId || null,
        url: data.url || file.url || '',
        alt: data.alt || '',
        caption: data.caption || '',
        align: data.align || 'center',
       width: data.width || '100'
      };
    }
    render() {
      const wrap = document.createElement('div');
      wrap.className = 'kc-tool kc-tool-image';
      wrap.innerHTML = [
        '<div class="kc-image-frame"></div>',
        '<div class="kc-image-controls">',
        '  <div class="kc-image-actions"></div>',
        '  <div class="kc-image-meta">',
        '    <input class="kc-image-alt" type="text" placeholder="Alt text (for accessibility)">',
        '    <input class="kc-image-caption" type="text" placeholder="Image caption…">',
        '  </div>',
        '</div>'
      ].join('');
      this.nodes = {
        wrap,
        frame: wrap.querySelector('.kc-image-frame'),
        actions: wrap.querySelector('.kc-image-actions'),
        alt: wrap.querySelector('.kc-image-alt'),
        caption: wrap.querySelector('.kc-image-caption')
      };
      this.nodes.alt.value = this.data.alt;
      this.nodes.caption.value = this.data.caption;
      this.paint();
      if (!this.readOnly) this.bindActions();
      return wrap;
    }
    bindActions() {
    this.nodes.actions.innerHTML = '';

    const file = document.createElement('input');
    file.type = 'file';
    file.accept = 'image/png,image/jpeg,image/gif,image/webp';
    file.hidden = true;

    file.addEventListener('change', () => {
        if (file.files && file.files[0]) {
            this.uploadFile(file.files[0]);
        }
    });

    if (!this.data.url) {

        const upload = document.createElement('button');
        upload.type = 'button';
        upload.className = 'kc-image-btn kc-image-btn-primary';
        upload.textContent = 'Upload image';

        upload.addEventListener('click', () => file.click());

        const browse = document.createElement('button');
        browse.type = 'button';
        browse.className = 'kc-image-btn';
        browse.textContent = 'Media library';

        browse.addEventListener('click', () => {
            if (typeof this.config.onBrowse === 'function') {
                this.config.onBrowse('image', asset => this.applyAsset(asset));
            } else {
                file.click();
            }
        });

        this.nodes.actions.appendChild(upload);
        this.nodes.actions.appendChild(browse);

    } else {

        const replaceBtn = document.createElement('button');
        replaceBtn.type = 'button';
        replaceBtn.className = 'kc-image-btn kc-image-btn-primary';
        replaceBtn.textContent = 'Replace';

        replaceBtn.addEventListener('click', () => file.click());

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'kc-image-btn';
        removeBtn.textContent = 'Remove';

        removeBtn.addEventListener('click', () => {

            this.data = {
    assetId: null,
    url: '',
    alt: '',
    caption: '',
    align: 'center',
    width: '100'
};

this.nodes.alt.value = '';
this.nodes.caption.value = '';

            this.paint();
            this.bindActions();
        });

        this.nodes.actions.appendChild(replaceBtn);
        this.nodes.actions.appendChild(removeBtn);

        //------------------------------------
        // Alignment
        //------------------------------------

        ['left','center','right'].forEach(mode => {

            const btn = document.createElement('button');

            btn.type = 'button';
            btn.className = 'kc-image-btn';

            btn.textContent = mode;

            if (this.data.align === mode) {
                btn.classList.add('active');
            }

            btn.addEventListener('click', () => {

                this.data.align = mode;

                this.paint();
                this.bindActions();

            });

            this.nodes.actions.appendChild(btn);

        });

        //------------------------------------
        // Width presets
        //------------------------------------

        [
            ['25','25%'],
            ['50','50%'],
            ['75','75%'],
            ['100','100%']
        ].forEach(([value,label]) => {

            const btn = document.createElement('button');

            btn.type = 'button';
            btn.className = 'kc-image-btn';

            btn.textContent = label;

            if (this.data.width === value) {
                btn.classList.add('active');
            }

            btn.addEventListener('click', () => {

                this.data.width = value;

                this.paint();
                this.bindActions();

            });

            this.nodes.actions.appendChild(btn);

        });

    }

    this.nodes.actions.appendChild(file);
}
    paint() {

    this.nodes.frame.innerHTML = '';

    this.nodes.frame.className = 'kc-image-frame';

    this.nodes.frame.classList.add(
        'align-' + (this.data.align || 'center')
    );

    if (!this.data.url) {

        this.nodes.frame.innerHTML =
            '<div class="kc-image-empty">' +
            '<span class="kc-image-empty-icon">📷</span>' +
            '<span>No image selected</span>' +
            '</div>';

        return;
    }

    const img = document.createElement('img');

    img.src = this.data.url;
    img.alt = this.nodes.alt
    ? this.nodes.alt.value.trim()
    : (this.data.alt || '');

    switch (this.data.width) {

        case '25':
            img.style.width = '25%';
            break;

        case '50':
            img.style.width = '50%';
            break;

        case '75':
            img.style.width = '75%';
            break;

        case '100':
            img.style.width = '100%';
            break;

       default:
          img.style.width = '100%';
          this.data.width = '100';
    }

    const toolbar = document.createElement('div');
toolbar.className = 'kc-image-overlay';

toolbar.innerHTML =
    '<span class="kc-image-badge">' +
    (this.data.width || '100') +
    '%</span>';

this.nodes.frame.appendChild(img);
this.nodes.frame.appendChild(toolbar);
}
    applyAsset(asset) {

    this.data.assetId = asset.id || asset.assetId || null;
    this.data.url = asset.url || '';

    if (!this.data.alt && asset.alt) {
        this.data.alt = asset.alt;
    }

    if (this.nodes.alt) {
        this.nodes.alt.value = this.data.alt;
    }

    this.paint();

if (!this.readOnly) {

    this.bindActions();

    this.nodes.frame.scrollIntoView({
        behavior: 'smooth',
        block: 'nearest'
    });

}
  }
  async uploadFile(file) {

    if (typeof this.config.uploader === 'function') {

        try {

            const result = await this.config.uploader(file, 'image');

            if (result && result.url) {

                this.applyAsset(result);
                return;

            }

        } catch (err) {

            console.error('Image upload failed:', err);

        }

    }

    // Local preview fallback
    const reader = new FileReader();

    reader.onload = (e) => {

        this.applyAsset({
            url: e.target.result,
            alt: file.name
        });

    };

    reader.readAsDataURL(file);

}
    onPaste(event) {
      const files = event.detail && event.detail.file ? [event.detail.file] : [];
      if (files[0]) this.uploadFile(files[0]);
    }
    save() {

    this.data.alt = this.nodes.alt.value.trim();
    this.data.caption = this.nodes.caption.value.trim();

    return {
        assetId: this.data.assetId,
        url: this.data.url,
        alt: this.data.alt,
        caption: this.data.caption,
        align: this.data.align || 'center',
        width: this.data.width || '100'
    };

}
    renderSettings() {

    const update = () => {

        this.paint();

        if (!this.readOnly) {
            this.bindActions();
        }

    };

    return [

        {
            icon: '<span>⇤</span>',
            label: 'Left',
            closeOnActivate: true,
            isActive: this.data.align === 'left',
            onActivate: () => {

                this.data.align = 'left';
                update();

            }
        },

        {
            icon: '<span>↔</span>',
            label: 'Center',
            closeOnActivate: true,
            isActive: this.data.align === 'center',
            onActivate: () => {

                this.data.align = 'center';
                update();

            }
        },

        {
            icon: '<span>⇥</span>',
            label: 'Right',
            closeOnActivate: true,
            isActive: this.data.align === 'right',
            onActivate: () => {

                this.data.align = 'right';
                update();

            }
        }

    ];

}
  }

  if (global.KcEditorRegistry) {
    global.KcEditorRegistry.register('image', KcImage, { label: 'Image', group: 'media' });
  }
  global.KcImage = KcImage;
})(window);
