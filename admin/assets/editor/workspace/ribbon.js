/**
 * Task T2: Progressive Ribbon Tabs Manager (Skeleton).
 * Handles Home, Insert, Layout, Components, Document, Review tabs.
 */
(function (global) {
  'use strict';

  class RibbonManager {
    constructor(hostEl) {
      this.host = typeof hostEl === 'string' ? document.getElementById(hostEl) : hostEl;
      this.activeTab = 'home';
      this.tabs = ['home', 'insert', 'layout', 'components', 'document', 'review'];
    }

    init() {
      if (!this.host) return;
      this.render();
      this.bindEvents();
    }

    render() {
      // Developer 2: Flesh out ribbon toolbar buttons per active tab
      this.host.innerHTML = `
        <div class="kc-ribbon-tabs" role="tablist">
          ${this.tabs.map(tab => `
            <button type="button" class="kc-ribbon-tab ${tab === this.activeTab ? 'is-active' : ''}" data-tab="${tab}">
              ${tab.charAt(0).toUpperCase() + tab.slice(1)}
            </button>
          `).join('')}
        </div>
        <div class="kc-ribbon-toolbar" id="kc-ribbon-toolbar">
          <!-- Active tab controls rendered here -->
        </div>
      `;
    }

    switchTab(tabName) {
      if (!this.tabs.includes(tabName)) return;
      this.activeTab = tabName;
      this.render();
    }

    bindEvents() {
      this.host.addEventListener('click', (e) => {
        const tabBtn = e.target.closest('.kc-ribbon-tab');
        if (tabBtn && tabBtn.dataset.tab) {
          this.switchTab(tabBtn.dataset.tab);
        }
      });
    }
  }

  global.KcRibbonManager = RibbonManager;
})(typeof window !== 'undefined' ? window : this);
