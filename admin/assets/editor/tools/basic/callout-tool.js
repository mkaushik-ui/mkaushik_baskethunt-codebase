/**
 * Task T3: Callout Tool (Skeleton).
 */
(function (global) {
  'use strict';

  class CalloutTool {
    constructor({ data, config, api, readOnly }) {
      this.data = Object.assign({ tone: 'info', title: '', text: '' }, data || {});
      this.api = api;
      this.readOnly = readOnly;
    }

    static get toolbox() {
      return {
        icon: '!',
        title: 'Callout'
      };
    }

    render() {
      const container = document.createElement('div');
      container.classList.add('kc-block-callout', `kc-callout-${this.data.tone}`);
      container.innerHTML = `
        <div class="kc-callout-head"><strong>[${this.data.tone.toUpperCase()}]</strong> <span class="kc-callout-title" contenteditable="${!this.readOnly}">${this.data.title}</span></div>
        <div class="kc-callout-body" contenteditable="${!this.readOnly}">${this.data.text}</div>
      `;
      return container;
    }

    save(blockContent) {
      const titleEl = blockContent.querySelector('.kc-callout-title');
      const bodyEl = blockContent.querySelector('.kc-callout-body');
      return {
        tone: this.data.tone,
        title: titleEl ? titleEl.innerHTML : '',
        text: bodyEl ? bodyEl.innerHTML : ''
      };
    }
  }

  global.KcCalloutTool = CalloutTool;
})(typeof window !== 'undefined' ? window : this);
