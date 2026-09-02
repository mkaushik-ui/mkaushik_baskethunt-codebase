/**
 * KcPaginationEngine — SOI Knowledge Center 1.1.0
 *
 * Architecture:
 *   Canonical Document (Editor.js flat block sequence)
 *         ↓
 *   Block Measurement (getBoundingClientRect per .ce-block)
 *         ↓
 *   Layout & Pagination Engine (deterministic sequential flow)
 *         ↓
 *   Derived Page Layout (never stored in canonical document)
 *         ↓
 *   Presentation Renderer (CSS classes + visual markers only)
 *
 * Contract:
 *   - Never inserts nodes INSIDE .codex-editor__redactor
 *   - Never reorders blocks during pagination
 *   - Never stores block.page as authoritative location
 *   - Block order is always canonical Editor.js sequence
 *   - Page membership is always DERIVED from block dimensions + layout constraints
 *   - Pagination is deterministic given same document + same layout constraints
 */
(function (global) {
  'use strict';

  function PageConstraints(options) {
    options = options || {};
    this.pageWidth    = options.pageWidth    || 794;
    this.pageHeight   = options.pageHeight   || 1123;
    this.marginTop    = options.marginTop    || 72;
    this.marginBottom = options.marginBottom || 72;
    this.marginLeft   = options.marginLeft   || 60;
    this.marginRight  = options.marginRight  || 60;
    this.pageGap      = options.pageGap      || 32;
    this.usableHeight = this.pageHeight - this.marginTop - this.marginBottom;
    this.usableWidth  = this.pageWidth  - this.marginLeft - this.marginRight;
  }

  function measureBlock(blockEl) {
    if (!blockEl) return { height: 0, width: 0 };
    var rect = blockEl.getBoundingClientRect();
    return {
      height: Math.max(Math.ceil(rect.height), 0),
      width:  Math.max(Math.ceil(rect.width),  0)
    };
  }

  function getCanonicalBlockElements(holderEl) {
    if (!holderEl) return [];
    var redactor = holderEl.querySelector('.codex-editor__redactor');
    if (!redactor) return [];
    return Array.prototype.slice.call(redactor.querySelectorAll(':scope > .ce-block'));
  }

  function inferBlockType(blockEl) {
    if (!blockEl) return 'unknown';
    var inner = blockEl.querySelector('.ce-block__content');
    if (!inner || !inner.firstElementChild) return 'unknown';
    var cls = inner.firstElementChild.className || '';
    var m = cls.match(/ce-([a-z-]+)|cdx-([a-z-]+)|kc-([a-z-]+)/);
    return m ? (m[1] || m[2] || m[3]) : 'block';
  }

  function flowBlocks(canonicalBlocks, constraints) {
    var usable  = constraints.usableHeight;
    var pages   = [];
    var cur     = { pageNum: 1, blocks: [], contentHeight: 0 };
    var remaining = usable;

    for (var i = 0; i < canonicalBlocks.length; i++) {
      var el = canonicalBlocks[i];
      var m  = measureBlock(el);
      var h  = Math.max(m.height, 1);
      var id = (el.dataset && el.dataset.id) ? el.dataset.id : ('b' + i);

      var entry = { canonicalIndex: i, blockId: id, height: h, el: el, derivedPage: cur.pageNum };

      if (cur.blocks.length > 0 && h > remaining) {
        pages.push(cur);
        cur       = { pageNum: pages.length + 1, blocks: [], contentHeight: 0 };
        remaining = usable;
        entry.derivedPage = cur.pageNum;
      }

      cur.blocks.push(entry);
      cur.contentHeight += h;
      remaining -= h;
      if (remaining < 0) remaining = 0;
    }

    if (cur.blocks.length > 0 || pages.length === 0) pages.push(cur);
    return pages;
  }

  function buildDiagnostic(holderEl, constraints) {
    var blocks = getCanonicalBlockElements(holderEl);
    var pages  = flowBlocks(blocks, constraints);

    var blockRecords = blocks.map(function (el, i) {
      var m  = measureBlock(el);
      var id = (el.dataset && el.dataset.id) ? el.dataset.id : ('b' + i);
      return {
        canonicalIndex: i,
        blockId:        id,
        inferredType:   inferBlockType(el),
        measuredHeight: m.height,
        measuredWidth:  m.width,
        derivedPage:    null
      };
    });

    pages.forEach(function (p) {
      p.blocks.forEach(function (b) {
        if (blockRecords[b.canonicalIndex]) {
          blockRecords[b.canonicalIndex].derivedPage = p.pageNum;
        }
      });
    });

    return {
      timestamp:          new Date().toISOString(),
      canonicalBlockCount: blocks.length,
      derivedPageCount:   pages.length,
      constraints: {
        pageWidth:     constraints.pageWidth,
        pageHeight:    constraints.pageHeight,
        marginTop:     constraints.marginTop,
        marginBottom:  constraints.marginBottom,
        usableHeight:  constraints.usableHeight
      },
      canonicalOrder: blockRecords,
      derivedPages:   pages.map(function (p) {
        return {
          pageNum:       p.pageNum,
          blockCount:    p.blocks.length,
          contentHeight: p.contentHeight,
          utilization:   constraints.usableHeight > 0
            ? Math.round((p.contentHeight / constraints.usableHeight) * 100) + '%'
            : '—',
          blockIndices:  p.blocks.map(function (b) { return b.canonicalIndex; }),
          blockIds:      p.blocks.map(function (b) { return b.blockId; })
        };
      })
    };
  }

  var CLASSES = {
    lastOnPage: 'kc-page-last-block',
    firstOnPage: 'kc-page-first-block',
    paginated: 'kc-block-paginated'
  };

  function clearPaginationClasses(holderEl) {
    if (!holderEl) return;
    var els = holderEl.querySelectorAll(
      '.' + CLASSES.lastOnPage + ',.' + CLASSES.firstOnPage + ',.' + CLASSES.paginated
    );
    Array.prototype.forEach.call(els, function (el) {
      el.classList.remove(CLASSES.lastOnPage, CLASSES.firstOnPage, CLASSES.paginated);
    });
  }

  function applyPageClasses(pages) {
    var lastPageIdx = pages.length - 1;
    pages.forEach(function (page, pageIdx) {
      page.blocks.forEach(function (b, blockIdx) {
        b.el.classList.add(CLASSES.paginated);
        if (blockIdx === page.blocks.length - 1 && pageIdx < lastPageIdx) {
          b.el.classList.add(CLASSES.lastOnPage);
        }
        if (blockIdx === 0 && page.pageNum > 1) {
          b.el.classList.add(CLASSES.firstOnPage);
        }
      });
    });
  }

  function renderBreakMarkers(pages, markersEl, surfaceEl) {
    if (!markersEl || !surfaceEl) return;
    markersEl.innerHTML = '';
    if (pages.length <= 1) return;

    var surfaceRect = surfaceEl.getBoundingClientRect();

    for (var p = 0; p < pages.length - 1; p++) {
      var page = pages[p];
      if (!page.blocks.length) continue;
      var lastBlock = page.blocks[page.blocks.length - 1];
      var blockRect = lastBlock.el.getBoundingClientRect();
      var breakY    = blockRect.bottom - surfaceRect.top + surfaceEl.scrollTop;

      var marker = document.createElement('div');
      marker.className = 'kc-page-break-marker';
      marker.setAttribute('aria-hidden', 'true');
      marker.dataset.afterPage  = String(p + 1);
      marker.dataset.beforePage = String(p + 2);
      marker.style.top = Math.round(breakY) + 'px';
      marker.innerHTML =
        '<span class="kc-page-break-badge">' +
        'END OF PAGE ' + (p + 1) + ' · BEGIN PAGE ' + (p + 2) +
        '</span>';
      markersEl.appendChild(marker);
    }
  }

  // ─── Public Engine ───────────────────────────────────────────────────────

  function KcPaginationEngine(options) {
    options = options || {};
    this.holderEl  = options.holder  || document.getElementById('kc-editorjs');
    this.stageEl   = options.stage   || document.getElementById('kc-doc-stage');
    this.surfaceEl = options.surface || document.getElementById('kc-doc-surface');
    this.constraints = new PageConstraints(options.constraints || {});

    this._mode         = 'continuous';
    this._derivedPages = [];
    this._rafId        = null;
    this._markersEl    = null;

    this._initMarkersContainer();

    var self = this;
    global.__KC_PAGINATION_DIAGNOSTIC = function () {
      return self.getDiagnostic();
    };
    global.__KC_PAGINATION_DIAGNOSTIC_PRINT = function () {
      var d = self.getDiagnostic();
      console.group('[KcPagination] Diagnostic — ' + d.timestamp);
      console.log('Canonical blocks:', d.canonicalBlockCount, '  Derived pages:', d.derivedPageCount);
      console.log('Usable page height:', d.constraints.usableHeight + 'px');
      console.log('─── Canonical Order (document truth) ───');
      console.table(d.canonicalOrder);
      console.log('─── Derived Page Layout (presentation) ───');
      console.table(d.derivedPages);
      console.groupEnd();
      return d;
    };
  }

  KcPaginationEngine.prototype._initMarkersContainer = function () {
    if (!this.surfaceEl) return;
    var existing = this.surfaceEl.querySelector('#kc-page-break-markers');
    if (existing) existing.remove();

    this._markersEl = document.createElement('div');
    this._markersEl.id = 'kc-page-break-markers';
    this._markersEl.className = 'kc-page-break-markers';
    this._markersEl.setAttribute('aria-hidden', 'true');

    var editorEl = this.surfaceEl.querySelector('#kc-editorjs');
    if (editorEl && editorEl.nextSibling) {
      this.surfaceEl.insertBefore(this._markersEl, editorEl.nextSibling);
    } else {
      this.surfaceEl.appendChild(this._markersEl);
    }
  };

  KcPaginationEngine.prototype.setMode = function (mode) {
    this._mode = mode;
    this.invalidate();
  };

  KcPaginationEngine.prototype.invalidate = function () {
    if (this._rafId) cancelAnimationFrame(this._rafId);
    var self = this;
    this._rafId = requestAnimationFrame(function () {
      self._rafId = null;
      self._reflow();
    });
  };

  KcPaginationEngine.prototype.reflow = function () {
    if (this._rafId) { cancelAnimationFrame(this._rafId); this._rafId = null; }
    this._reflow();
  };

  KcPaginationEngine.prototype._reflow = function () {
    var canonicalBlocks = getCanonicalBlockElements(this.holderEl);

    clearPaginationClasses(this.holderEl);
    if (this._markersEl) this._markersEl.innerHTML = '';

    if (this._mode !== 'paginated') {
      this._derivedPages = [];
      if (this.stageEl)   { this.stageEl.dataset.pageCount = '1'; delete this.stageEl.dataset.paginationMode; }
      if (this.surfaceEl) { delete this.surfaceEl.dataset.paginationMode; }
      return;
    }

    this._derivedPages = flowBlocks(canonicalBlocks, this.constraints);
    applyPageClasses(this._derivedPages);
    renderBreakMarkers(this._derivedPages, this._markersEl, this.surfaceEl);

    if (this.stageEl)   { this.stageEl.dataset.pageCount = String(this._derivedPages.length); this.stageEl.dataset.paginationMode = 'paginated'; }
    if (this.surfaceEl) { this.surfaceEl.dataset.paginationMode = 'paginated'; }
  };

  Object.defineProperty(KcPaginationEngine.prototype, 'pageCount', {
    get: function () { return this._derivedPages.length || 1; }
  });

  Object.defineProperty(KcPaginationEngine.prototype, 'derivedPages', {
    get: function () { return this._derivedPages.slice(); }
  });

  KcPaginationEngine.prototype.getDiagnostic = function () {
    return buildDiagnostic(this.holderEl, this.constraints);
  };

  KcPaginationEngine.prototype.logDiagnostic = function () {
    var d = this.getDiagnostic();
    global.__KC_PAGINATION_DIAGNOSTIC_PRINT && global.__KC_PAGINATION_DIAGNOSTIC_PRINT();
    return d;
  };

  KcPaginationEngine.prototype.getDerivedPageForBlock = function (canonicalIndex) {
    for (var i = 0; i < this._derivedPages.length; i++) {
      var page = this._derivedPages[i];
      for (var j = 0; j < page.blocks.length; j++) {
        if (page.blocks[j].canonicalIndex === canonicalIndex) return page.pageNum;
      }
    }
    return -1;
  };

  KcPaginationEngine.prototype.updateConstraints = function (newConstraints) {
    this.constraints = new PageConstraints(
      Object.assign({}, {
        pageWidth: this.constraints.pageWidth,
        pageHeight: this.constraints.pageHeight,
        marginTop: this.constraints.marginTop,
        marginBottom: this.constraints.marginBottom
      }, newConstraints || {})
    );
    this.invalidate();
  };

  global.KcPaginationEngine = KcPaginationEngine;
  global.KcPageConstraints  = PageConstraints;

})(window);
