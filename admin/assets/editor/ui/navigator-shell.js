/**
 * Task T2: Navigator Shell Component.
 * Renders document outline tree from document headings and structured layout blocks.
 */
(function (global) {
  'use strict';

  class NavigatorShell {
    constructor(hostEl) {
      this.host = typeof hostEl === 'string' ? document.getElementById(hostEl) : hostEl;
      this.outlineListEl = null;
    }

    init() {
      if (!this.host) {
        this.host = document.getElementById('kc-left-pane') || document.querySelector('.kc-left-pane');
      }
      this.outlineListEl = document.getElementById('kc-outline-list');
      
      if (global.KcEditorRuntime) {
        global.KcEditorRuntime.on('change', () => this.refreshOutline());
        global.KcEditorRuntime.on('ready', () => this.refreshOutline());
      }
    }

    async refreshOutline() {
      if (!this.outlineListEl) {
        this.outlineListEl = document.getElementById('kc-outline-list');
      }
      if (!this.outlineListEl) return;

      const runtime = global.KcEditorRuntime;
      if (!runtime || !runtime.instance) {
        this.renderEmpty();
        return;
      }

      try {
        const docData = await runtime.save();
        const blocks = docData.blocks || [];

        if (blocks.length === 0) {
          this.renderEmpty();
          return;
        }

        const items = [];
        blocks.forEach((b, idx) => {
          const type = (b.type || '').toLowerCase();
          if (type === 'header') {
            const level = b.data?.level || 2;
            const text = (b.data?.text || '').replace(/<[^>]*>/g, '').trim() || 'Untitled Heading';
            items.push({ index: idx, label: text, level: level, icon: 'H' + level });
          } else if (['group', 'columns', 'cards', 'accordion', 'steps', 'faq', 'callout'].includes(type)) {
            const label = b.data?.title || (type.charAt(0).toUpperCase() + type.slice(1));
            items.push({ index: idx, label: label, level: 2, icon: '▢' });
          }
        });

        if (items.length === 0) {
          this.renderEmpty();
          return;
        }

        this.renderItems(items);
      } catch (err) {
        console.error('[NavigatorShell] Failed to refresh outline:', err);
      }
    }

    renderEmpty() {
      if (!this.outlineListEl) return;
      this.outlineListEl.innerHTML = '<div class="kc-empty-hint">Headings and layout containers in the document appear here. Click to jump.</div>';
    }

    renderItems(items) {
      if (!this.outlineListEl) return;
      this.outlineListEl.innerHTML = '';

      items.forEach(item => {
        const row = document.createElement('button');
        row.type = 'button';
        row.className = `kc-outline-item kc-outline-level-${item.level}`;
        row.dataset.blockIndex = item.index;
        row.innerHTML = `<span class="kc-outline-ico">${item.icon}</span> <span class="kc-outline-text">${this.escapeHtml(item.label)}</span>`;
        
        row.addEventListener('click', () => {
          this.jumpToBlock(item.index);
        });

        this.outlineListEl.appendChild(row);
      });
    }

    jumpToBlock(index) {
      const runtime = global.KcEditorRuntime;
      if (!runtime || !runtime.instance) return;

      try {
        const blockObj = runtime.instance.blocks.getBlockByIndex(index);
        if (blockObj && blockObj.holder) {
          blockObj.holder.scrollIntoView({ behavior: 'smooth', block: 'center' });
          runtime.instance.caret.setToBlock(index);
          const currentBlock = { index, id: blockObj.id, name: blockObj.name, holder: blockObj.holder };
          runtime.emit('selectionChange', currentBlock);
        }
      } catch (err) {
        console.error('[NavigatorShell] Could not jump to block:', err);
      }
    }

    escapeHtml(str) {
      return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
  }

  global.KcNavigatorShell = NavigatorShell;
})(typeof window !== 'undefined' ? window : this);

