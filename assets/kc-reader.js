/**
 * Reader Experience, Navigation & Public Frontend Script
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    initToc();
    initMobileDrawer();
    initKeyboardSearch();
    initGlobalSearchModal();
  });

  // Automatically extract H2 and H3 headings into On-This-Page TOC
  function initToc() {
    const articleBody = document.querySelector('.kc-r-article-body');
    const tocList = document.getElementById('kc-r-toc-list');
    if (!articleBody || !tocList) return;

    const headings = Array.from(articleBody.querySelectorAll('h2, h3'));
    if (!headings.length) {
      const widget = document.getElementById('kc-r-toc-widget');
      if (widget) widget.hidden = true;
      return;
    }

    tocList.innerHTML = '';
    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          const id = entry.target.id;
          document.querySelectorAll('.kc-r-toc-item').forEach(item => {
            if (item.dataset.targetId === id) {
              item.classList.add('is-active');
            } else {
              item.classList.remove('is-active');
            }
          });
        }
      });
    }, { rootMargin: '-80px 0px -70% 0px' });

    headings.forEach((h, idx) => {
      if (!h.id) {
        h.id = 'heading-' + idx + '-' + h.textContent.toLowerCase().replace(/[^a-z0-9]+/g, '-');
      }
      observer.observe(h);

      const li = document.createElement('li');
      li.className = 'kc-r-toc-item' + (h.tagName.toLowerCase() === 'h3' ? ' is-h3' : '');
      li.dataset.targetId = h.id;
      li.innerHTML = '<a href="#' + h.id + '">' + escapeHtml(h.textContent) + '</a>';
      tocList.appendChild(li);
    });
  }

  // Mobile Drawer Navigation
  function initMobileDrawer() {
    const toggleBtn = document.getElementById('kc-r-drawer-toggle');
    const sidebar = document.getElementById('kc-r-sidebar-left');
    if (!toggleBtn || !sidebar) return;

    toggleBtn.addEventListener('click', function () {
      sidebar.classList.toggle('is-open');
    });

    document.addEventListener('click', function (e) {
      if (!sidebar.contains(e.target) && !toggleBtn.contains(e.target)) {
        sidebar.classList.remove('is-open');
      }
    });
  }

  // Keyboard shortcut focus on global search input
  function initKeyboardSearch() {
    const searchInput = document.getElementById('kc-r-search-input');
    if (!searchInput) return;

    window.addEventListener('keydown', function (e) {
      const isCmdOrCtrl = e.metaKey || e.ctrlKey;
      if ((isCmdOrCtrl && e.key.toLowerCase() === 'k') || (e.key === '/' && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA')) {
        e.preventDefault();
        openSearchModal();
      }
    });

    searchInput.addEventListener('click', function (e) {
      e.preventDefault();
      openSearchModal();
    });
  }

  // Interactive Global Search Modal Overlay (⌘K)
  function initGlobalSearchModal() {
    const modalHtml = `
      <div class="kc-search-modal-backdrop" id="kc-search-modal" hidden>
        <div class="kc-search-modal-box">
          <div class="kc-search-modal-header">
            <span class="kc-r-search-ico">🔍</span>
            <input type="search" id="kc-modal-query" class="kc-search-modal-input" placeholder="Search documentation, API endpoints, error codes... (Esc to close)" autocomplete="off">
            <button type="button" class="kc-search-modal-close" id="kc-modal-close">&times;</button>
          </div>
          <div class="kc-search-modal-filters">
            <span class="kc-filter-label">Filter:</span>
            <button type="button" class="kc-filter-btn is-active" data-filter-space="all">All Spaces</button>
            <button type="button" class="kc-filter-btn" data-filter-space="files-service">Files Service</button>
            <button type="button" class="kc-filter-btn" data-filter-space="general-docs">General Docs</button>
            <button type="button" class="kc-filter-btn" data-filter-space="tech">Tech</button>
          </div>
          <div class="kc-search-modal-results" id="kc-modal-results">
            <div class="kc-search-empty">Type a query to search across Knowledge Spaces...</div>
          </div>
        </div>
      </div>
    `;

    document.body.insertAdjacentHTML('beforeend', modalHtml);

    const modal = document.getElementById('kc-search-modal');
    const queryInput = document.getElementById('kc-modal-query');
    const closeBtn = document.getElementById('kc-modal-close');
    const resultsContainer = document.getElementById('kc-modal-results');
    let activeFilter = 'all';

    closeBtn.addEventListener('click', closeSearchModal);

    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeSearchModal();
    });

    window.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.hidden) {
        closeSearchModal();
      }
    });

    document.querySelectorAll('.kc-filter-btn').forEach(btn => {
      btn.addEventListener('click', function () {
        document.querySelectorAll('.kc-filter-btn').forEach(b => b.classList.remove('is-active'));
        this.classList.add('is-active');
        activeFilter = this.dataset.filterSpace;
        performSearch(queryInput.value, activeFilter);
      });
    });

    queryInput.addEventListener('input', function () {
      performSearch(this.value, activeFilter);
    });

    function performSearch(q, space) {
      const query = (q || '').trim();
      if (!query) {
        resultsContainer.innerHTML = '<div class="kc-search-empty">Type a query to search across Knowledge Spaces...</div>';
        return;
      }

      fetch('/api/search?q=' + encodeURIComponent(query) + '&space=' + encodeURIComponent(space))
        .then(res => res.json())
        .then(data => {
          const results = data.results || [];
          if (!results.length) {
            resultsContainer.innerHTML = '<div class="kc-search-empty">No matching articles found for "' + escapeHtml(query) + '".</div>';
            return;
          }

          resultsContainer.innerHTML = results.map(item => `
            <a href="${item.view_url}" class="kc-search-result-item">
              <div class="kc-search-result-header">
                <span class="kc-search-space-tag">${escapeHtml(item.space_name || 'Files Service Docs')}</span>
                <span class="kc-search-result-title">${escapeHtml(item.title)}</span>
              </div>
              <p class="kc-search-snippet">${item.snippet || ''}</p>
              <div class="kc-search-breadcrumb">${escapeHtml(item.space_name)} &rsaquo; ${escapeHtml(item.category || 'Docs')} &rsaquo; ${escapeHtml(item.slug)}</div>
            </a>
          `).join('');
        })
        .catch(() => {
          resultsContainer.innerHTML = '<div class="kc-search-empty">Search unavailable.</div>';
        });
    }
  }

  function openSearchModal() {
    const modal = document.getElementById('kc-search-modal');
    const queryInput = document.getElementById('kc-modal-query');
    if (modal && queryInput) {
      modal.hidden = false;
      queryInput.focus();
      queryInput.select();
    }
  }

  function closeSearchModal() {
    const modal = document.getElementById('kc-search-modal');
    if (modal) modal.hidden = true;
  }

  function escapeHtml(str) {
    return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
})();
