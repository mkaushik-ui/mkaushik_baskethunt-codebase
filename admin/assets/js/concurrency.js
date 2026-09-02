(function (global) {
  'use strict';

  class KcConcurrencyHandler {
    constructor(options = {}) {
      this.modalEl = options.modalEl || document.getElementById('kc-conflict-modal');
      this.onForceSave = options.onForceSave || null;
      this.onReloadRemote = options.onReloadRemote || null;
      this.currentConflictData = null;

      this.initModalEvents();
    }

    /**
     * Intercept API save response and check for edit collision conflict.
     */
    handleSaveResponse(responsePayload, localPayload) {
      if (responsePayload && responsePayload.ok === false && responsePayload.conflict === true) {
        this.currentConflictData = {
          response: responsePayload,
          local: localPayload
        };
        this.openConflictModal(responsePayload);
        return true; // Conflict detected
      }
      return false; // No conflict
    }

    openConflictModal(data) {
      let modal = this.modalEl;
      if (!modal) {
        modal = this.createDefaultModal();
        this.modalEl = modal;
      }

      const expectedEl = modal.querySelector('#conflict-expected-time');
      const remoteEl = modal.querySelector('#conflict-remote-time');
      const authorEl = modal.querySelector('#conflict-author');

      if (expectedEl) expectedEl.textContent = data.expected_updated_at || 'Stale version';
      if (remoteEl) remoteEl.textContent = data.remote_updated_at || 'Just now';
      if (authorEl) authorEl.textContent = data.remote_author || 'Colleague';

      modal.style.display = 'flex';
      modal.setAttribute('aria-hidden', 'false');
    }

    closeConflictModal() {
      if (this.modalEl) {
        this.modalEl.style.display = 'none';
        this.modalEl.setAttribute('aria-hidden', 'true');
      }
    }

    initModalEvents() {
      if (!this.modalEl) return;

      const overwriteBtn = this.modalEl.querySelector('#btn-conflict-overwrite');
      const reloadBtn = this.modalEl.querySelector('#btn-conflict-reload');
      const cancelBtn = this.modalEl.querySelector('#btn-conflict-cancel');

      if (overwriteBtn) {
        overwriteBtn.addEventListener('click', () => {
          this.closeConflictModal();
          if (this.onForceSave && this.currentConflictData) {
            this.onForceSave(this.currentConflictData.local);
          }
        });
      }

      if (reloadBtn) {
        reloadBtn.addEventListener('click', () => {
          this.closeConflictModal();
          if (this.onReloadRemote && this.currentConflictData) {
            this.onReloadRemote(this.currentConflictData.response);
          }
        });
      }

      if (cancelBtn) {
        cancelBtn.addEventListener('click', () => {
          this.closeConflictModal();
        });
      }
    }

    createDefaultModal() {
      const modal = document.createElement('div');
      modal.id = 'kc-conflict-modal';
      modal.className = 'kc-modal-overlay';
      modal.style.cssText = 'position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(15,23,42,0.8); display:none; align-items:center; justify-content:center; z-index:9999; backdrop-filter:blur(8px);';

      modal.innerHTML = `
        <div class="kc-modal-dialog" style="background:#1e293b; border:1px solid rgba(255,255,255,0.15); border-radius:12px; max-width:500px; width:90%; padding:1.5rem; color:#f8fafc; font-family:Inter,sans-serif; box-shadow:0 20px 40px rgba(0,0,0,0.4);">
          <div style="display:flex; align-items:center; gap:0.6rem; margin-bottom:1rem; color:#ef4444;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
            <h3 style="margin:0; font-size:1.1rem; font-weight:800; color:#fff;">Edit Collision Detected</h3>
          </div>
          <p style="font-size:0.85rem; color:#94a3b8; line-height:1.5; margin-bottom:1.2rem;">
            Another user (<strong id="conflict-author" style="color:#38bdf8;">Colleague</strong>) has saved a newer version of this document while you were editing.
          </p>
          <div style="background:#0f172a; border:1px solid rgba(255,255,255,0.1); border-radius:8px; padding:0.85rem; font-size:0.78rem; margin-bottom:1.25rem;">
            <div style="margin-bottom:0.35rem;">Your Expected Version: <span id="conflict-expected-time" style="color:#f59e0b; font-weight:700;">-</span></div>
            <div>Database Version: <span id="conflict-remote-time" style="color:#10b981; font-weight:700;">-</span></div>
          </div>
          <div style="display:flex; justify-content:flex-end; gap:0.65rem;">
            <button id="btn-conflict-cancel" style="background:transparent; border:1px solid #475569; color:#cbd5e1; padding:0.5rem 0.85rem; border-radius:6px; font-size:0.8rem; font-weight:700; cursor:pointer;">Cancel</button>
            <button id="btn-conflict-reload" style="background:#0284c7; color:#fff; border:none; padding:0.5rem 0.85rem; border-radius:6px; font-size:0.8rem; font-weight:700; cursor:pointer;">Reload Latest</button>
            <button id="btn-conflict-overwrite" style="background:#dc2626; color:#fff; border:none; padding:0.5rem 0.85rem; border-radius:6px; font-size:0.8rem; font-weight:700; cursor:pointer;">Overwrite Remote</button>
          </div>
        </div>
      `;

      document.body.appendChild(modal);
      return modal;
    }
  }

  global.KcConcurrencyHandler = KcConcurrencyHandler;
})(window);
