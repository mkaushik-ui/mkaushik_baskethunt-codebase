/**
 * Task T4: Columns Tool (Skeleton).
 */
(function (global) {
  'use strict';

  class ColumnsTool {
    constructor({ data, config, api, readOnly }) {
      this.data = Object.assign({
        layout: '50-50',
        columns: [{ content: '' }, { content: '' }]
      }, data || {});
      this.api = api;
      this.readOnly = readOnly;
    }

    static get toolbox() {
      return {
        icon: '▥',
        title: 'Columns'
      };
    }

    render() {
      const wrapper = document.createElement('div');
      wrapper.classList.add('kc-block-columns', `kc-layout-${this.data.layout}`);
      this.data.columns.forEach((col, idx) => {
        const colEl = document.createElement('div');
        colEl.classList.add('kc-col-cell');
        colEl.contentEditable = !this.readOnly;
        colEl.innerHTML = col.content || `Column ${idx + 1}`;
        wrapper.appendChild(colEl);
      });
      return wrapper;
    }

    save(blockContent) {
      const cells = blockContent.querySelectorAll('.kc-col-cell');
      const cols = [];
      cells.forEach(cell => {
        cols.push({ content: cell.innerHTML });
      });
      return {
        layout: this.data.layout,
        columns: cols
      };
    }
  }

  global.KcColumnsTool = ColumnsTool;
})(typeof window !== 'undefined' ? window : this);
