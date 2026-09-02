/**
 * Task A3-T21: Favorites & Recently Used Components
 *
 * Tracks frequently and recently used editor components using browser localStorage.
 * Automatically updates component usage count and last-used timestamp upon insertion,
 * orders components by frequency and recency, and dynamically renders a "Frequently Used"
 * row at the top of the Left Component Library.
 *
 * @author Saurabh
 */
(function (global) {
  'use strict';

  const STORAGE_KEY = 'kc_editor_component_favorites';
  const MAX_FAVORITES_DISPLAY = 6;

  // In-memory fallback in case localStorage is unavailable or blocked
  let memoryFallback = {};

  /**
   * Safe localStorage accessor that handles unavailable storage,
   * quota errors, or corrupted JSON gracefully.
   */
  const StorageManager = {
    isAvailable: function () {
      try {
        const testKey = '__kc_storage_test__';
        localStorage.setItem(testKey, '1');
        localStorage.removeItem(testKey);
        return true;
      } catch (e) {
        return false;
      }
    },

    load: function () {
      if (!this.isAvailable()) {
        return Object.assign({}, memoryFallback);
      }
      try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) {
          return {};
        }
        const parsed = JSON.parse(raw);
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
          return parsed;
        }
        return {};
      } catch (e) {
        console.warn('[KC Favorites] Corrupted or invalid localStorage data, resetting.', e);
        return {};
      }
    },

    save: function (data) {
      if (!data || typeof data !== 'object') return false;
      memoryFallback = Object.assign({}, data);
      if (!this.isAvailable()) {
        return true;
      }
      try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
        return true;
      } catch (e) {
        console.warn('[KC Favorites] Failed to save usage data to localStorage:', e);
        return false;
      }
    },

    clear: function () {
      memoryFallback = {};
      if (this.isAvailable()) {
        try {
          localStorage.removeItem(STORAGE_KEY);
        } catch (e) {}
      }
    }
  };

  /**
   * Default component catalog metadata fallback.
   */
  const DEFAULT_CATALOG_ITEMS = {
    paragraph: { id: 'paragraph', label: 'Paragraph', icon: 'P', description: 'Body text', group: 'basic', keywords: 'text p write' },
    heading: { id: 'heading', label: 'Heading', icon: 'H', description: 'Section title', group: 'basic', keywords: 'h2 h3 title' },
    list: { id: 'list', label: 'Bulleted list', icon: '•', description: 'Unordered list', group: 'basic', keywords: 'ul bullet list' },
    'list-ordered': { id: 'list-ordered', label: 'Numbered list', icon: '1.', description: 'Ordered list', group: 'basic', keywords: 'ol numbered list' },
    'list-checklist': { id: 'list-checklist', label: 'Checklist', icon: '☑', description: 'Task list', group: 'basic', keywords: 'todo check list' },
    quote: { id: 'quote', label: 'Quote', icon: '“', description: 'Pull quote', group: 'basic', keywords: 'blockquote quote citation' },
    divider: { id: 'divider', label: 'Divider', icon: '—', description: 'Horizontal rule', group: 'basic', keywords: 'hr rule separator' },
    image: { id: 'image', label: 'Image', icon: '🖼', description: 'Picture from media library', group: 'media', keywords: 'photo media upload' },
    file: { id: 'file', label: 'File', icon: '📎', description: 'Downloadable attachment', group: 'media', keywords: 'attachment download doc' },
    link: { id: 'link', label: 'Link card', icon: '🔗', description: 'Titled URL bookmark card', group: 'media', keywords: 'url bookmark web link' },
    table: { id: 'table', label: 'Table', icon: '▦', description: 'Rows and columns grid', group: 'structured', keywords: 'grid sheet data table' },
    code: { id: 'code', label: 'Code', icon: '</>', description: 'Technical snippet', group: 'technical', keywords: 'snippet pre terminal code' },
    callout: { id: 'callout', label: 'Callout', icon: '!', description: 'Semantic info, tip, warning notice', group: 'notice', keywords: 'notice warning tip info danger' },
    steps: { id: 'steps', label: 'Steps', icon: '1.', description: 'Numbered procedural instructions', group: 'structured', keywords: 'procedure howto guide steps' },
    accordion: { id: 'accordion', label: 'Accordion', icon: '▾', description: 'Expandable sections', group: 'structured', keywords: 'collapse expand section accordion' },
    faq: { id: 'faq', label: 'FAQ', icon: '?', description: 'Questions and answers with Schema.org markup', group: 'structured', keywords: 'question answer help faq' },
    tabs: { id: 'tabs', label: 'Tabs', icon: '↹', description: 'Multi-tab content switcher', group: 'structured', keywords: 'tab panel platform language tabs' },
    codeGroup: { id: 'codeGroup', label: 'Code Group', icon: '{ }', description: 'Multi-language code tabs', group: 'technical', keywords: 'curl php javascript code snippets' },
    definitionList: { id: 'definitionList', label: 'Definition List', icon: '≡', description: 'Terms and definitions glossary', group: 'structured', keywords: 'glossary terminology dl terms' },
    statusBadge: { id: 'statusBadge', label: 'Status / Badge', icon: '●', description: 'Stable, Beta, Deprecated tags', group: 'notice', keywords: 'badge status stable beta tag' },
    apiEndpoint: { id: 'apiEndpoint', label: 'API Endpoint', icon: '⚡', description: 'Structured API specification', group: 'technical', keywords: 'api endpoint rest http request' },
    keyValues: { id: 'keyValues', label: 'Key / Value', icon: '☷', description: 'Reference specifications list', group: 'technical', keywords: 'key value spec reference table' },
    kbd: { id: 'kbd', label: 'Keyboard Key', icon: '⌨', description: 'Semantic key shortcut sequence', group: 'technical', keywords: 'kbd shortcut key keypress' },
    group: { id: 'group', label: 'Group Container', icon: '▢', description: 'Wrapper container for related content', group: 'layout', keywords: 'container section box wrapper' },
    columns: { id: 'columns', label: 'Columns', icon: '▥', description: 'Responsive multi-column layout', group: 'layout', keywords: 'two three 50 33 grid layout split' },
    cards: { id: 'cards', label: 'Cards Grid', icon: '▤', description: 'Card grid with links and icons', group: 'layout', keywords: 'card grid tiles cards' },
    reusable: { id: 'reusable', label: 'Reusable Block', icon: '♻', description: 'Insert a shared reusable component', group: 'layout', keywords: 'reusable shared synced component' },
    legacy: { id: 'legacy', label: 'Legacy HTML', icon: 'HTML', description: 'Preserved HTML from previous editor', group: 'legacy', keywords: 'html tinymce raw legacy' }
  };

  /**
   * Core Favorites Management Module
   */
  const KcFavorites = {
    /**
     * Retrieve the raw usage data map from storage.
     * Format: { [componentId: string]: { count: number, lastUsed: number } }
     *
     * @return {Object.<string, {count: number, lastUsed: number}>}
     */
    getUsageData: function () {
      const data = StorageManager.load();
      // Ensure all values are valid objects with numerical count and lastUsed
      const clean = {};
      Object.keys(data).forEach(function (key) {
        const item = data[key];
        if (item && typeof item === 'object' && typeof item.count === 'number') {
          clean[key] = {
            count: Math.max(0, Math.floor(item.count)),
            lastUsed: typeof item.lastUsed === 'number' ? item.lastUsed : Date.now()
          };
        }
      });
      return clean;
    },

    /**
     * Record usage each time a component is inserted.
     * Increments usage count, updates last-used timestamp, saves to localStorage,
     * and refreshes the UI row.
     *
     * @param {string} componentId Identifier or type of the inserted component
     * @return {Object.<string, {count: number, lastUsed: number}>}
     */
    recordUsage: function (componentId) {
      if (!componentId || typeof componentId !== 'string') {
        return this.getUsageData();
      }

      const id = componentId.trim();
      if (!id) return this.getUsageData();

      const data = this.getUsageData();
      const current = data[id] || { count: 0, lastUsed: 0 };

      data[id] = {
        count: current.count + 1,
        lastUsed: Date.now()
      };

      StorageManager.save(data);

      // Refresh the frequently used row in the Left Component Library
      this.renderFrequentlyUsedRow();

      return data;
    },

    /**
     * Retrieve ordered list of frequently / recently used components.
     * Sorted primarily by highest usage count descending, with most recent usage
     * (lastUsed timestamp) as the tie-breaker.
     *
     * @param {number} [limit=6] Maximum number of components to return
     * @return {Array.<{id: string, count: number, lastUsed: number}>}
     */
    getOrdered: function (limit) {
      const max = typeof limit === 'number' && limit > 0 ? limit : MAX_FAVORITES_DISPLAY;
      const data = this.getUsageData();

      const list = Object.keys(data).map(function (id) {
        return {
          id: id,
          count: data[id].count || 0,
          lastUsed: data[id].lastUsed || 0
        };
      }).filter(function (item) {
        return item.count > 0;
      });

      // Sort: Higher count first; if count is equal, more recent timestamp first
      list.sort(function (a, b) {
        if (b.count !== a.count) {
          return b.count - a.count;
        }
        return b.lastUsed - a.lastUsed;
      });

      return list.slice(0, max);
    },

    /**
     * Get component metadata from catalog, editor registry, DOM, or built-in defaults.
     *
     * @param {string} id
     * @return {Object}
     */
    getComponentMeta: function (id) {
      // 1. Check page config catalog if available
      try {
        const cfgNode = document.getElementById('kc-editor-config');
        if (cfgNode && cfgNode.textContent) {
          const cfg = JSON.parse(cfgNode.textContent);
          if (cfg && Array.isArray(cfg.catalog)) {
            const found = cfg.catalog.find(function (it) {
              return it.id === id || it.type === id || it.editorType === id;
            });
            if (found) return found;
          }
        }
      } catch (e) {}

      // 2. Check DOM for existing component button metadata
      try {
        const domBtn = document.querySelector('.kc-component[data-component-id="' + id + '"]');
        if (domBtn) {
          const icoEl = domBtn.querySelector('.kc-component-ico');
          const strongEl = domBtn.querySelector('strong');
          const descEl = domBtn.querySelector('.kc-component-desc');
          return {
            id: id,
            label: strongEl ? strongEl.textContent.trim() : id,
            icon: icoEl ? icoEl.textContent.trim() : '★',
            description: descEl ? descEl.textContent.trim() : '',
            group: domBtn.dataset.group || 'favorites',
            keywords: domBtn.dataset.keywords || ''
          };
        }
      } catch (e) {}

      // 3. Fallback to default catalog mapping
      if (DEFAULT_CATALOG_ITEMS[id]) {
        return DEFAULT_CATALOG_ITEMS[id];
      }

      return {
        id: id,
        label: id.charAt(0).toUpperCase() + id.slice(1),
        icon: '★',
        description: '',
        group: 'favorites',
        keywords: id
      };
    },

    /**
     * Render or update the "Frequently Used" row at the top of the Left Component Library.
     *
     * @param {HTMLElement} [container] Optional container (defaults to #kc-component-list)
     * @return {HTMLElement|null} The rendered group element or null
     */
    renderFrequentlyUsedRow: function (container) {
      const listEl = container || document.getElementById('kc-component-list');
      if (!listEl) return null;

      const ordered = this.getOrdered(MAX_FAVORITES_DISPLAY);
      let groupEl = listEl.querySelector('.kc-component-group.kc-favorites-group');

      // If no usage data exists yet, hide or remove previous favorites group
      if (!ordered.length) {
        if (groupEl) {
          groupEl.remove();
        }
        return null;
      }

      if (!groupEl) {
        groupEl = document.createElement('div');
        groupEl.className = 'kc-component-group kc-favorites-group';
        groupEl.dataset.group = 'favorites';

        // Insert at the very top of the component list
        if (listEl.firstChild) {
          listEl.insertBefore(groupEl, listEl.firstChild);
        } else {
          listEl.appendChild(groupEl);
        }
      }

      // Build group markup with unique, non-duplicated items
      const titleHtml = '<h4>★ Frequently Used</h4>';
      let gridHtml = '<div class="kc-component-grid">';

      const seenIds = {};
      ordered.forEach((item) => {
        if (seenIds[item.id]) return;
        seenIds[item.id] = true;

        const meta = this.getComponentMeta(item.id);
        const icon = meta.icon || '★';
        const label = meta.label || item.id;
        const desc = meta.description || (item.count + (item.count === 1 ? ' use' : ' uses'));
        const keywords = (meta.keywords || '') + ' favorite frequent recent ' + item.id;

        gridHtml += '<button type="button" class="kc-component kc-fav-component" draggable="true" '
          + 'data-component-id="' + this.escapeAttr(item.id) + '" '
          + 'data-group="favorites" '
          + 'data-keywords="' + this.escapeAttr(keywords) + '" '
          + 'title="' + this.escapeAttr(desc ? (label + ' — ' + desc) : label) + ' (used ' + item.count + ' times)">'
          + '<span class="kc-component-ico">' + this.escapeHtml(icon) + '</span>'
          + '<strong>' + this.escapeHtml(label) + '</strong>'
          + '<span class="kc-component-desc">' + this.escapeHtml(desc) + '</span>'
          + '</button>';
      });

      gridHtml += '</div>';
      groupEl.innerHTML = titleHtml + gridHtml;

      // Attach click listeners to freshly rendered favorite buttons
      groupEl.querySelectorAll('.kc-component').forEach((btn) => {
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          const compId = btn.dataset.componentId;
          if (compId) {
            // Trigger component insertion
            this.insertComponent(compId);
          }
        });
      });

      return groupEl;
    },

    /**
     * Trigger component insertion into the active editor and update usage.
     *
     * @param {string} componentId
     */
    insertComponent: function (componentId) {
      if (!componentId) return;

      // Record usage count and timestamp
      this.recordUsage(componentId);

      // 1. If global insertBlockByType exists
      if (typeof global.insertBlockByType === 'function') {
        global.insertBlockByType(componentId);
        return;
      }

      // 2. Fallback: simulate click on matching standard catalog button
      const standardBtn = document.querySelector(
        '.kc-component:not(.kc-fav-component)[data-component-id="' + componentId + '"]'
      );
      if (standardBtn) {
        // Prevent duplicate recordUsage on self
        standardBtn.dataset.kcFromFavorite = 'true';
        standardBtn.click();
        delete standardBtn.dataset.kcFromFavorite;
        return;
      }

      // 3. Fallback: Editor.js blocks API if accessible directly
      if (global.editor && global.editor.blocks && typeof global.editor.blocks.insert === 'function') {
        let type = componentId;
        if (type === 'heading') type = 'header';
        if (type === 'link') type = 'linkCard';
        global.editor.blocks.insert(type, {}, {}, undefined, true);
      }
    },

    /**
     * Clear all stored usage data.
     */
    clearUsage: function () {
      StorageManager.clear();
      const listEl = document.getElementById('kc-component-list');
      if (listEl) {
        const groupEl = listEl.querySelector('.kc-component-group.kc-favorites-group');
        if (groupEl) groupEl.remove();
      }
    },

    /**
     * HTML entity escaping helper.
     */
    escapeHtml: function (str) {
      if (!str) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    },

    /**
     * HTML attribute escaping helper.
     */
    escapeAttr: function (str) {
      if (!str) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    },

    /**
     * Initialize favorites tracking and event listeners.
     */
    init: function () {
      // 1. Initial render of Frequently Used row
      this.renderFrequentlyUsedRow();

      // 2. Global event delegation for component button clicks
      document.addEventListener('click', (e) => {
        const compBtn = e.target.closest('.kc-component');
        if (compBtn) {
          // If clicked from the favorite row itself or flagged, avoid double recording
          if (compBtn.classList.contains('kc-fav-component') || compBtn.dataset.kcFromFavorite) {
            return;
          }
          const compId = compBtn.dataset.componentId;
          if (compId) {
            this.recordUsage(compId);
          }
          return;
        }

        // Handle ribbon insert tool buttons: .kc-tool[data-insert]
        const toolBtn = e.target.closest('.kc-tool[data-insert]');
        if (toolBtn) {
          const insertType = toolBtn.dataset.insert;
          if (insertType) {
            this.recordUsage(insertType);
          }
        }
      }, true);

      // 3. Hook into window.insertBlockByType if available
      if (typeof global.insertBlockByType === 'function' && !global.insertBlockByType._kc_fav_hooked) {
        const originalInsert = global.insertBlockByType;
        const self = this;
        global.insertBlockByType = function (typeId, initialData) {
          self.recordUsage(typeId);
          return originalInsert.apply(this, arguments);
        };
        global.insertBlockByType._kc_fav_hooked = true;
      }
    }
  };

  // Expose global API
  global.KcFavorites = KcFavorites;
  global.KcFrequentlyUsed = KcFavorites;

  // Auto-boot on DOM ready
  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function () {
        KcFavorites.init();
      });
    } else {
      KcFavorites.init();
    }
  }

})(typeof window !== 'undefined' ? window : globalThis);
