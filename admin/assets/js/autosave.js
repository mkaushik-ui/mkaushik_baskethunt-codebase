(function (global) {
  'use strict';

  const DEBOUNCE_DELAY_MS = 2500; // 2.5s mandatory timer
  const IDLE_TRIGGER_MS = 800;    // Adaptive idle pause trigger

  class KcAutosaveService {
    constructor(options = {}) {
      this.endpoint = options.endpoint || '/api/autosave';
      this.documentId = options.documentId || 'doc_demo_101';
      this.statusChipEl = options.statusChipEl || null;
      this.onSaveComplete = options.onSaveComplete || null;
      this.onRevisionCreated = options.onRevisionCreated || null;

      this.timer = null;
      this.idleTimer = null;
      this.isDirty = false;
      this.isSaving = false;
      this.lastContent = '';
      this.lastContentHash = '';
      this.revisionHistory = [];
      this.offlineQueueKey = `kc_autosave_queue_${this.documentId}`;

      this.initNetworkListeners();
    }

    initNetworkListeners() {
      window.addEventListener('online', () => {
        this.syncOfflineQueue();
      });
      window.addEventListener('offline', () => {
        if (this.isDirty) {
          this.updateStatusChip('offline', 'Offline (Queued)');
        }
      });
    }

    /**
     * Bind canvas editor input events to trigger adaptive 2.5s debounced timer.
     */
    attachToEditor(editorElement) {
      if (!editorElement) return;

      this.editorElement = editorElement;
      this.lastContent = this.getEditorContent();

      const triggerHandler = () => {
        this.markDirty();
      };

      editorElement.addEventListener('input', triggerHandler);
      editorElement.addEventListener('keyup', triggerHandler);
      editorElement.addEventListener('change', triggerHandler);
    }

    markDirty() {
      const currentContent = this.getEditorContent();
      if (currentContent === this.lastContent && !this.isDirty) return;

      this.isDirty = true;
      const diffLen = currentContent.length - this.lastContent.length;
      const diffText = diffLen > 0 ? `+${diffLen} chars` : `${diffLen} chars`;

      this.updateStatusChip('unsaved', `Unsaved (${diffText})`);

      if (this.timer) clearTimeout(this.timer);
      if (this.idleTimer) clearTimeout(this.idleTimer);

      // Adaptive idle trigger (800ms idle pause after typing)
      this.idleTimer = setTimeout(() => {
        this.executeAutosave();
      }, IDLE_TRIGGER_MS);

      // Acceptance Criteria: 2.5s mandatory max timer
      this.timer = setTimeout(() => {
        this.executeAutosave();
      }, DEBOUNCE_DELAY_MS);
    }

    async executeAutosave() {
      if (!this.isDirty || this.isSaving) return;

      if (this.timer) clearTimeout(this.timer);
      if (this.idleTimer) clearTimeout(this.idleTimer);

      const content = this.getEditorContent();

      // Check if offline
      if (!navigator.onLine) {
        this.queueOffline(content);
        this.updateStatusChip('offline', 'Offline (Saved locally)');
        return;
      }

      this.isSaving = true;
      this.updateStatusChip('autosaving', 'Autosaving...');

      const payload = {
        document_id: this.documentId,
        content: content,
        previous_hash: this.lastContentHash,
        timestamp: new Date().toISOString()
      };

      try {
        let responseData;
        if (typeof window.mockAutosaveApi === 'function') {
          responseData = await window.mockAutosaveApi(payload);
        } else {
          const res = await fetch(this.endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
          });
          responseData = await res.json();
        }

        if (responseData && responseData.autosave === true && responseData.success) {
          this.isDirty = false;
          this.lastContent = content;
          this.lastContentHash = responseData.content_hash || '';

          const statusLabel = responseData.is_delta === false ? 'Saved (No changes)' : `Saved ${this.formatTime(new Date())}`;
          this.updateStatusChip('saved', statusLabel, responseData.revision_id);

          if (responseData.is_delta !== false) {
            this.revisionHistory.unshift({
              revision_id: responseData.revision_id,
              timestamp: responseData.updated_at || new Date().toISOString(),
              bytes: responseData.saved_bytes,
              lock_token: responseData.lock_token
            });

            if (this.onRevisionCreated) {
              this.onRevisionCreated(this.revisionHistory[0]);
            }
          }

          if (this.onSaveComplete) this.onSaveComplete(responseData);
        } else {
          this.updateStatusChip('failed', 'Save failed');
        }
      } catch (err) {
        this.queueOffline(content);
        this.updateStatusChip('failed', 'Save failed');
      } finally {
        this.isSaving = false;
      }
    }

    queueOffline(content) {
      try {
        localStorage.setItem(this.offlineQueueKey, JSON.stringify({
          content,
          timestamp: new Date().toISOString()
        }));
      } catch (e) {
        // Fallback
      }
    }

    async syncOfflineQueue() {
      try {
        const raw = localStorage.getItem(this.offlineQueueKey);
        if (raw) {
          const queued = JSON.parse(raw);
          localStorage.removeItem(this.offlineQueueKey);
          this.isDirty = true;
          await this.executeAutosave();
        }
      } catch (e) {}
    }

    getEditorContent() {
      if (!this.editorElement) return '';
      return this.editorElement.innerText || this.editorElement.value || '';
    }

    updateStatusChip(state, label, revisionId = '') {
      if (!this.statusChipEl) return;
      this.statusChipEl.className = `kc-status-chip chip-${state}`;

      let iconSvg = '';
      if (state === 'unsaved') {
        iconSvg = '<span class="chip-dot"></span>';
      } else if (state === 'autosaving') {
        iconSvg = '<svg class="spinner" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="10" stroke-dasharray="32" stroke-dashoffset="12"/></svg>';
      } else if (state === 'saved') {
        iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>';
      } else if (state === 'offline') {
        iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="1" y1="1" x2="23" y2="23"/><path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"/><path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"/></svg>';
      } else if (state === 'failed') {
        iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
      }

      const tooltipText = revisionId ? `Revision: ${revisionId}` : '';
      const tooltipAttr = tooltipText ? ` title="${tooltipText}"` : '';

      this.statusChipEl.innerHTML = `<span ${tooltipAttr} style="display: flex; align-items: center; gap: 0.4rem;">${iconSvg}<span>${label}</span></span>`;
    }

    formatTime(date) {
      return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }
  }

  global.KcAutosaveService = KcAutosaveService;
})(window);
