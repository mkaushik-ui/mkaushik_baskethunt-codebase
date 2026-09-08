/**
 * Knowledge Center Public Reader Script (Task KS-06)
 *
 * Micro-runtime for public reader navigation shell:
 * - Mobile sidebar drawer toggle
 * - Active navigation item & section synchronization
 * - Collapsible navigation section groups
 * - Accessible keyboard navigation hooks
 *
 * @author Saurabh
 */
(function (global) {
  'use strict';

  function initReaderShell() {
    var sidebar = document.getElementById('kcSidebar');
    var toggleBtn = document.getElementById('kcSidebarToggle');

    // 1. Mobile Sidebar Toggle
    if (toggleBtn && sidebar) {
      toggleBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        var isOpen = sidebar.classList.toggle('is-open');
        toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      });

      // Close on clicking outside on mobile
      document.addEventListener('click', function (e) {
        if (sidebar.classList.contains('is-open') && !sidebar.contains(e.target) && e.target !== toggleBtn) {
          sidebar.classList.remove('is-open');
          toggleBtn.setAttribute('aria-expanded', 'false');
        }
      });
    }

    // 2. Collapsible Navigation Groups
    var sectionTitles = document.querySelectorAll('.kc-section-title');
    for (var i = 0; i < sectionTitles.length; i++) {
      (function (title) {
        title.style.cursor = 'pointer';
        title.addEventListener('click', function () {
          var group = title.closest('.kc-nav-group');
          if (group) {
            group.classList.toggle('is-collapsed');
            var list = group.querySelector('.kc-nav-list');
            if (list) {
              if (group.classList.contains('is-collapsed')) {
                list.style.display = 'none';
              } else {
                list.style.display = '';
              }
            }
          }
        });
      })(sectionTitles[i]);
    }

    // 3. Highlight and Ensure Active Navigation Item Visibility
    var activeItem = document.querySelector('.kc-nav-item.is-active, .kc-nav-link.is-active');
    if (activeItem) {
      var parentGroup = activeItem.closest('.kc-nav-group');
      if (parentGroup) {
        parentGroup.classList.remove('is-collapsed');
        parentGroup.classList.add('is-expanded');
        var list = parentGroup.querySelector('.kc-nav-list');
        if (list) {
          list.style.display = '';
        }
      }

      // Scroll active item into view within sidebar
      if (sidebar && typeof activeItem.scrollIntoView === 'function') {
        try {
          var sidebarRect = sidebar.getBoundingClientRect();
          var itemRect = activeItem.getBoundingClientRect();
          if (itemRect.top < sidebarRect.top || itemRect.bottom > sidebarRect.bottom) {
            activeItem.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
          }
        } catch (err) {}
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initReaderShell);
  } else {
    initReaderShell();
  }
})(typeof window !== 'undefined' ? window : this);
