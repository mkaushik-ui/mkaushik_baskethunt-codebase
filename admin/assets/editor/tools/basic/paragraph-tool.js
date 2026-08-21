/**
 * Task T3: Paragraph Tool (Skeleton).
 */
(function (global) {
  'use strict';

  class ParagraphTool {
    constructor({ data, config, api, readOnly }) {
      this.data = data || { text: '' };
      this.api = api;
      this.readOnly = readOnly;
    }

    static get toolbox() {
      return {
        icon: 'P',
        title: 'Paragraph'
      };
    }

    render() {
      const p = document.createElement('div');
      p.classList.add('kc-block-paragraph');
      p.contentEditable = !this.readOnly;
      p.innerHTML = this.data.text || '';
      return p;
    }

    save(blockContent) {
      return {
        text: blockContent.innerHTML
      };
    }
  }

  global.KcParagraphTool = ParagraphTool;
})(typeof window !== 'undefined' ? window : this);
