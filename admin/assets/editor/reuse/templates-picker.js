/**
 * Task T7: Starter Templates Picker Modal (Skeleton).
 */
(function (global) {
  'use strict';

  class TemplatesPicker {
    constructor() {
      this.modalEl = null;
    }

    open(onTemplateSelected) {
      console.log('[TemplatesPicker] Opening starter templates gallery.');
    }
  }

  global.KcTemplatesPicker = TemplatesPicker;
})(typeof window !== 'undefined' ? window : this);
