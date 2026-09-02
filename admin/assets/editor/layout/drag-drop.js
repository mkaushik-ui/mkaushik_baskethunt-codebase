/**
 * Task T4: Drag and Drop Engine.
 * Handles drag-to-canvas insertions and drop-zone indicators.
 */
(function (global) {
  'use strict';

  class DragDropEngine {
    constructor() {
      this.activeDraggedComponent = null;
    }

    init() {
      this.bindDraggables();
      this.bindDropZones();
    }

    bindDraggables() {
      document.addEventListener('dragstart', (e) => {
        const item = e.target.closest('[data-component-id]');
        if (item) {
          this.activeDraggedComponent = item.dataset.componentId;
          e.dataTransfer.setData('text/plain', this.activeDraggedComponent);
          e.dataTransfer.effectAllowed = 'copy';
        }
      });

      document.addEventListener('dragend', () => {
        this.activeDraggedComponent = null;
        const canvas = document.getElementById('kc-editorjs');
        if (canvas) canvas.classList.remove('kc-dropzone-active');
      });
    }

    bindDropZones() {
      const canvas = document.getElementById('kc-editorjs');
      if (!canvas) return;

      canvas.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'copy';
        canvas.classList.add('kc-dropzone-active');
      });

      canvas.addEventListener('dragleave', (e) => {
        if (!canvas.contains(e.relatedTarget)) {
          canvas.classList.remove('kc-dropzone-active');
        }
      });

      canvas.addEventListener('drop', (e) => {
        e.preventDefault();
        canvas.classList.remove('kc-dropzone-active');
        const componentId = e.dataTransfer.getData('text/plain') || this.activeDraggedComponent;
        if (!componentId) return;

        this.insertComponentAtDrop(componentId, e.clientY);
      });
    }

    /**
     * Map component ID to EditorJS block type and default payload, then insert.
     */
    insertComponentAtDrop(componentId, clientY) {
      const runtime = global.KcEditorRuntime;
      if (!runtime || !runtime.instance || typeof runtime.instance.blocks?.insert !== 'function') {
        console.warn('[DragDrop] EditorJS runtime is not available for insertion.');
        return;
      }

      // Map component IDs to Editor.js block type and default data
      let type = componentId;
      let data = {};

      switch (componentId) {
        case 'paragraph':
          type = 'paragraph';
          data = { text: '' };
          break;
        case 'heading':
        case 'h2':
          type = 'header';
          data = { text: '', level: 2 };
          break;
        case 'h3':
          type = 'header';
          data = { text: '', level: 3 };
          break;
        case 'list-unordered':
          type = 'list';
          data = { style: 'unordered', items: [''] };
          break;
        case 'list-ordered':
          type = 'list';
          data = { style: 'ordered', items: [''] };
          break;
        case 'callout':
          type = 'callout';
          data = { tone: 'info', title: '', text: '' };
          break;
        case 'steps':
          type = 'steps';
          data = { items: [{ title: 'Step 1', text: '' }] };
          break;
        case 'accordion':
          type = 'accordion';
          data = { items: [{ title: 'Section Title', content: '' }] };
          break;
        case 'faq':
          type = 'faq';
          data = { items: [{ question: 'Question?', answer: 'Answer...' }] };
          break;
        case 'tabs':
          type = 'tabs';
          data = { items: [{ label: 'Tab 1', content: '' }] };
          break;
        case 'table':
          type = 'table';
          data = { withHeadings: true, content: [['Header 1', 'Header 2'], ['Cell 1', 'Cell 2']] };
          break;
        case 'code':
          type = 'code';
          data = { code: '', language: 'javascript' };
          break;
        case 'apiEndpoint':
          type = 'apiEndpoint';
          data = { method: 'GET', endpoint: '/api/v1/resource', description: '' };
          break;
        case 'keyValues':
          type = 'keyValues';
          data = { pairs: [{ key: 'Key', value: 'Value' }] };
          break;
        default:
          type = componentId;
          break;
      }

      // Find insertion index based on mouse vertical coordinate
      const blockNodes = Array.from(document.querySelectorAll('.ce-block'));
      let targetIndex = blockNodes.length;

      for (let i = 0; i < blockNodes.length; i++) {
        const rect = blockNodes[i].getBoundingClientRect();
        if (clientY < rect.top + (rect.height / 2)) {
          targetIndex = i;
          break;
        }
      }

      try {
        runtime.instance.blocks.insert(type, data, {}, targetIndex, true);
        console.log(`[DragDrop] Inserted ${type} block at index ${targetIndex}.`);
      } catch (err) {
        console.error('[DragDrop] Insertion failed:', err);
      }
    }
  }

  global.KcDragDropEngine = DragDropEngine;
})(typeof window !== 'undefined' ? window : this);

