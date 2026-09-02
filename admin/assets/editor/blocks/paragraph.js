/**
 * Knowledge Center Paragraph Block (Task A1-T01).
 * Standard text block supporting rich inline formatting, enter key line break, and backspace merge.
 */
(function (global) {
  'use strict';

  class KcParagraph {
    static get toolbox() {
      return {
        title: 'Paragraph',
        icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M7 4h10M7 4v16M13 4v16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
      };
    }

    static get isReadOnlySupported() { return true; }
    
    static get conversionConfig() { 
      return { export: 'text', import: 'text' }; 
    }
    
    static get sanitize() {
      return {
        text: {
          br: true,
          b: true,
          strong: true,
          i: true,
          em: true,
          u: true,
          s: true,
          code: true,
          a: { href: true }
        }
      };
    }

    constructor({ data, api, readOnly }) {
      this.api = api;
      this.readOnly = !!readOnly;
      this.data = {
        text: data.text || ''
      };
      this.nodes = { wrapper: null, body: null };
    }

    render() {
      this.nodes.wrapper = document.createElement('div');
      this.nodes.wrapper.className = 'kc-tool kc-tool-paragraph';

      this.nodes.body = document.createElement('div');
      this.nodes.body.className = 'kc-tool-body kc-paragraph-body';
      this.nodes.body.contentEditable = this.readOnly ? 'false' : 'true';
      this.nodes.body.dataset.placeholder = 'Type / to choose a block or start writing...';
      this.nodes.body.innerHTML = this.data.text;

      if (!this.readOnly) {
        this.nodes.body.addEventListener('input', () => {
          this.data.text = this.nodes.body.innerHTML;
        });
        
        // Listen to Enter and Backspace to ensure compatibility with custom framework overrides
        this.nodes.body.addEventListener('keydown', (e) => this.handleKeyDown(e));
      }

      this.nodes.wrapper.appendChild(this.nodes.body);
      return this.nodes.wrapper;
    }

    handleKeyDown(e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        // We let the Editor.js core handle block splitting normally if present, 
        // but if we are building custom extensions, we might intercept here.
        // For Editor.js standard behavior, bubbling is usually fine unless we explicitly need to stop it.
      }
    }

    merge(data) {
      this.data.text += data.text || '';
      if (this.nodes.body) {
        this.nodes.body.innerHTML = this.data.text;
      }
    }

    save() {
      return {
        text: this.nodes.body ? this.nodes.body.innerHTML : (this.data.text || '')
      };
    }
  }

  // Register in the custom Editor ecosystem
  if (global.KcEditorRegistry) {
    global.KcEditorRegistry.register('paragraph', KcParagraph, { label: 'Paragraph', group: 'basic' });
  }
  
  global.KcParagraph = KcParagraph;
})(window);
