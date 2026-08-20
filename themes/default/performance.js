/**
 * SOI CMS frontend performance — progressive media & skeletons (v1.2.10.1)
 * Graceful without IntersectionObserver; never permanently hides content.
 */
(function () {
  'use strict';

  document.documentElement.classList.add('soi-perf-js');

  function markLoaded(frame, img) {
    if (!frame) return;
    frame.classList.remove('is-loading');
    frame.classList.add('is-loaded');
    if (img) img.style.opacity = '';
  }

  function markError(frame) {
    if (!frame) return;
    frame.classList.remove('is-loading');
    frame.classList.add('is-error');
  }

  function wireMediaFrame(frame) {
    var img = frame.querySelector('img');
    if (!img) {
      frame.classList.remove('is-loading');
      return;
    }

    if (img.complete && img.naturalWidth > 0) {
      markLoaded(frame, img);
      return;
    }

    frame.classList.add('is-loading');
    img.addEventListener('load', function () { markLoaded(frame, img); }, { once: true });
    img.addEventListener('error', function () { markError(frame); }, { once: true });
  }

  function initMediaFrames(root) {
    (root || document).querySelectorAll('.soi-media-frame').forEach(wireMediaFrame);
  }

  function initDeferBlocks() {
    var blocks = document.querySelectorAll('.soi-defer-block');
    if (!blocks.length) return;

    if (!('IntersectionObserver' in window)) {
      blocks.forEach(function (el) {
        el.classList.remove('is-pending');
        el.classList.add('is-visible');
      });
      return;
    }

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        entry.target.classList.remove('is-pending');
        entry.target.classList.add('is-visible');
        io.unobserve(entry.target);
      });
    }, { rootMargin: '120px 0px', threshold: 0.01 });

    blocks.forEach(function (el) {
      el.classList.add('is-pending');
      io.observe(el);
    });
  }

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  ready(function () {
    initMediaFrames(document);
    initDeferBlocks();
  });
})();
