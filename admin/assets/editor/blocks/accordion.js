/**
 * Accordion Tool - Knowledge Center & Editor.js Interactive Component (Task A3-T13).
 * 
 * Expandable multi-section accordion tool with header title input, open-by-default
 * toggle checkbox, rich description content, dynamic reordering, and accessible
 * details/summary integration. Compatible with SOI\Core\Blocks\Interactive\AccordionBlock.
 * 
 * @author Saurabh
 */
(function (global) {
  'use strict';

  function el(tag, className, attrs) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (attrs) {
      Object.keys(attrs).forEach(function (key) {
        if (key === 'text') node.textContent = attrs[key];
        else if (key === 'html') node.innerHTML = attrs[key];
        else node.setAttribute(key, attrs[key]);
      });
    }
    return node;
  }

  function injectStyles() {
    if (typeof document === 'undefined') return;
    if (document.getElementById('kc-accordion-tool-style')) return;
    const style = document.createElement('style');
    style.id = 'kc-accordion-tool-style';
    style.textContent = `
      .kc-tool-accordion-wrap {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background: #ffffff;
        padding: 1rem 1.25rem;
        margin: 0.75rem 0;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      }
      .kc-accordion-tool-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 0.75rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid #f1f5f9;
      }
      .kc-accordion-tool-badge {
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #7c3aed;
        background: #f5f3ff;
        padding: 0.2rem 0.55rem;
        border-radius: 4px;
      }
      .kc-accordion-tool-list {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
      }
      .kc-accordion-tool-row {
        display: flex;
        gap: 0.85rem;
        align-items: flex-start;
        padding: 0.85rem;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        position: relative;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
      }
      .kc-accordion-tool-indicator {
        width: 28px;
        height: 28px;
        border-radius: 6px;
        background: #ede9fe;
        color: #7c3aed;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.95rem;
        font-weight: 700;
        flex-shrink: 0;
        margin-top: 0.2rem;
      }
      .kc-accordion-tool-fields {
        flex: 1 1 auto;
        display: flex;
        flex-direction: column;
        gap: 0.45rem;
      }
      .kc-accordion-tool-topbar {
        display: flex;
        gap: 0.6rem;
        align-items: center;
      }
      .kc-accordion-tool-input-title {
        flex: 1 1 auto;
        box-sizing: border-box;
        padding: 0.5rem 0.65rem;
        font-size: 0.96rem;
        font-weight: 600;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        color: #1e293b;
        background: #ffffff;
        outline: none;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
      }
      .kc-accordion-tool-input-title:focus {
        border-color: #7c3aed;
        box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.15);
      }
      .kc-accordion-tool-toggle-label {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.78rem;
        font-weight: 500;
        color: #64748b;
        cursor: pointer;
        user-select: none;
        white-space: nowrap;
      }
      .kc-accordion-tool-toggle-label input[type="checkbox"] {
        accent-color: #7c3aed;
        cursor: pointer;
      }
      .kc-accordion-tool-input-content {
        width: 100%;
        box-sizing: border-box;
        padding: 0.45rem 0.6rem;
        font-size: 0.88rem;
        line-height: 1.5;
        border: 1px solid #cbd5e1;
        border-radius: 4px;
        color: #334155;
        background: #ffffff;
        resize: vertical;
        min-height: 54px;
        outline: none;
      }
      .kc-accordion-tool-input-content:focus {
        border-color: #7c3aed;
        box-shadow: 0 0 0 2px rgba(124, 58, 237, 0.15);
      }
      .kc-accordion-tool-actions {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        flex-shrink: 0;
      }
      .kc-accordion-tool-btn {
        width: 26px;
        height: 26px;
        padding: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.8rem;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        color: #475569;
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.15s ease;
      }
      .kc-accordion-tool-btn:hover:not(:disabled) {
        background: #f1f5f9;
        border-color: #94a3b8;
        color: #0f172a;
      }
      .kc-accordion-tool-btn-danger:hover:not(:disabled) {
        background: #fee2e2;
        border-color: #fca5a5;
        color: #dc2626;
      }
      .kc-accordion-tool-btn:disabled {
        opacity: 0.4;
        cursor: not-allowed;
      }
      .kc-accordion-tool-add-btn {
        margin-top: 0.75rem;
        width: 100%;
        padding: 0.5rem;
        background: #f8fafc;
        border: 1px dashed #94a3b8;
        color: #7c3aed;
        font-size: 0.88rem;
        font-weight: 600;
        border-radius: 6px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        transition: all 0.15s ease;
      }
      .kc-accordion-tool-add-btn:hover {
        background: #f5f3ff;
        border-color: #7c3aed;
        color: #6d28d9;
      }
    `;
    document.head.appendChild(style);
  }

  class KcAccordionTool {
    static get toolbox() {
      return {
        title: 'Accordion',
        icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 8h10M7 12h10M7 16h10"/><path d="M15 8l2 2-2 2"/></svg>'
      };
    }

    static get isReadOnlySupported() {
      return true;
    }

    static get sanitize() {
      return {
        items: {
          title: {
            b: true,
            strong: true,
            i: true,
            em: true,
            code: true
          },
          content: {
            b: true,
            strong: true,
            i: true,
            em: true,
            u: true,
            s: true,
            code: true,
            a: {
              href: true,
              target: '_blank',
              rel: 'noopener noreferrer'
            },
            br: true
          },
          open: false
        }
      };
    }

    constructor({ data, config, api, readOnly }) {
      this.api = api;
      this.readOnly = !!readOnly;
      this.config = config || {};

      const rawItems = (data && Array.isArray(data.items)) ? data.items : [];
      this.items = rawItems.length > 0
        ? rawItems.map(item => ({
            title: typeof item.title === 'string' ? item.title : '',
            content: typeof item.content === 'string' ? item.content : '',
            open: !!item.open
          }))
        : [
            { title: 'Section 1', content: '', open: true }
          ];

      this.nodes = {
        wrap: null,
        list: null
      };

      injectStyles();
    }

    render() {
      const wrap = el('div', 'kc-tool kc-tool-accordion kc-tool-accordion-wrap');

      const header = el('div', 'kc-accordion-tool-header');
      const badge = el('div', 'kc-accordion-tool-badge', { text: 'Accordion Sections' });
      header.appendChild(badge);
      wrap.appendChild(header);

      const list = el('div', 'kc-accordion-tool-list');
      wrap.appendChild(list);

      this.nodes.wrap = wrap;
      this.nodes.list = list;

      if (!this.readOnly) {
        const addBtn = el('button', 'kc-accordion-tool-add-btn', {
          type: 'button',
          text: '+ Add Section'
        });
        addBtn.addEventListener('click', () => {
          this.items = this.collectItems();
          this.items.push({
            title: `Section ${this.items.length + 1}`,
            content: '',
            open: false
          });
          this.paint();
        });
        wrap.appendChild(addBtn);
      }

      this.paint();
      return wrap;
    }

    paint() {
      const list = this.nodes.list;
      list.innerHTML = '';

      this.items.forEach((item, index) => {
        const row = el('div', 'kc-accordion-tool-row');
        row._index = index;

        // Visual indicator
        const indicator = el('div', 'kc-accordion-tool-indicator', {
          text: '▾'
        });
        row.appendChild(indicator);

        // Fields container
        const fields = el('div', 'kc-accordion-tool-fields');

        // Top bar with title + open toggle checkbox
        const topbar = el('div', 'kc-accordion-tool-topbar');

        const titleInput = el('input', 'kc-accordion-tool-input-title', {
          type: 'text',
          placeholder: `Section ${index + 1} Heading / Title`
        });
        titleInput.value = item.title || '';
        titleInput.disabled = this.readOnly;
        topbar.appendChild(titleInput);

        const toggleLabel = el('label', 'kc-accordion-tool-toggle-label');
        const openCheckbox = el('input', '', { type: 'checkbox' });
        openCheckbox.checked = !!item.open;
        openCheckbox.disabled = this.readOnly;
        toggleLabel.appendChild(openCheckbox);
        toggleLabel.appendChild(document.createTextNode(' Open by default'));
        topbar.appendChild(toggleLabel);

        fields.appendChild(topbar);

        // Content description input
        const contentInput = el('textarea', 'kc-accordion-tool-input-content', {
          placeholder: 'Section body content, paragraphs, instructions...'
        });
        contentInput.value = item.content || '';
        contentInput.disabled = this.readOnly;
        fields.appendChild(contentInput);

        row.appendChild(fields);

        // Action buttons
        if (!this.readOnly) {
          const actions = el('div', 'kc-accordion-tool-actions');

          const upBtn = el('button', 'kc-accordion-tool-btn', {
            type: 'button',
            text: '↑',
            title: 'Move Section Up'
          });
          if (index === 0) upBtn.disabled = true;
          upBtn.addEventListener('click', () => {
            this.items = this.collectItems();
            if (index > 0) {
              const tmp = this.items[index - 1];
              this.items[index - 1] = this.items[index];
              this.items[index] = tmp;
              this.paint();
            }
          });
          actions.appendChild(upBtn);

          const downBtn = el('button', 'kc-accordion-tool-btn', {
            type: 'button',
            text: '↓',
            title: 'Move Section Down'
          });
          if (index === this.items.length - 1) downBtn.disabled = true;
          downBtn.addEventListener('click', () => {
            this.items = this.collectItems();
            if (index < this.items.length - 1) {
              const tmp = this.items[index + 1];
              this.items[index + 1] = this.items[index];
              this.items[index] = tmp;
              this.paint();
            }
          });
          actions.appendChild(downBtn);

          const removeBtn = el('button', 'kc-accordion-tool-btn kc-accordion-tool-btn-danger', {
            type: 'button',
            text: '×',
            title: 'Remove Section'
          });
          removeBtn.addEventListener('click', () => {
            this.items = this.collectItems();
            this.items.splice(index, 1);
            if (this.items.length === 0) {
              this.items.push({ title: 'Section 1', content: '', open: true });
            }
            this.paint();
          });
          actions.appendChild(removeBtn);

          row.appendChild(actions);
        }

        row._titleInput = titleInput;
        row._openCheckbox = openCheckbox;
        row._contentInput = contentInput;
        list.appendChild(row);
      });
    }

    collectItems() {
      if (!this.nodes.list) return this.items || [];
      const rows = this.nodes.list.querySelectorAll('.kc-accordion-tool-row');
      const items = [];
      rows.forEach(row => {
        const title = row._titleInput ? row._titleInput.value.trim() : '';
        const open = row._openCheckbox ? !!row._openCheckbox.checked : false;
        const content = row._contentInput ? row._contentInput.value.trim() : '';
        items.push({ title, content, open });
      });
      return items;
    }

    save() {
      return {
        items: this.collectItems()
      };
    }

    validate(savedData) {
      if (!savedData || !Array.isArray(savedData.items)) {
        return false;
      }
      return savedData.items.length > 0;
    }
  }

  // Register with KC Editor Registry if present
  if (global.KcEditorRegistry && typeof global.KcEditorRegistry.register === 'function') {
    global.KcEditorRegistry.register('accordion', KcAccordionTool, {
      label: 'Accordion',
      group: 'interactive'
    });
  }

  // Expose global tool classes
  global.KcAccordionTool = KcAccordionTool;
  global.KcAccordion = KcAccordionTool;
  global.AccordionTool = KcAccordionTool;
  global.Accordion = KcAccordionTool;

})(typeof window !== 'undefined' ? window : globalThis);
