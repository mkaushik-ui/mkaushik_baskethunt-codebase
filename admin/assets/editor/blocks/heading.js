/**
 * Knowledge Center Heading Block (A2-T02)
 * Supports levels H2-H6, auto anchor slugging (backend handled), and integrates with right Inspector panel.
 */
(function (global) {
  'use strict';

  class KcHeading {
    static get toolbox() {
      return {
        title: 'Heading',
        icon: '<svg viewBox="0 0 24 24" width="18" height="18"><path d="M7 4v16M17 4v16M7 12h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
      };
    }
    
    static get isReadOnlySupported() { return true; }
    
    // Allow conversion between text and heading
    static get conversionConfig() { 
      return { export: 'text', import: 'text' }; 
    }
    
    // Limit allowed inline formatting
    static get sanitize() {
      return {
        text: { b: true, strong: true, i: true, em: true, u: true, a: { href: true }, code: true, s: true, del: true }
      };
    }

    constructor({ data, api, readOnly, block }) {
      this.api = api;
      this.readOnly = !!readOnly;
      this.blockApi = block;
      this.data = {
        text: data.text || '',
        level: data.level ? parseInt(data.level, 10) : 2,
        anchor: data.anchor || ''
      };
      this.nodes = {};
      this._bindInspector = this._bindInspector.bind(this);
    }

    render() {
      this.nodes.wrapper = document.createElement('div');
      this.nodes.wrapper.className = 'kc-tool kc-tool-heading';
      
      this.nodes.body = document.createElement('div');
      this.nodes.body.className = `kc-heading-level kc-heading-level-${this.data.level}`;
      this.nodes.body.contentEditable = this.readOnly ? 'false' : 'true';
      this.nodes.body.dataset.placeholder = 'Heading...';
      this.nodes.body.innerHTML = this.data.text;
      
      if (!this.readOnly) {
        this.nodes.body.addEventListener('input', () => {
          this.data.text = this.nodes.body.innerHTML;
        });

        this.nodes.body.addEventListener('keydown', (e) => {
          // Allow enter to create a new default block (usually paragraph)
          if (e.key === 'Enter') {
            e.preventDefault();
            const currentIndex = this.api.blocks.getCurrentBlockIndex();
            this.api.blocks.insert();
            this.api.caret.setToBlock(currentIndex + 1, 'start');
          }
          // Handle backspace when empty
          if (e.key === 'Backspace' && this.nodes.body.textContent.trim() === '') {
            e.preventDefault();
            this.api.blocks.delete();
          }
        });

        // Delegate listener to capture changes in the dynamically built right Inspector (#kc-insp-hlevel)
        document.addEventListener('change', this._bindInspector);
        
        // Ensure the dropdown shows correct value when this block is focused
        this.nodes.body.addEventListener('focus', () => {
          this._syncInspectorValue();
        });
      }
      
      this.nodes.wrapper.appendChild(this.nodes.body);
      return this.nodes.wrapper;
    }

    _syncInspectorValue() {
      const select = document.getElementById('kc-insp-hlevel');
      if (select) {
        select.value = this.data.level;
      }
    }

    _bindInspector(e) {
      if (e.target && e.target.id === 'kc-insp-hlevel') {
        const currentIndex = this.api.blocks.getCurrentBlockIndex();
        const currentBlock = this.api.blocks.getBlockByIndex(currentIndex);
        
        // Verify this block is actually the selected block before reacting
        if (currentBlock && currentBlock.id === this.blockApi.id) {
          const newLevel = parseInt(e.target.value, 10);
          if (newLevel >= 2 && newLevel <= 6) {
            this.data.level = newLevel;
            this.nodes.body.className = `kc-heading-level kc-heading-level-${this.data.level}`;
          }
        }
      }
    }

    save() {
      return {
        text: this.nodes.body ? this.nodes.body.innerHTML : (this.data.text || ''),
        level: this.data.level || 2,
        anchor: this.data.anchor || ''
      };
    }

    destroy() {
      document.removeEventListener('change', this._bindInspector);
    }
  }

  if (global.KcEditorRegistry) {
    global.KcEditorRegistry.register('heading', KcHeading, { label: 'Heading', group: 'basic' });
  }
  // Export to global scope for editor js reference if needed
  global.KcHeading = KcHeading;
})(window);
