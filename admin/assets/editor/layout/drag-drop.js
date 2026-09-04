/**
 * Enterprise Knowledge Center - Layout Drag & Drop Module
 * Task EUX-05: Drag/Drop Component Label Leakage Bug Fix
 * 
 * Prevents palette label text leakage into canvas contenteditable areas by using
 * the custom MIME type `application/x-kc-component-id` and intercepting drop
 * events before native browser or Editor.js text insertion occurs.
 */
(function (global) {
  'use strict';

  const COMPONENT_MIME_TYPE = 'application/x-kc-component-id';
  let isInitialized = false;
  let activeComponentId = null;
  let dropIndicatorEl = null;

  function createDropIndicator() {
    if (dropIndicatorEl && dropIndicatorEl.parentNode) return dropIndicatorEl;
    dropIndicatorEl = document.createElement('div');
    dropIndicatorEl.className = 'kc-drag-drop-indicator';
    dropIndicatorEl.style.cssText = 'position:absolute;left:0;right:0;height:3px;background:#2563eb;border-radius:2px;pointer-events:none;z-index:9999;display:none;box-shadow:0 0 8px rgba(37,99,235,0.6);transition:top 0.08s ease;';
    document.body.appendChild(dropIndicatorEl);
    return dropIndicatorEl;
  }

  function hideDropIndicator() {
    if (dropIndicatorEl) {
      dropIndicatorEl.style.display = 'none';
    }
  }

  function calculateTargetBlockIndex(redactor, clientY) {
    if (!redactor) return 0;
    const blocks = Array.from(redactor.querySelectorAll('.ce-block'));
    if (!blocks.length) return 0;

    for (let i = 0; i < blocks.length; i++) {
      const rect = blocks[i].getBoundingClientRect();
      const midY = rect.top + rect.height / 2;
      if (clientY < midY) {
        return i;
      }
    }
    return blocks.length;
  }

  function updateDropIndicator(redactor, clientY) {
    if (!redactor) return;
    const blocks = Array.from(redactor.querySelectorAll('.ce-block'));
    const indicator = createDropIndicator();

    if (!blocks.length) {
      const rect = redactor.getBoundingClientRect();
      indicator.style.top = (rect.top + window.scrollY + 10) + 'px';
      indicator.style.left = (rect.left + window.scrollX) + 'px';
      indicator.style.width = rect.width + 'px';
      indicator.style.display = 'block';
      return;
    }

    let targetTop = 0;
    let targetLeft = 0;
    let targetWidth = 0;

    for (let i = 0; i < blocks.length; i++) {
      const rect = blocks[i].getBoundingClientRect();
      const midY = rect.top + rect.height / 2;
      if (clientY < midY) {
        targetTop = rect.top + window.scrollY - 2;
        targetLeft = rect.left + window.scrollX;
        targetWidth = rect.width;
        break;
      } else if (i === blocks.length - 1) {
        targetTop = rect.bottom + window.scrollY + 2;
        targetLeft = rect.left + window.scrollX;
        targetWidth = rect.width;
      }
    }

    if (targetWidth > 0) {
      indicator.style.top = targetTop + 'px';
      indicator.style.left = targetLeft + 'px';
      indicator.style.width = targetWidth + 'px';
      indicator.style.display = 'block';
    }
  }

  function init(options) {
    if (isInitialized && !options?.force) return;
    isInitialized = true;

    options = options || {};
    const getInserter = function () {
      return options.insertBlockByType || global.insertBlockByType;
    };

    // 1. Setup dragstart on component items
    function handleDragStart(e) {
      const compBtn = e.target.closest('.kc-component');
      if (!compBtn) return;

      const componentId = compBtn.dataset.componentId;
      if (!componentId) return;

      activeComponentId = componentId;
      global.__kcDraggingComponentId = componentId;

      // CRITICAL REQUIREMENT:
      // Use custom drag MIME type: application/x-kc-component-id
      // Do NOT use text/plain for the component drag payload.
      // Clear all standard text transfer to prevent native contenteditable drop text leakage.
      try {
        if (e.dataTransfer) {
          e.dataTransfer.clearData();
          e.dataTransfer.setData(COMPONENT_MIME_TYPE, componentId);
          e.dataTransfer.effectAllowed = 'copy';

          // Set custom drag preview if available
          const ghost = compBtn.cloneNode(true);
          ghost.style.position = 'absolute';
          ghost.style.top = '-9999px';
          ghost.style.left = '-9999px';
          ghost.style.opacity = '0.9';
          ghost.style.pointerEvents = 'none';
          ghost.style.transform = 'scale(0.85)';
          document.body.appendChild(ghost);
          e.dataTransfer.setDragImage(ghost, 20, 20);
          setTimeout(() => {
            if (ghost.parentNode) ghost.parentNode.removeChild(ghost);
          }, 0);
        }
      } catch (err) {
        // Fallback for older browsers
        if (e.dataTransfer) {
          e.dataTransfer.setData(COMPONENT_MIME_TYPE, componentId);
        }
      }
    }

    function handleDragEnd() {
      activeComponentId = null;
      global.__kcDraggingComponentId = null;
      hideDropIndicator();
    }

    // 2. Prevent native browser text drop into contenteditable areas during palette drag
    function handleDragOver(e) {
      const isComponentDrag = (e.dataTransfer && e.dataTransfer.types && (
        Array.from(e.dataTransfer.types).includes(COMPONENT_MIME_TYPE)
      )) || activeComponentId || global.__kcDraggingComponentId;

      if (!isComponentDrag) return;

      const canvas = document.getElementById('kc-editor-canvas');
      const redactor = document.querySelector('.codex-editor__redactor') || canvas;

      if (redactor && (redactor.contains(e.target) || canvas?.contains(e.target))) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'copy';
        updateDropIndicator(redactor, e.clientY);
      }
    }

    function handleDragLeave(e) {
      const canvas = document.getElementById('kc-editor-canvas');
      if (canvas && !canvas.contains(e.relatedTarget)) {
        hideDropIndicator();
      }
    }

    // 3. Drop handler: capture phase to stop native / Editor.js paste & insert cleanly
    function handleDrop(e) {
      const hasCustomType = e.dataTransfer && e.dataTransfer.types && (
        Array.from(e.dataTransfer.types).includes(COMPONENT_MIME_TYPE)
      );
      const activeId = activeComponentId || global.__kcDraggingComponentId;

      if (!hasCustomType && !activeId) {
        return; // Allow standard file/image drops or other drops to proceed normally
      }

      const canvas = document.getElementById('kc-editor-canvas');
      const redactor = document.querySelector('.codex-editor__redactor') || canvas;

      if (!redactor || (!redactor.contains(e.target) && !canvas?.contains(e.target))) {
        hideDropIndicator();
        return;
      }

      // CRITICAL: Stop propagation so Editor.js and browser never paste text into contenteditable
      e.preventDefault();
      e.stopPropagation();
      if (typeof e.stopImmediatePropagation === 'function') {
        e.stopImmediatePropagation();
      }

      hideDropIndicator();

      let componentId = null;
      try {
        if (e.dataTransfer) {
          componentId = e.dataTransfer.getData(COMPONENT_MIME_TYPE);
        }
      } catch (err) {}

      if (!componentId) {
        componentId = activeId;
      }

      activeComponentId = null;
      global.__kcDraggingComponentId = null;

      if (!componentId) return;

      // Calculate target index
      const targetIndex = calculateTargetBlockIndex(redactor, e.clientY);

      const inserter = getInserter();
      if (typeof inserter === 'function') {
        inserter(componentId, targetIndex);
      } else {
        console.error('[KcDragDrop] insertBlockByType function not found!');
      }
    }

    // Bind delegation on document for dynamic component buttons
    document.addEventListener('dragstart', handleDragStart, false);
    document.addEventListener('dragend', handleDragEnd, false);
    document.addEventListener('dragover', handleDragOver, false);
    document.addEventListener('dragleave', handleDragLeave, false);
    // Use capture phase for drop to intercept before any child contenteditable handles it
    document.addEventListener('drop', handleDrop, true);

    // Also suppress beforeinput if caused by drop of component
    document.addEventListener('beforeinput', function (e) {
      if ((activeComponentId || global.__kcDraggingComponentId) && e.inputType === 'insertFromDrop') {
        e.preventDefault();
      }
    }, true);
  }

  // Auto-init on DOM ready if document is loaded
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => init());
  } else {
    init();
  }

  global.KcDragDrop = {
    MIME_TYPE: COMPONENT_MIME_TYPE,
    init: init
  };

})(typeof window !== 'undefined' ? window : this);
