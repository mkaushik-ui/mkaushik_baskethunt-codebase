/**
 * Task T6: Debounced Client Autosave (Skeleton).
 * Debounces input edits to 2500ms before sending background draft save.
 */
(function (global) {
  'use strict';

  class AutosaveClient {
    constructor(options = {}) {
      this.debounceMs = options.debounceMs || 2500;
      this.timer = null;
      this.statusEl = document.getElementById('kc-save-status');
    }

    scheduleSave(saveCallback) {
      if (this.statusEl) {
        this.statusEl.dataset.state = 'unsaved';
        this.statusEl.textContent = 'Unsaved';
      }
      clearTimeout(this.timer);
      this.timer = setTimeout(async () => {
        if (this.statusEl) {
          this.statusEl.dataset.state = 'saving';
          this.statusEl.textContent = 'Saving...';
        }
        try {
          if (typeof saveCallback === 'function') {
            await saveCallback();
          }
          if (this.statusEl) {
            this.statusEl.dataset.state = 'saved';
            this.statusEl.textContent = 'Saved';
          }
        } catch (err) {
          if (this.statusEl) {
            this.statusEl.dataset.state = 'error';
            this.statusEl.textContent = 'Save Failed';
          }
        }
      }, this.debounceMs);
    }
  }

  global.KcAutosaveClient = AutosaveClient;
})(typeof window !== 'undefined' ? window : this);
