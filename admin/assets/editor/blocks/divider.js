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

  constructor({ data, config, api, readOnly }) {
    this.api = api;
  }

  render() {
    const wrapper = document.createElement('div');
    // Using native Editor.js block class ensures it selects and behaves properly
    if (this.api && this.api.styles) {
      wrapper.classList.add(this.api.styles.block);
    }
    wrapper.classList.add('kc-divider-tool');
    
    // Massive, obvious visual indicator for the editor UI
    wrapper.innerHTML = '&#10022; &nbsp; &nbsp; &#10022; &nbsp; &nbsp; &#10022;';
    wrapper.style.textAlign = 'center';
    wrapper.style.color = '#94a3b8';
    wrapper.style.fontSize = '24px';
    wrapper.style.lineHeight = '2em';
    wrapper.style.letterSpacing = '0.2em';
    wrapper.style.userSelect = 'none';
    
    return wrapper;
  }

  save(blockContent) {
    return {}; // Output is strictly { type: 'divider', data: {} }
  }
}
