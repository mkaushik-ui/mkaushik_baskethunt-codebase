/**
 * Task T2: Navigator Shell Component (Skeleton).
 * Renders document outline tree, component library catalog, and patterns tabs.
 */
(function (global) {
  'use strict';

  class NavigatorShell {
    constructor(hostEl) {
      this.host = typeof hostEl === 'string' ? document.getElementById(hostEl) : hostEl;
      this.activeTab = 'components'; // 'components', 'navigator', 'patterns', 'reusable'
    }

    init() {
      if (!this.host) return;
      this.render();
      this.bindEvents();
    }

    render() {
      this.host.innerHTML = `
        <div class="kc-nav-tabs">
          <button type="button" class="kc-nav-tab ${this.activeTab === 'components' ? 'is-active' : ''}" data-nav="components">Components</button>
          <button type="button" class="kc-nav-tab ${this.activeTab === 'navigator' ? 'is-active' : ''}" data-nav="navigator">Navigator</button>
          <button type="button" class="kc-nav-tab ${this.activeTab === 'patterns' ? 'is-active' : ''}" data-nav="patterns">Patterns</button>
        </div>
        <div class="kc-nav-content" id="kc-nav-content">
          <!-- Active tab content rendered here -->
        </div>
      `;
    }

    bindEvents() {
      this.host.addEventListener('click', (e) => {
        const tabBtn = e.target.closest('.kc-nav-tab');
        if (tabBtn && tabBtn.dataset.nav) {
          this.activeTab = tabBtn.dataset.nav;
          this.render();
        }
      });
    }
  }

  global.KcNavigatorShell = NavigatorShell;
})(typeof window !== 'undefined' ? window : this);
