/**
 * Admin Media Library thumbnail progressive load (v1.2.10.1)
 * Does not touch CSRF, auth, or destructive actions.
 */
(function () {
  'use strict';

  function wireThumb(thumb) {
    var img = thumb.querySelector('img.ml-thumb-image');
    if (!img) {
      thumb.classList.remove('is-thumb-loading');
      return;
    }

    function done() {
      thumb.classList.remove('is-thumb-loading');
      thumb.classList.add('is-thumb-loaded');
    }
    function fail() {
      thumb.classList.remove('is-thumb-loading');
      thumb.classList.add('is-thumb-error');
      img.classList.add('ml-thumb--hidden');
      var ph = thumb.querySelector('.ml-thumb-placeholder');
      if (ph) ph.classList.add('is-visible');
    }

    if (img.complete && img.naturalWidth > 0) {
      done();
      return;
    }

    thumb.classList.add('is-thumb-loading');
    img.addEventListener('load', done, { once: true });
    img.addEventListener('error', fail, { once: true });
  }

  function init() {
    document.querySelectorAll('.media-library .ml-card-thumb').forEach(wireThumb);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
