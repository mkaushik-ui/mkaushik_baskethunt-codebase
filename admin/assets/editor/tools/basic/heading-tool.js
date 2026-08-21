/**
 * Task T3: Heading Tool (Skeleton).
 */
(function (global) {
  'use strict';

  class HeadingTool {
    constructor({ data, config, api, readOnly }) {
      this.data = Object.assign({ text: '', level: 2, anchor: '' }, data || {});
      this.api = api;
      this.readOnly = readOnly;
    }

    static get toolbox() {
      return {
        icon: 'H',
        title: 'Heading'
      };
    }

    render() {
      const h = document.createElement(`h${this.data.level}`);
      h.classList.add('kc-block-heading');
      h.contentEditable = !this.readOnly;
      h.innerHTML = this.data.text || '';
      return h;
    }

    save(blockContent) {
      return {
        text: blockContent.innerHTML,
        level: this.data.level,
        anchor: this.data.anchor
      };
    }
  }

  global.KcHeadingTool = HeadingTool;
})(typeof window !== 'undefined' ? window : this);
