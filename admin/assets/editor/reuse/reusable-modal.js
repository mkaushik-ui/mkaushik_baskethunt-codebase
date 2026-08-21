/**
 * Task T7: Reusable Block Insertion Modal (Skeleton).
 */
(function (global) {
  'use strict';

  class ReusableModal {
    constructor() {
      this.modalEl = null;
    }

    open(onSelect) {
      console.log('[ReusableModal] Opening reusable blocks library picker.');
    }
  }

  global.KcReusableModal = ReusableModal;
})(typeof window !== 'undefined' ? window : this);
