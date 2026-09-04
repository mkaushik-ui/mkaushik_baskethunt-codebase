(function (global) {
  'use strict';

  class DividerBlock {
    static get toolbox() {
      return {
        title: 'Divider',
        icon: '<svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><line x1="4" y1="12" x2="20" y2="12"></line></svg>'
      };
    }

    static get isReadOnlySupported() {
      return true;
    }

    static get contentless() {
      return true;
    }

    constructor({ data, config, api, readOnly }) {
      this.api = api;
      this.readOnly = !!readOnly;
    }

    render() {
      const wrapper = document.createElement('div');
      if (this.api && this.api.styles) {
        wrapper.classList.add(this.api.styles.block);
      }
      wrapper.classList.add('kc-divider-tool');
      wrapper.innerHTML = '<div class="kc-divider-line"></div>';

      return wrapper;
    }

    save(blockContent) {
      return {}; // Output is strictly { type: 'divider', data: {} }
    }
  }

  if (global.KcEditorRegistry && typeof global.KcEditorRegistry.register === 'function') {
    global.KcEditorRegistry.register('divider', DividerBlock, { label: 'Divider', group: 'basic' });
  }

  global.KcDivider = DividerBlock;
  global.DividerBlock = DividerBlock;
  global.Divider = DividerBlock;
})(typeof window !== 'undefined' ? window : this);

