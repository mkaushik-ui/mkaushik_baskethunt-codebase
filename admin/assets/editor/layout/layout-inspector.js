/**
 * Task T4: Layout Settings Inspector (Skeleton).
 * Exposes governed column presets and gap controls.
 */
(function (global) {
  'use strict';

  class LayoutInspector {
    constructor() {
      this.presets = ['50-50', '33-67', '67-33', 'thirds', 'quarters'];
    }

    render(blockData, onUpdate) {
      const container = document.createElement('div');
      container.classList.add('kc-layout-inspector');
      container.innerHTML = `
        <label>Column Layout Ratio</label>
        <select class="kc-select" id="kc-layout-ratio-select">
          ${this.presets.map(p => `<option value="${p}" ${p === (blockData.layout || '50-50') ? 'selected' : ''}>${p}</option>`).join('')}
        </select>
      `;
      const select = container.querySelector('#kc-layout-ratio-select');
      select.addEventListener('change', (e) => {
        if (typeof onUpdate === 'function') {
          onUpdate({ layout: e.target.value });
        }
      });
      return container;
    }
  }

  global.KcLayoutInspector = LayoutInspector;
})(typeof window !== 'undefined' ? window : this);
