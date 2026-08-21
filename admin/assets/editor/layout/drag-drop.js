/**
 * Task T4: Drag and Drop Engine (Skeleton).
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
        }
      });
    }

    bindDropZones() {
      const canvas = document.getElementById('kc-editorjs');
      if (!canvas) return;

      canvas.addEventListener('dragover', (e) => {
        e.preventDefault();
        canvas.classList.add('kc-dropzone-active');
      });

      canvas.addEventListener('dragleave', () => {
        canvas.classList.remove('kc-dropzone-active');
      });

      canvas.addEventListener('drop', (e) => {
        e.preventDefault();
        canvas.classList.remove('kc-dropzone-active');
        const componentId = e.dataTransfer.getData('text/plain');
        if (componentId && global.KcEditorRuntime) {
          // Developer 4: Insert block at drop coordinate
          console.log('[DragDrop] Component dropped:', componentId);
        }
      });
    }
  }

  global.KcDragDropEngine = DragDropEngine;
})(typeof window !== 'undefined' ? window : this);
