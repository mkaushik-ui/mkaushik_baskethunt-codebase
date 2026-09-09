/**
 * Knowledge Center Public Block Interactivity Micro-Runtime
 * 
 * High-performance, vanilla JavaScript runtime for public documentation readers.
 * Handles ARIA-compliant tab switching, multi-language code groups, and keyboard navigation.
 * Zero external vendor dependencies (Does NOT load Editor.js).
 * 
 * @package KnowledgeCenter
 * @version 1.1.0
 */
(function (root, factory) {
  'use strict';
  if (typeof define === 'function' && define.amd) {
    define([], factory);
  } else if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.KCPublic = factory();
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  var ROOT_SELECTOR = '.kc-block-tabs, .kc-block-codegroup, .kc-code-group, [data-interactive="tabs"]';
  var TAB_SELECTOR = '[role="tab"], .kc-tab-btn, .kc-tabs-tab, .kc-code-tab, .kc-codegroup-tab, [data-kc-tab], [data-tab-index], [data-tab-idx]';
  var PANEL_SELECTOR = '[role="tabpanel"], .kc-tab-panel, .kc-tabs-panel, .kc-code-panel, .kc-codegroup-panel, pre.kc-codegroup-pre, pre.kc-code-panel, [data-kc-panel], [data-tab-index], [data-panel-idx]';

  /**
   * Helper to retrieve all tabs within a root container.
   */
  function getTabs(container) {
    return Array.prototype.slice.call(container.querySelectorAll(TAB_SELECTOR));
  }

  /**
   * Helper to retrieve all panels within a root container.
   */
  function getPanels(container) {
    return Array.prototype.slice.call(container.querySelectorAll(PANEL_SELECTOR));
  }

  /**
   * Resolve tab index from either attribute or array position.
   */
  function getTabIndex(tab, allTabs) {
    var raw = tab.getAttribute('data-kc-tab') ||
              tab.getAttribute('data-tab-index') ||
              tab.getAttribute('data-tab-idx');
    if (raw !== null && raw !== '') {
      var parsed = parseInt(raw, 10);
      if (!isNaN(parsed)) return parsed;
    }
    return allTabs.indexOf(tab);
  }

  /**
   * Resolve panel index from attribute or array position.
   */
  function getPanelIndex(panel, allPanels) {
    var raw = panel.getAttribute('data-kc-panel') ||
              panel.getAttribute('data-tab-index') ||
              panel.getAttribute('data-panel-idx');
    if (raw !== null && raw !== '') {
      var parsed = parseInt(raw, 10);
      if (!isNaN(parsed)) return parsed;
    }
    return allPanels.indexOf(panel);
  }

  /**
   * Activate a specific tab and its corresponding panel in a container.
   *
   * @param {HTMLElement} container - The tabs or codegroup container
   * @param {HTMLElement|number|string} target - The tab element or target index
   * @return {boolean} True if activated
   */
  function activate(container, target) {
    if (!container || !container.querySelectorAll) return false;

    var tabs = getTabs(container);
    var panels = getPanels(container);
    if (!tabs.length || !panels.length) return false;

    var targetIndex = -1;

    if (typeof target === 'object' && target !== null && target.nodeType === 1) {
      targetIndex = getTabIndex(target, tabs);
      if (targetIndex < 0) targetIndex = tabs.indexOf(target);
    } else if (typeof target === 'number') {
      targetIndex = target;
    } else if (typeof target === 'string') {
      var parsed = parseInt(target, 10);
      targetIndex = isNaN(parsed) ? 0 : parsed;
    }

    if (targetIndex < 0 || targetIndex >= tabs.length) {
      targetIndex = 0;
    }

    // 1. Update Tabs
    tabs.forEach(function (tab, i) {
      var thisIndex = getTabIndex(tab, tabs);
      var isSelected = (thisIndex === targetIndex) || (i === targetIndex && thisIndex < 0);

      tab.classList.toggle('is-active', isSelected);
      tab.setAttribute('aria-selected', isSelected ? 'true' : 'false');
      tab.tabIndex = isSelected ? 0 : -1;
    });

    // 2. Update Panels
    panels.forEach(function (panel, i) {
      var thisIndex = getPanelIndex(panel, panels);
      var isMatch = (thisIndex === targetIndex) || (i === targetIndex && thisIndex < 0);

      panel.classList.toggle('is-active', isMatch);
      if (isMatch) {
        panel.removeAttribute('hidden');
        panel.setAttribute('aria-hidden', 'false');
      } else {
        panel.setAttribute('hidden', '');
        panel.setAttribute('aria-hidden', 'true');
      }
    });

    return true;
  }

  /**
   * Ensure standard ARIA attributes exist across all tab components.
   */
  function ensureAria(container) {
    var tabs = getTabs(container);
    var panels = getPanels(container);
    if (!tabs.length) return;

    var tablist = container.querySelector('[role="tablist"]') ||
                  container.querySelector('.kc-tabs-header, .kc-tabs, .kc-code-tabs, .kc-codegroup-tabs');
    if (tablist && !tablist.getAttribute('role')) {
      tablist.setAttribute('role', 'tablist');
    }

    var hasActiveTab = false;
    tabs.forEach(function (tab, i) {
      if (!tab.getAttribute('role')) tab.setAttribute('role', 'tab');
      var isActive = tab.classList.contains('is-active') || tab.getAttribute('aria-selected') === 'true';
      if (isActive && !hasActiveTab) {
        hasActiveTab = true;
        tab.setAttribute('aria-selected', 'true');
        tab.tabIndex = 0;
      } else {
        tab.setAttribute('aria-selected', 'false');
        tab.tabIndex = -1;
      }
    });

    panels.forEach(function (panel, i) {
      if (!panel.getAttribute('role')) panel.setAttribute('role', 'tabpanel');
    });

    // Activate the first tab if none was active
    if (!hasActiveTab && tabs.length > 0) {
      activate(container, tabs[0]);
    } else {
      var activeTab = tabs.filter(function (t) { return t.getAttribute('aria-selected') === 'true'; })[0];
      if (activeTab) {
        activate(container, activeTab);
      }
    }
  }

  /**
   * Handle Click event delegation for tabs.
   */
  function handleClick(event) {
    var tab = event.target.closest(TAB_SELECTOR);
    if (!tab) return;

    var container = tab.closest(ROOT_SELECTOR);
    if (!container) return;

    event.preventDefault();
    activate(container, tab);
  }

  /**
   * Handle Keyboard navigation for tabs.
   * Supports ArrowRight/ArrowDown (next) and ArrowLeft/ArrowUp (prev) with wrap-around.
   */
  function handleKeyDown(event) {
    var tab = event.target.closest(TAB_SELECTOR);
    if (!tab) return;

    var container = tab.closest(ROOT_SELECTOR);
    if (!container) return;

    var tabs = getTabs(container);
    if (tabs.length <= 1) return;

    var currentIndex = tabs.indexOf(tab);
    if (currentIndex === -1) {
      currentIndex = getTabIndex(tab, tabs);
    }
    if (currentIndex === -1) return;

    var nextIndex = -1;

    switch (event.key) {
      case 'ArrowRight':
      case 'ArrowDown':
        event.preventDefault();
        nextIndex = (currentIndex + 1) % tabs.length;
        break;
      case 'ArrowLeft':
      case 'ArrowUp':
        event.preventDefault();
        nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
        break;
      case 'Home':
        event.preventDefault();
        nextIndex = 0;
        break;
      case 'End':
        event.preventDefault();
        nextIndex = tabs.length - 1;
        break;
      default:
        return;
    }

    if (nextIndex >= 0 && nextIndex < tabs.length) {
      var targetTab = tabs[nextIndex];
      targetTab.focus();
      activate(container, targetTab);
    }
  }

  /**
   * Initialize all tabs and code groups on the page.
   */
  function init(scope) {
    var rootEl = scope || document;
    var containers = Array.prototype.slice.call(rootEl.querySelectorAll(ROOT_SELECTOR));
    containers.forEach(function (container) {
      ensureAria(container);
    });
  }

  // Register global event listeners once
  if (typeof document !== 'undefined') {
    document.addEventListener('click', handleClick);
    document.addEventListener('keydown', handleKeyDown);

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function () {
        init();
      });
    } else {
      init();
    }
  }

  return {
    version: '1.1.0',
    activate: activate,
    init: init,
    getTabs: getTabs,
    getPanels: getPanels
  };
});
