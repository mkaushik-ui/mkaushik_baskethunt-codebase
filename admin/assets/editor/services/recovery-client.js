/**
 * Task T6: Local Browser Storage Recovery Buffer (Skeleton).
 * Stores uncommitted edits locally to recover after browser crashes.
 */
(function (global) {
  'use strict';

  class RecoveryClient {
    constructor(docId) {
      this.storageKey = `kc_draft_recovery_${docId}`;
    }

    saveBuffer(documentData) {
      try {
        localStorage.setItem(this.storageKey, JSON.stringify({
          time: Date.now(),
          data: documentData
        }));
      } catch (e) {
        // Storage quota full or unavailable
      }
    }

    checkBuffer() {
      try {
        const raw = localStorage.getItem(this.storageKey);
        return raw ? JSON.parse(raw) : null;
      } catch (e) {
        return null;
      }
    }

    clearBuffer() {
      try {
        localStorage.removeItem(this.storageKey);
      } catch (e) {}
    }
  }

  global.KcRecoveryClient = RecoveryClient;
})(typeof window !== 'undefined' ? window : this);
