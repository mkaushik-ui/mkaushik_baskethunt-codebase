/**
 * Steps Tool - Knowledge Center & Editor.js Interactive Component (Task A3-T12).
 * 
 * Numbered procedural instruction steps tool with step counter badges,
 * title, instruction content, dynamic add/remove, reordering, and read-only support.
 * Compatible with SOI\Core\Blocks\Interactive\StepsBlock backend renderer.
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
    if (document.getElementById('kc-steps-tool-style')) return;
    const style = document.createElement('style');
    style.id = 'kc-steps-tool-style';
    style.textContent = `
      .kc-tool-steps-wrap {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background: #ffffff;
        padding: 1rem 1.25rem;
        margin: 0.75rem 0;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      }
      .kc-steps-tool-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 0.75rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid #f1f5f9;
      }
      .kc-steps-tool-badge {
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #0284c7;
        background: #e0f2fe;
        padding: 0.2rem 0.55rem;
        border-radius: 4px;
      }
      .kc-steps-tool-list {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
      }
      .kc-steps-tool-row {
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
      .kc-steps-tool-index {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.95rem;
        font-weight: 800;
        flex-shrink: 0;
        margin-top: 0.1rem;
        box-shadow: 0 2px 6px rgba(37, 99, 235, 0.35);
      }
      .kc-steps-tool-fields {
        flex: 1 1 auto;
        display: flex;
        flex-direction: column;
        gap: 0.45rem;
      }
      .kc-steps-tool-input-title {
        width: 100%;
        box-sizing: border-box;
        padding: 0.5rem 0.65rem;
        font-size: 1rem;
        font-weight: 700;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        color: #1e293b;
        background: #ffffff;
        outline: none;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
      }
      .kc-steps-tool-input-title:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
      }
      .kc-steps-tool-input-content {
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
      .kc-steps-tool-input-content:focus {
        border-color: #0284c7;
        box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.15);
      }
      .kc-steps-tool-actions {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        flex-shrink: 0;
      }
      .kc-steps-tool-btn {
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
      .kc-steps-tool-btn:hover:not(:disabled) {
        background: #f1f5f9;
        border-color: #94a3b8;
        color: #0f172a;
      }
      .kc-steps-tool-btn-danger:hover:not(:disabled) {
        background: #fee2e2;
        border-color: #fca5a5;
        color: #dc2626;
      }
      .kc-steps-tool-btn:disabled {
        opacity: 0.4;
        cursor: not-allowed;
      }
      .kc-steps-tool-add-btn {
        margin-top: 0.75rem;
        width: 100%;
        padding: 0.5rem;
        background: #f8fafc;
        border: 1px dashed #94a3b8;
        color: #0284c7;
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
      .kc-steps-tool-add-btn:hover {
        background: #e0f2fe;
        border-color: #0284c7;
        color: #0369a1;
      }
    `;
    document.head.appendChild(style);
  }

  class KcStepsTool {
    static get toolbox() {
      return {
        title: 'Steps',
        icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h16"/><circle cx="4" cy="6" r="1.5" fill="currentColor"/><circle cx="4" cy="12" r="1.5" fill="currentColor"/><circle cx="4" cy="18" r="1.5" fill="currentColor"/></svg>'
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
          }
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
            content: typeof item.content === 'string' ? item.content : ''
          }))
        : [
            { title: 'Step 1', content: '' },
            { title: 'Step 2', content: '' }
          ];

      this.nodes = {
        wrap: null,
        list: null
      };

      injectStyles();
    }

    render() {
      const wrap = el('div', 'kc-tool kc-tool-steps kc-tool-steps-wrap');
      
      const header = el('div', 'kc-steps-tool-header');
      const badge = el('div', 'kc-steps-tool-badge', { text: 'Numbered Steps' });
      header.appendChild(badge);
      wrap.appendChild(header);

      const list = el('div', 'kc-steps-tool-list');
      wrap.appendChild(list);

      this.nodes.wrap = wrap;
      this.nodes.list = list;

      if (!this.readOnly) {
        const addBtn = el('button', 'kc-steps-tool-add-btn', {
          type: 'button',
          text: '+ Add Step'
        });
        addBtn.addEventListener('click', () => {
          this.items = this.collectItems();
          this.items.push({
            title: `Step ${this.items.length + 1}`,
            content: ''
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
        const row = el('div', 'kc-steps-tool-row');
        row._index = index;

        // Step index circle
        const indexCircle = el('div', 'kc-steps-tool-index', {
          text: String(index + 1)
        });
        row.appendChild(indexCircle);

        // Fields container
        const fields = el('div', 'kc-steps-tool-fields');

        const titleInput = el('input', 'kc-steps-tool-input-title', {
          type: 'text',
          placeholder: `Step ${index + 1} Title`
        });
        titleInput.value = item.title || '';
        titleInput.disabled = this.readOnly;
        fields.appendChild(titleInput);

        const contentInput = el('textarea', 'kc-steps-tool-input-content', {
          placeholder: 'Step instructions, details, code snippets, etc.'
        });
        contentInput.value = item.content || '';
        contentInput.disabled = this.readOnly;
        fields.appendChild(contentInput);

        row.appendChild(fields);

        // Action buttons
        if (!this.readOnly) {
          const actions = el('div', 'kc-steps-tool-actions');

          const upBtn = el('button', 'kc-steps-tool-btn', {
            type: 'button',
            text: '↑',
            title: 'Move Step Up'
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

          const downBtn = el('button', 'kc-steps-tool-btn', {
            type: 'button',
            text: '↓',
            title: 'Move Step Down'
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

          const removeBtn = el('button', 'kc-steps-tool-btn kc-steps-tool-btn-danger', {
            type: 'button',
            text: '×',
            title: 'Remove Step'
          });
          removeBtn.addEventListener('click', () => {
            this.items = this.collectItems();
            this.items.splice(index, 1);
            if (this.items.length === 0) {
              this.items.push({ title: 'Step 1', content: '' });
            }
            this.paint();
          });
          actions.appendChild(removeBtn);

          row.appendChild(actions);
        }

        row._titleInput = titleInput;
        row._contentInput = contentInput;
        list.appendChild(row);
      });
    }

    collectItems() {
      if (!this.nodes.list) return this.items || [];
      const rows = this.nodes.list.querySelectorAll('.kc-steps-tool-row');
      const items = [];
      rows.forEach(row => {
        const title = row._titleInput ? row._titleInput.value.trim() : '';
        const content = row._contentInput ? row._contentInput.value.trim() : '';
        items.push({ title, content });
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
    global.KcEditorRegistry.register('steps', KcStepsTool, {
      label: 'Steps',
      group: 'interactive'
    });
  }

  // Expose global tool classes
  global.KcStepsTool = KcStepsTool;
  global.KcSteps = KcStepsTool;
  global.StepsTool = KcStepsTool;
  global.Steps = KcStepsTool;

})(typeof window !== 'undefined' ? window : globalThis);
