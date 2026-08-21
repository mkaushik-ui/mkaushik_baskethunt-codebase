/**
 * SOI Knowledge Center — Lightweight Public Reader Micro-Runtime (<10KB).
 * Powers client interactivity (Tabs, Accordions, Code Groups) on public reader pages.
 * PUBLIC PAGES MUST NEVER LOAD THE HEAVY KC-EDITOR.JS ENGINE.
 */
(function () {
  'use strict';

  function initTabs() {
    document.querySelectorAll('.kc-tabs-container, .kc-code-group').forEach(container => {
      const tabs = container.querySelectorAll('.kc-tab-nav-btn, .kc-code-tab');
      const panels = container.querySelectorAll('.kc-tab-panel, .kc-code-panel');

      tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => {
          tabs.forEach(t => t.classList.remove('is-active'));
          panels.forEach(p => p.setAttribute('hidden', ''));

          tab.classList.add('is-active');
          if (panels[index]) {
            panels[index].removeAttribute('hidden');
          }
        });
      });
    });
  }

  function initAccordions() {
    // Native HTML <details>/<summary> is used; add optional accordion group mutual-exclusion if needed
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      initTabs();
      initAccordions();
    });
  } else {
    initTabs();
    initAccordions();
  }
})();
