/**
 * Task T2: Panels Controller (Skeleton).
 * Manages Left Navigator Panel and Right Inspector Panel collapse/expand and distraction-free mode.
 */
(function (global) {
  'use strict';

  class PanelsController {
    constructor() {
      this.leftOpen = true;
      this.rightOpen = true;
      this.leftEl = document.getElementById('kc-sidebar-left');
      this.rightEl = document.getElementById('kc-sidebar-right');
    }

    toggleLeft() {
      this.leftOpen = !this.leftOpen;
      if (this.leftEl) {
        this.leftEl.dataset.state = this.leftOpen ? 'open' : 'collapsed';
      }
    }

    toggleRight() {
      this.rightOpen = !this.rightOpen;
      if (this.rightEl) {
        this.rightEl.dataset.state = this.rightOpen ? 'open' : 'collapsed';
      }
    }

    toggleDistractionFree() {
      const collapse = (this.leftOpen || this.rightOpen);
      this.leftOpen = !collapse;
      this.rightOpen = !collapse;
      if (this.leftEl) this.leftEl.dataset.state = this.leftOpen ? 'open' : 'collapsed';
      if (this.rightEl) this.rightEl.dataset.state = this.rightOpen ? 'open' : 'collapsed';
    }
  }

  global.KcPanelsController = PanelsController;
})(typeof window !== 'undefined' ? window : this);
