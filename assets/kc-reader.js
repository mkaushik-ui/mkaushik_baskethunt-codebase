/**
 * SOI Knowledge Center — Master Public Reader Runtime
 * Location: assets/kc-reader.js
 * Domain: kc.soi.co.in
 *
 * Harmonized Client Micro-Runtime for Workstream C:
 * 1. Mobile Sidebar Drawer Toggle & Overlay Management (Baseline KS-06)
 * 2. Collapsible Navigation Section Groups & Active Item Auto-Scroll (Baseline KS-06)
 * 3. Automatic Code Copy Button Injection & A11y Live Region (Task RC-02)
 * 4. "On This Page" Table of Contents Scroll-Spy & Smooth Anchor Scroll (Task RC-03)
 * 5. Sidebar Live Search Filter & Tech Product Version Switcher (Task RC-04)
 * 6. Accessible Dark / Light Mode Reader Theme Engine & Storage Persistence (Task RC-05)
 *
 * Zero external vendor dependencies (<6KB minified).
 */

(function (global) {
  'use strict';

  var LIVE_REGION_ID = 'kc-reader-a11y-live-region';
  var THEME_STORAGE_KEY = 'kc-reader-theme';
  var REVERT_TIMEOUT_MS = 2000;

  // ==========================================================================
  // 1. Accessibility Live Region Announcement (RC-02)
  // ==========================================================================
  function getLiveRegion() {
    if (typeof document === 'undefined') return null;
    var region = document.getElementById(LIVE_REGION_ID);
    if (!region) {
      region = document.createElement('div');
      region.id = LIVE_REGION_ID;
      region.setAttribute('role', 'status');
      region.setAttribute('aria-live', 'polite');
      region.setAttribute('aria-atomic', 'true');
      region.className = 'kc-sr-only';
      document.body.appendChild(region);
    }
    return region;
  }

  function announce(message) {
    var region = getLiveRegion();
    if (!region) return;
    region.textContent = '';
    setTimeout(function () {
      region.textContent = message;
    }, 50);
  }

  // ==========================================================================
  // 2. Mobile Drawer Toggle & Collapsible Navigation (KS-06)
  // ==========================================================================
  function initSidebarNavigation() {
    var sidebar = document.getElementById('kcSidebar');
    var toggleBtn = document.getElementById('kcSidebarToggle');

    // Mobile Sidebar Drawer Toggle
    if (toggleBtn && sidebar) {
      toggleBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        var isOpen = sidebar.classList.toggle('is-open');
        toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      });

      document.addEventListener('click', function (e) {
        if (sidebar.classList.contains('is-open') && !sidebar.contains(e.target) && e.target !== toggleBtn) {
          sidebar.classList.remove('is-open');
          toggleBtn.setAttribute('aria-expanded', 'false');
        }
      });
    }

    // Collapsible Section Groups
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
              list.style.display = group.classList.contains('is-collapsed') ? 'none' : '';
            }
          }
        });
      })(sectionTitles[i]);
    }

    // Active Navigation Item Auto-Scroll & Section Expansion
    var activeItem = document.querySelector('.kc-nav-item.is-active, .kc-nav-link.is-active');
    if (activeItem) {
      var parentGroup = activeItem.closest('.kc-nav-group');
      if (parentGroup) {
        parentGroup.classList.remove('is-collapsed');
        parentGroup.classList.add('is-expanded');
        var list = parentGroup.querySelector('.kc-nav-list');
        if (list) list.style.display = '';
      }

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

  // ==========================================================================
  // 3. Code Copy Button Injection Engine (Task RC-02)
  // ==========================================================================
  function extractCodeText(preElement) {
    if (!preElement) return '';
    var codeEl = preElement.querySelector('code');
    var raw = codeEl ? (codeEl.innerText || codeEl.textContent) : (preElement.innerText || preElement.textContent);
    return (raw || '').replace(/\r\n/g, '\n').replace(/\n+$/, '');
  }

  function copyFallback(text) {
    if (typeof document === 'undefined') return false;
    var textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.top = '-9999px';
    textarea.style.left = '-9999px';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();

    var success = false;
    try {
      success = document.execCommand('copy');
    } catch (err) {
      success = false;
    }
    document.body.removeChild(textarea);
    return success;
  }

  function copyText(text) {
    if (typeof navigator !== 'undefined' && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      return navigator.clipboard.writeText(text).then(
        function () { return true; },
        function () { return copyFallback(text); }
      );
    }
    return Promise.resolve(copyFallback(text));
  }

  function handleCopyClick(btn, preElement) {
    if (!btn || !preElement) return;
    var text = extractCodeText(preElement);
    if (!text) return;

    var origText = btn.getAttribute('data-orig-text') || 'Copy';
    if (!btn.getAttribute('data-orig-text')) {
      btn.setAttribute('data-orig-text', origText);
    }

    if (btn._revertTimer) {
      clearTimeout(btn._revertTimer);
      btn._revertTimer = null;
    }

    copyText(text).then(function (success) {
      if (success) {
        btn.textContent = '✓ Copied!';
        btn.classList.add('is-copied');
        btn.setAttribute('aria-label', 'Code copied to clipboard');
        announce('Code copied to clipboard');
      } else {
        btn.textContent = 'Failed';
        btn.setAttribute('aria-label', 'Failed to copy code');
        announce('Failed to copy code to clipboard');
      }

      btn._revertTimer = setTimeout(function () {
        btn.textContent = origText;
        btn.classList.remove('is-copied');
        btn.setAttribute('aria-label', 'Copy code to clipboard');
        btn._revertTimer = null;
      }, REVERT_TIMEOUT_MS);
    });
  }

  function injectCopyButtons(scope) {
    var rootEl = scope || document;
    var preElements = rootEl.querySelectorAll('.kc-block-code pre, .kc-codegroup-pre, pre.kc-code-pre, pre.kc-code-panel, .kc-block-codegroup pre');

    preElements.forEach(function (pre) {
      if (pre.getAttribute('data-kc-copy-ready') === 'true' || pre.querySelector('.kc-copy-code-btn')) {
        return;
      }

      var style = window.getComputedStyle ? window.getComputedStyle(pre) : pre.currentStyle;
      if (!style || style.position === 'static') {
        pre.style.position = 'relative';
      }

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'kc-copy-code-btn';
      btn.textContent = 'Copy';
      btn.setAttribute('aria-label', 'Copy code to clipboard');
      btn.setAttribute('title', 'Copy code to clipboard');

      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        e.preventDefault();
        handleCopyClick(btn, pre);
      });

      pre.appendChild(btn);
      pre.setAttribute('data-kc-copy-ready', 'true');
    });
  }

  // ==========================================================================
  // 4. "On This Page" Table of Contents Scroll-Spy (Task RC-03)
  // ==========================================================================
  function initTableOfContents() {
    var tocRail = document.getElementById('kcToc');
    if (!tocRail) return;

    var tocLinks = Array.prototype.slice.call(tocRail.querySelectorAll('.kc-toc-link'));
    if (!tocLinks.length) return;

    // Collect corresponding heading elements in document article
    var headingElements = [];
    tocLinks.forEach(function (link) {
      var href = link.getAttribute('href');
      if (href && href.charAt(0) === '#') {
        var targetId = href.slice(1);
        var el = document.getElementById(targetId);
        if (el) {
          headingElements.push({ id: targetId, element: el, link: link });
        }
      }
    });

    if (!headingElements.length) return;

    // Smooth Scrolling with sticky header compensation
    tocLinks.forEach(function (link) {
      link.addEventListener('click', function (e) {
        var href = link.getAttribute('href');
        if (href && href.charAt(0) === '#') {
          var target = document.getElementById(href.slice(1));
          if (target) {
            e.preventDefault();
            var headerHeight = 75;
            var elementPosition = target.getBoundingClientRect().top + window.pageYOffset;
            var offsetPosition = elementPosition - headerHeight;

            window.scrollTo({
              top: offsetPosition,
              behavior: 'smooth'
            });

            if (history.pushState) {
              history.pushState(null, '', href);
            }
          }
        }
      });
    });

    // Scroll-Spy via IntersectionObserver
    if (typeof IntersectionObserver !== 'undefined') {
      var observerOptions = {
        root: null,
        rootMargin: '0px 0px -70% 0px',
        threshold: 0
      };

      var activeId = '';
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            activeId = entry.target.id;
          }
        });

        if (activeId) {
          tocLinks.forEach(function (link) {
            var isCurrent = link.getAttribute('href') === '#' + activeId;
            link.classList.toggle('is-active', isCurrent);
            var parentItem = link.closest('.kc-toc-item');
            if (parentItem) {
              parentItem.classList.toggle('is-active', isCurrent);
            }
          });
        }
      }, observerOptions);

      headingElements.forEach(function (item) {
        observer.observe(item.element);
      });
    }
  }

  // ==========================================================================
  // 5. Sidebar Live Search & Version Switcher (Task RC-04)
  // ==========================================================================
  function initSidebarSearchAndVersions() {
    // 1. Sidebar Live Search Filtering
    var searchInput = document.getElementById('kcSidebarSearch');
    if (searchInput) {
      searchInput.addEventListener('input', function () {
        var query = searchInput.value.trim().toLowerCase();
        var groups = document.querySelectorAll('.kc-nav-group');

        groups.forEach(function (group) {
          var items = group.querySelectorAll('.kc-nav-item');
          var visibleCount = 0;

          items.forEach(function (item) {
            var text = item.textContent.toLowerCase();
            if (query === '' || text.indexOf(query) !== -1) {
              item.style.display = '';
              visibleCount++;
            } else {
              item.style.display = 'none';
            }
          });

          var list = group.querySelector('.kc-nav-list');
          if (list) {
            if (query === '') {
              list.style.display = group.classList.contains('is-collapsed') ? 'none' : '';
            } else {
              list.style.display = visibleCount > 0 ? '' : 'none';
              if (visibleCount > 0) {
                group.classList.remove('is-collapsed');
                group.classList.add('is-expanded');
              }
            }
          }
        });
      });
    }

    // 2. Technical Space Version Switcher
    var versionSelect = document.getElementById('kcVersionSelect');
    if (versionSelect) {
      versionSelect.addEventListener('change', function () {
        var selectedVersion = versionSelect.value;
        var currentUrl = new URL(window.location.href);
        if (selectedVersion) {
          currentUrl.searchParams.set('v', selectedVersion);
        } else {
          currentUrl.searchParams.delete('v');
        }
        window.location.href = currentUrl.toString();
      });
    }
  }

  // ==========================================================================
  // 6. Accessible Dark / Light Mode Theme Engine (Task RC-05)
  // ==========================================================================
  function initThemeEngine() {
    var toggleBtn = document.getElementById('kcThemeToggle');
    if (!toggleBtn) return;

    function getActiveTheme() {
      var attr = document.documentElement.getAttribute('data-theme');
      if (attr === 'dark' || attr === 'light') return attr;
      return document.documentElement.classList.contains('theme-dark') ? 'dark' : 'light';
    }

    function applyTheme(targetTheme, persist) {
      var theme = (targetTheme === 'dark') ? 'dark' : 'light';
      document.documentElement.setAttribute('data-theme', theme);
      if (theme === 'dark') {
        document.documentElement.classList.add('theme-dark');
      } else {
        document.documentElement.classList.remove('theme-dark');
      }

      var nextLabel = (theme === 'dark') ? 'Switch to light theme' : 'Switch to dark theme';
      toggleBtn.setAttribute('aria-label', nextLabel);
      toggleBtn.setAttribute('title', nextLabel);

      if (persist) {
        try {
          localStorage.setItem(THEME_STORAGE_KEY, theme);
        } catch (e) {}
      }
    }

    // Sync button state on initialization
    var currentTheme = getActiveTheme();
    applyTheme(currentTheme, false);

    // Click handler
    toggleBtn.addEventListener('click', function (e) {
      e.preventDefault();
      var newTheme = (getActiveTheme() === 'dark') ? 'light' : 'dark';
      applyTheme(newTheme, true);
      announce('Color theme changed to ' + newTheme + ' mode');
    });

    // Listen to system preference changes if user hasn't explicitly set localStorage
    if (window.matchMedia) {
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
        try {
          if (!localStorage.getItem(THEME_STORAGE_KEY)) {
            applyTheme(e.matches ? 'dark' : 'light', false);
          }
        } catch (err) {}
      });
    }
  }

  // ==========================================================================
  // Master Reader Lifecycle Bootstrap
  // ==========================================================================
  function initReaderSuite() {
    getLiveRegion();
    initSidebarNavigation();
    injectCopyButtons();
    initTableOfContents();
    initSidebarSearchAndVersions();
    initThemeEngine();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initReaderSuite);
  } else {
    initReaderSuite();
  }

  global.KCReader = {
    version: '1.3.0',
    init: initReaderSuite,
    injectCopyButtons: injectCopyButtons,
    copyText: copyText
  };

})(typeof window !== 'undefined' ? window : this);
