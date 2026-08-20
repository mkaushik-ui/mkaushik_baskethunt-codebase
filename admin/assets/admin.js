/* SOI CMS — Admin JavaScript (Phase 1.1.3 Enterprise Console UI) */

document.addEventListener('DOMContentLoaded', function () {

  const MOBILE_BP = 900;
  const body = document.body;
  const toggle = document.querySelector('.mobile-toggle');
  const sidebar = document.getElementById('admin-sidebar');
  const overlay = document.querySelector('.admin-sidebar-overlay');

  function setDrawer(open) {
    body.classList.toggle('admin-sidebar-open', open);
    if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function toggleDrawer() {
    setDrawer(!body.classList.contains('admin-sidebar-open'));
  }

  function closeDrawer() {
    setDrawer(false);
  }

  if (toggle) toggle.addEventListener('click', toggleDrawer);
  if (overlay) overlay.addEventListener('click', closeDrawer);

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeDrawer();
  });

  if (sidebar) {
    sidebar.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        if (window.innerWidth <= MOBILE_BP) closeDrawer();
      });
    });
  }

  window.matchMedia('(max-width: ' + MOBILE_BP + 'px)').addEventListener('change', function (e) {
    if (!e.matches) closeDrawer();
  });

  document.querySelectorAll('.alert[data-autodismiss]').forEach(el => {
    setTimeout(() => {
      el.style.transition = 'opacity 0.5s';
      el.style.opacity = '0';
      setTimeout(() => el.remove(), 500);
    }, 4000);
  });

  document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', function (e) {
      const msg = this.dataset.confirm || 'Are you sure?';
      if (!confirm(msg)) e.preventDefault();
    });
  });

  const titleInput = document.getElementById('post-title') || document.getElementById('page-title');
  const slugInput  = document.getElementById('post-slug')  || document.getElementById('page-slug');
  let slugManuallyEdited = false;

  if (slugInput && slugInput.value) slugManuallyEdited = true;

  if (titleInput && slugInput) {
    titleInput.addEventListener('input', function () {
      if (!slugManuallyEdited) slugInput.value = slugify(this.value);
    });
    slugInput.addEventListener('input', function () { slugManuallyEdited = true; });
    slugInput.addEventListener('blur', function () { this.value = slugify(this.value); });
  }

  function slugify(str) {
    return str.toLowerCase()
      .replace(/[^\w\s-]/g, '')
      .replace(/[\s_]+/g, '-')
      .replace(/^-+|-+$/g, '');
  }

  const uploadZone = document.querySelector('.upload-zone');
  const fileInput  = document.querySelector('.upload-file-input');

  if (uploadZone && fileInput) {
    uploadZone.addEventListener('click', () => fileInput.click());
    uploadZone.addEventListener('dragover', (e) => { e.preventDefault(); uploadZone.classList.add('drag-over'); });
    uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('drag-over'));
    uploadZone.addEventListener('drop', (e) => {
      e.preventDefault();
      uploadZone.classList.remove('drag-over');
      if (e.dataTransfer.files.length) {
        const dt = new DataTransfer();
        for (const file of e.dataTransfer.files) dt.items.add(file);
        fileInput.files = dt.files;
        uploadZone.querySelector('.upload-zone-text').textContent = `${e.dataTransfer.files.length} file(s) selected`;
      }
    });
    fileInput.addEventListener('change', function () {
      if (this.files.length) {
        uploadZone.querySelector('.upload-zone-text').textContent = `${this.files.length} file(s) selected — ready to upload`;
      }
    });
  }

  const updateForm = document.getElementById('update-form');
  const updateProgress = document.querySelector('.update-progress');
  if (updateForm && updateProgress) {
    updateForm.addEventListener('submit', function () { updateProgress.style.display = 'block'; });
  }

  const smtpTestBtn = document.getElementById('smtp-test-btn');
  if (smtpTestBtn) {
    smtpTestBtn.addEventListener('click', async function () {
      const btn = this;
      const resultEl = document.getElementById('smtp-test-result');
      const form = document.getElementById('smtp-form');
      btn.disabled = true;
      btn.textContent = 'Sending...';
      const formData = new FormData(form);
      formData.append('action', 'test');
      try {
        const res = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await res.json();
        if (resultEl) {
          resultEl.className = 'alert ' + (data.success ? 'alert-success' : 'alert-error');
          resultEl.textContent = data.success ? 'Test email sent successfully!' : (data.error || 'Failed to send test email.');
          resultEl.style.display = 'flex';
        }
      } catch (err) {
        if (resultEl) {
          resultEl.className = 'alert alert-error';
          resultEl.textContent = 'Request failed: ' + err.message;
          resultEl.style.display = 'flex';
        }
      }
      btn.disabled = false;
      btn.textContent = 'Send Test Email';
    });
  }

  initSortable('.menu-item-list');

  function initSortable(selector) {
    const list = document.querySelector(selector);
    if (!list) return;
    let dragging = null;
    list.querySelectorAll('.menu-item-row').forEach(item => {
      item.draggable = true;
      item.addEventListener('dragstart', () => {
        dragging = item;
        setTimeout(() => item.style.opacity = '0.4', 0);
      });
      item.addEventListener('dragend', () => {
        item.style.opacity = '1';
        dragging = null;
        updateSortOrder(list);
      });
      item.addEventListener('dragover', (e) => {
        e.preventDefault();
        const afterEl = getDragAfterEl(list, e.clientY);
        if (afterEl == null) list.appendChild(dragging);
        else list.insertBefore(dragging, afterEl);
      });
    });
  }

  function getDragAfterEl(container, y) {
    const els = [...container.querySelectorAll('.menu-item-row:not([style*="opacity: 0.4"])')];
    return els.reduce((closest, child) => {
      const box = child.getBoundingClientRect();
      const offset = y - box.top - box.height / 2;
      if (offset < 0 && offset > closest.offset) return { offset, element: child };
      return closest;
    }, { offset: Number.NEGATIVE_INFINITY }).element;
  }

  function updateSortOrder(list) {
    list.querySelectorAll('.menu-item-row').forEach((row, i) => {
      const input = row.querySelector('input[name="sort[]"]');
      if (input) input.value = i;
    });
  }

  const pwdInput = document.getElementById('user-password');
  const pwdStrength = document.getElementById('pwd-strength');
  if (pwdInput && pwdStrength) {
    pwdInput.addEventListener('input', function () {
      const v = this.value;
      let strength = 0;
      if (v.length >= 8) strength++;
      if (/[A-Z]/.test(v)) strength++;
      if (/[0-9]/.test(v)) strength++;
      if (/[^A-Za-z0-9]/.test(v)) strength++;
      const labels = ['', 'Weak', 'Fair', 'Good', 'Strong'];
      const colors = ['', '#D13438', '#986F0B', '#0078D4', '#107C10'];
      pwdStrength.textContent = labels[strength] || '';
      pwdStrength.style.color = colors[strength] || '';
    });
  }

  const selectAll = document.getElementById('select-all');
  if (selectAll) {
    selectAll.addEventListener('change', function () {
      document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = this.checked);
    });
  }

  document.querySelectorAll('[data-copy]').forEach(btn => {
    btn.addEventListener('click', function () {
      const text = document.querySelector(this.dataset.copy)?.value || this.dataset.copy;
      navigator.clipboard.writeText(text).then(() => {
        const orig = this.textContent;
        this.textContent = 'Copied!';
        setTimeout(() => this.textContent = orig, 2000);
      });
    });
  });

  /* ==========================================
     DEEP ADMIN SEARCH (Phase 1.2.2)
     ========================================== */
  const searchInput = document.getElementById('admin-deep-search');
  const searchDropdown = document.getElementById('search-results-dropdown');
  const searchClear = document.getElementById('admin-search-clear');
  const searchContainer = searchInput ? searchInput.closest('.topbar-search-container') : null;
  if (searchInput && searchDropdown) {
    let searchTimeout = null;
    let searchRequest = null;
    let searchRequestId = 0;
    let activeSearchIndex = -1;
    const searchEndpoint = searchContainer?.dataset.searchUrl || 'ajax-search.php';
    const searchToken = searchContainer?.dataset.searchToken || '';

    function openSearchDropdown() {
      searchDropdown.hidden = false;
      searchInput.setAttribute('aria-expanded', 'true');
    }

    function closeSearchDropdown() {
      searchDropdown.hidden = true;
      activeSearchIndex = -1;
      searchInput.removeAttribute('aria-activedescendant');
      searchInput.setAttribute('aria-expanded', 'false');
      updateActiveSearchResult();
    }

    function updateSearchChrome() {
      const hasValue = searchInput.value.trim().length > 0;
      if (searchClear) searchClear.hidden = !hasValue;
      if (searchContainer) searchContainer.classList.toggle('has-value', hasValue);
    }

    searchInput.addEventListener('input', function() {
      const query = this.value.trim();
      clearTimeout(searchTimeout);
      if (searchRequest) searchRequest.abort();
      updateSearchChrome();

      if (query.length < 2) {
        searchDropdown.innerHTML = '';
        closeSearchDropdown();
        return;
      }

      searchDropdown.innerHTML = '<div class="search-loading">Searching...</div>';
      openSearchDropdown();

      const requestId = ++searchRequestId;
      searchTimeout = setTimeout(async () => {
        searchRequest = new AbortController();
        searchInput.setAttribute('aria-busy', 'true');
        try {
          const separator = searchEndpoint.includes('?') ? '&' : '?';
          const res = await fetch(searchEndpoint + separator + 'q=' + encodeURIComponent(query), {
            credentials: 'same-origin',
            redirect: 'manual',
            cache: 'no-store',
            headers: {
              'Accept': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
              'X-SOI-Search-Token': searchToken
            },
            signal: searchRequest.signal
          });
          if (res.type === 'opaqueredirect' || res.status === 0) {
            throw new Error('Your Admin Center session has expired. Refresh the page and sign in again.');
          }
          const contentType = res.headers.get('content-type') || '';
          if (!contentType.includes('application/json')) {
            throw new Error('Search returned an invalid server response. Refresh the page and try again.');
          }
          const payload = await res.json();
          if (!res.ok || payload.ok === false) {
            throw new Error(payload.message || 'Search request failed.');
          }
          const data = payload.results ?? payload;
          if (requestId !== searchRequestId || searchInput.value.trim() !== query) return;
          renderSearchResults(data, query, payload.degraded ? payload.message : '');
        } catch (err) {
          if (err.name === 'AbortError') return;
          const message = err instanceof Error && err.message ? err.message : 'Search failed. Try again.';
          searchDropdown.innerHTML = '<div class="search-empty search-error" role="status">' + escHtml(message) + '</div>';
          openSearchDropdown();
        } finally {
          if (requestId === searchRequestId) {
            searchInput.removeAttribute('aria-busy');
            searchRequest = null;
          }
        }
      }, 220);
    });

    if (searchClear) {
      searchClear.addEventListener('click', function () {
        searchInput.value = '';
        if (searchRequest) searchRequest.abort();
        searchRequestId++;
        searchDropdown.innerHTML = '';
        updateSearchChrome();
        closeSearchDropdown();
        searchInput.focus();
      });
    }

    document.addEventListener('click', function(e) {
      if (searchContainer && !searchContainer.contains(e.target)) {
        closeSearchDropdown();
      }
    });

    searchInput.addEventListener('focus', function() {
      if (this.value.trim().length >= 2 && searchDropdown.innerHTML !== '') {
        openSearchDropdown();
      }
    });

    searchInput.addEventListener('keydown', function(e) {
      const items = searchItems();

      if (e.key === 'Escape') {
        closeSearchDropdown();
        return;
      }

      if (searchDropdown.hidden || !items.length) return;

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        activeSearchIndex = (activeSearchIndex + 1) % items.length;
        updateActiveSearchResult(true);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        activeSearchIndex = activeSearchIndex <= 0 ? items.length - 1 : activeSearchIndex - 1;
        updateActiveSearchResult(true);
      } else if (e.key === 'Enter' && activeSearchIndex >= 0) {
        e.preventDefault();
        items[activeSearchIndex].click();
      }
    });

    document.addEventListener('keydown', function(e) {
      const target = e.target;
      const isEditing = target instanceof HTMLInputElement
        || target instanceof HTMLTextAreaElement
        || target instanceof HTMLSelectElement
        || target?.isContentEditable;
      if (e.key === '/' && !e.metaKey && !e.ctrlKey && !e.altKey && !isEditing) {
        e.preventDefault();
        searchInput.focus();
      }
    });

    function renderSearchResults(data, query, warning = '') {
      searchDropdown.innerHTML = '';
      activeSearchIndex = -1;
      if (!data || Object.keys(data).length === 0) {
        searchDropdown.innerHTML = '<div class="search-empty">No results found for "'+escHtml(query)+'"</div>';
        openSearchDropdown();
        return;
      }

      let renderedCount = 0;
      for (const [type, items] of Object.entries(data)) {
        if (!items || !items.length) continue;
        
        const header = document.createElement('div');
        header.className = 'search-section-header';
        header.textContent = type;
        searchDropdown.appendChild(header);

        items.forEach(item => {
          const a = document.createElement('a');
          a.className = 'search-result-item';
          a.href = item.url;
          a.setAttribute('role', 'option');
          a.setAttribute('aria-selected', 'false');
          a.id = 'admin-search-result-' + renderedCount;
          
          const assetsUrl = (document.body.dataset.adminAssetsUrl || 'assets').replace(/\/$/, '');
          const iconName = type === 'Pages' ? 'pages'
            : type === 'Posts' ? 'posts'
            : type === 'Users' ? 'users'
            : type === 'Menus' ? 'menus'
            : 'search';
          const iconHtml = '<svg class="soi-icon" width="16" height="16"><use href="' + assetsUrl + '/icons.svg#' + iconName + '"></use></svg>';

          a.innerHTML = `
            <div class="search-result-icon">${iconHtml}</div>
            <div class="search-result-content">
              <div class="search-result-title">${escHtml(item.title)}</div>
              <div class="search-result-meta">${escHtml(item.meta || '')}</div>
            </div>
          `;
          searchDropdown.appendChild(a);
          renderedCount++;
        });
      }

      if (renderedCount === 0) {
        searchDropdown.innerHTML = '<div class="search-empty">No results found for "'+escHtml(query)+'"</div>';
      } else if (warning) {
        const warningEl = document.createElement('div');
        warningEl.className = 'search-provider-warning';
        warningEl.setAttribute('role', 'status');
        warningEl.textContent = warning;
        searchDropdown.appendChild(warningEl);
      }
      openSearchDropdown();
      updateActiveSearchResult();
    }

    function searchItems() {
      return Array.from(searchDropdown.querySelectorAll('.search-result-item'));
    }

    function updateActiveSearchResult(scrollIntoView = false) {
      const items = searchItems();
      items.forEach((item, index) => {
        const active = index === activeSearchIndex;
        item.classList.toggle('active', active);
        item.setAttribute('aria-selected', active ? 'true' : 'false');
        if (active) {
          searchInput.setAttribute('aria-activedescendant', item.id);
          if (scrollIntoView) item.scrollIntoView({ block: 'nearest' });
        }
      });

      if (activeSearchIndex < 0) {
        searchInput.removeAttribute('aria-activedescendant');
      }
    }

    function escHtml(str) {
      return String(str).replace(/[&<>'"]/g, match => {
        return {
          '&': '&amp;',
          '<': '&lt;',
          '>': '&gt;',
          "'": '&#39;',
          '"': '&quot;'
        }[match];
      });
    }

    updateSearchChrome();
  }

});
