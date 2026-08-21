/**
 * Task T2: Inspector Shell Component (Skeleton).
 * Synchronizes with active block selection to render document or block-specific settings.
 */
(function (global) {
  'use strict';

  class InspectorShell {
    constructor(hostEl) {
      this.host = typeof hostEl === 'string' ? document.getElementById(hostEl) : hostEl;
      this.currentMode = 'document'; // 'document' or 'block'
      this.selectedBlock = null;
    }

    init() {
      if (!this.host) return;
      this.render();
      if (global.KcEditorRuntime) {
        global.KcEditorRuntime.on('selectionChange', (block) => {
          this.setBlock(block);
        });
      }
    }

    setBlock(block) {
      this.selectedBlock = block;
      this.currentMode = block ? 'block' : 'document';
      this.render();
    }

    render() {
      if (!this.host) return;
      if (this.currentMode === 'document') {
        this.host.innerHTML = `
          <div class="kc-inspector-head"><h3>Document Settings</h3></div>
          <div class="kc-inspector-body">
            <label>URL Slug</label>
            <input type="text" id="kc-inspector-slug" class="kc-input" />
            <label>Meta Description</label>
            <textarea id="kc-inspector-desc" class="kc-textarea"></textarea>
          </div>
        `;
      } else {
        this.host.innerHTML = `
          <div class="kc-inspector-head"><h3>Block Properties: ${this.selectedBlock.name || 'Block'}</h3></div>
          <div class="kc-inspector-body" id="kc-inspector-fields">
            <!-- Developer 2: Render block-specific inspector controls here -->
          </div>
        `;
      }
    }
  }

  global.KcInspectorShell = InspectorShell;
})(typeof window !== 'undefined' ? window : this);
