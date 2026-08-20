/**
 * Minimal public interactivity for Knowledge Center structured blocks.
 * Does not load Editor.js.
 */
(function () {
  'use strict';
  function activate(root, index) {
    root.querySelectorAll('[role="tab"]').forEach(function (tab) {
      var on = String(tab.getAttribute('data-kc-tab')) === String(index);
      tab.classList.toggle('is-active', on);
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
      tab.tabIndex = on ? 0 : -1;
    });
    root.querySelectorAll('[role="tabpanel"]').forEach(function (panel) {
      var on = String(panel.getAttribute('data-kc-panel')) === String(index);
      panel.classList.toggle('is-active', on);
      if (on) panel.removeAttribute('hidden');
      else panel.setAttribute('hidden', '');
    });
  }
  document.addEventListener('click', function (event) {
    var tab = event.target.closest('[data-kc-tab]');
    if (!tab) return;
    var root = tab.closest('.kc-block-tabs, .kc-block-codegroup');
    if (!root) return;
    event.preventDefault();
    activate(root, tab.getAttribute('data-kc-tab'));
  });
  document.addEventListener('keydown', function (event) {
    var tab = event.target.closest('[data-kc-tab]');
    if (!tab) return;
    var root = tab.closest('.kc-block-tabs, .kc-block-codegroup');
    if (!root) return;
    var tabs = Array.prototype.slice.call(root.querySelectorAll('[role="tab"]'));
    var i = tabs.indexOf(tab);
    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
      event.preventDefault();
      var next = tabs[(i + 1) % tabs.length];
      next.focus();
      activate(root, next.getAttribute('data-kc-tab'));
    } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
      event.preventDefault();
      var prev = tabs[(i - 1 + tabs.length) % tabs.length];
      prev.focus();
      activate(root, prev.getAttribute('data-kc-tab'));
    }
  });
})();
