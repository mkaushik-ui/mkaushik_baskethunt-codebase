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
        width: data.width || (data.stretched ? 'full' : 'default')
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
      const upload = document.createElement('button');
      upload.type = 'button';
      upload.className = 'kc-image-btn kc-image-btn-primary';
      upload.textContent = 'Upload image';
      const browse = document.createElement('button');
      browse.type = 'button';
      browse.className = 'kc-image-btn';
      browse.textContent = 'Media library';
      const file = document.createElement('input');
      file.type = 'file';
      file.accept = 'image/png,image/jpeg,image/gif,image/webp';
      file.hidden = true;
      upload.addEventListener('click', () => file.click());
      file.addEventListener('change', () => {
        if (file.files && file.files[0]) this.uploadFile(file.files[0]);
      });
      browse.addEventListener('click', () => {
        if (typeof this.config.onBrowse === 'function') {
          this.config.onBrowse('image', (asset) => this.applyAsset(asset));
        } else {
          file.click();
        }
      });
      this.nodes.actions.appendChild(upload);
      this.nodes.actions.appendChild(browse);
      this.nodes.actions.appendChild(file);
    }
    paint() {
      this.nodes.frame.innerHTML = '';
      if (this.data.url) {
        const img = document.createElement('img');
        img.src = this.data.url;
        img.alt = this.data.alt || 'Uploaded image';
        this.nodes.frame.appendChild(img);
      } else {
        this.nodes.frame.innerHTML = '<div class="kc-image-empty"><span class="kc-image-empty-icon">📷</span><span>No image selected</span></div>';
      }
    }
    applyAsset(asset) {
      this.data.assetId = asset.id || asset.assetId || null;
      this.data.url = asset.url || '';
      if (!this.data.alt && asset.alt) this.data.alt = asset.alt;
      if (this.nodes.alt) this.nodes.alt.value = this.data.alt;
      this.paint();
    }
    async uploadFile(file) {
      if (typeof this.config.uploader === 'function') {
        const result = await this.config.uploader(file, 'image');
        if (result && result.url) {
          this.applyAsset(result);
          return;
        }
      }
      // Instant FileReader preview
      const reader = new FileReader();
      reader.onload = (e) => {
        this.applyAsset({ url: e.target.result, alt: file.name });
      };
      reader.readAsDataURL(file);
    }
    onPaste(event) {
      const files = event.detail && event.detail.file ? [event.detail.file] : [];
      if (files[0]) this.uploadFile(files[0]);
    }
    save() {
      return {
        assetId: this.data.assetId,
        url: this.data.url,
        alt: this.nodes.alt.value.trim(),
        caption: this.nodes.caption.value.trim(),
        align: this.data.align || 'center',
        width: this.data.width || 'default'
      };
    }
    renderSettings() {
      return [
        { name: 'left', label: 'Align left', icon: '⇤', onActivate: () => { this.data.align = 'left'; } },
        { name: 'center', label: 'Align center', icon: '↔', onActivate: () => { this.data.align = 'center'; } },
        { name: 'right', label: 'Align right', icon: '⇥', onActivate: () => { this.data.align = 'right'; } },
        { name: 'default', label: 'Default width', icon: '□', onActivate: () => { this.data.width = 'default'; } },
        { name: 'wide', label: 'Wide', icon: '▭', onActivate: () => { this.data.width = 'wide'; } },
        { name: 'full', label: 'Full width', icon: '▬', onActivate: () => { this.data.width = 'full'; } }
      ].map((item) => ({
        icon: '<span>' + item.icon + '</span>',
        label: item.label,
        closeOnActivate: true,
        isActive: this.data.align === item.name || this.data.width === item.name,
        onActivate: item.onActivate
      }));
    }
  }

  if (global.KcEditorRegistry) {
    global.KcEditorRegistry.register('image', KcImage, { label: 'Image', group: 'media' });
  }
  global.KcImage = KcImage;
})(window);
