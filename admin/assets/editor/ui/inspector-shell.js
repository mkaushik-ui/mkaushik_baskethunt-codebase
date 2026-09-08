/**
 * Task T2: Inspector Shell Component.
 * Synchronizes with active block selection to render document or block-specific settings.
 */
(function (global) {
  'use strict';

  class InspectorShell {
    constructor(hostEl) {
      this.host = typeof hostEl === 'string' ? document.getElementById(hostEl) : hostEl;
      this.currentMode = 'document'; // 'document' or 'block'
      this.selectedBlock = null;
    }

    init() {
      if (!this.host) return;
      if (global.KcEditorRuntime) {
        global.KcEditorRuntime.on('selectionChange', (block) => {
          this.setBlock(block);
        });
      }
      this.bindSpaceControls();
    }

    setBlock(block) {
      this.selectedBlock = block;
      this.currentMode = block ? 'block' : 'document';
      this.render();
    }

    render() {
      if (!this.host) return;
      if (this.currentMode === 'document') {
        const metaHint = document.getElementById('kc-selected-meta');
        if (metaHint) metaHint.textContent = 'Select a block on the canvas to configure properties.';
        const blockFields = document.getElementById('kc-block-fields');
        if (blockFields) blockFields.innerHTML = '';
        return;
      }

      const block = this.selectedBlock;
      const metaHint = document.getElementById('kc-selected-meta');
      if (metaHint) {
        metaHint.textContent = `Active Block: ${block.name || 'Block'} (Index #${block.index + 1})`;
      }

      const blockFields = document.getElementById('kc-block-fields');
      if (!blockFields) return;

      const runtime = global.KcEditorRuntime;
      if (!runtime || !runtime.instance) return;

      try {
        const blockObj = runtime.instance.blocks.getBlockByIndex(block.index);
        if (!blockObj) return;

        // Fetch block data asynchronously or inspect holder
        runtime.instance.save().then(docData => {
          const blockData = (docData.blocks && docData.blocks[block.index]) ? docData.blocks[block.index].data : {};
          this.renderBlockControls(blockFields, block.name, blockData, block.index);
        }).catch(err => {
          this.renderBlockControls(blockFields, block.name, {}, block.index);
        });
      } catch (err) {
        console.error('[InspectorShell] Error fetching block data:', err);
      }
    }

    renderBlockControls(container, blockName, data, index) {
      container.innerHTML = '';
      const name = (blockName || '').toLowerCase();

      const group = document.createElement('div');
      group.className = 'kc-inspector-fields-group';

      if (name === 'callout') {
        group.innerHTML = `
          <div class="kc-field">
            <label for="kc-insp-tone">Callout Tone</label>
            <select id="kc-insp-tone" class="kc-tool-select">
              <option value="info" ${data.tone === 'info' ? 'selected' : ''}>Information (Blue)</option>
              <option value="warning" ${data.tone === 'warning' ? 'selected' : ''}>Warning (Amber)</option>
              <option value="success" ${data.tone === 'success' ? 'selected' : ''}>Success (Green)</option>
              <option value="danger" ${data.tone === 'danger' ? 'selected' : ''}>Critical (Red)</option>
            </select>
          </div>
          <div class="kc-field">
            <label for="kc-insp-title">Title Header</label>
            <input type="text" id="kc-insp-title" class="kc-input" value="${this.escapeHtml(data.title || '')}" placeholder="Optional callout heading..." />
          </div>
        `;
      } else if (name === 'header') {
        group.innerHTML = `
          <div class="kc-field">
            <label for="kc-insp-level">Heading Level</label>
            <select id="kc-insp-level" class="kc-tool-select">
              <option value="2" ${data.level === 2 ? 'selected' : ''}>Heading 2 (H2)</option>
              <option value="3" ${data.level === 3 ? 'selected' : ''}>Heading 3 (H3)</option>
              <option value="4" ${data.level === 4 ? 'selected' : ''}>Heading 4 (H4)</option>
              <option value="5" ${data.level === 5 ? 'selected' : ''}>Heading 5 (H5)</option>
            </select>
          </div>
        `;
      } else if (name === 'code') {
        group.innerHTML = `
          <div class="kc-field">
            <label for="kc-insp-lang">Language</label>
            <select id="kc-insp-lang" class="kc-tool-select">
              <option value="javascript" ${data.language === 'javascript' ? 'selected' : ''}>JavaScript</option>
              <option value="php" ${data.language === 'php' ? 'selected' : ''}>PHP</option>
              <option value="json" ${data.language === 'json' ? 'selected' : ''}>JSON</option>
              <option value="html" ${data.language === 'html' ? 'selected' : ''}>HTML</option>
              <option value="css" ${data.language === 'css' ? 'selected' : ''}>CSS</option>
              <option value="bash" ${data.language === 'bash' ? 'selected' : ''}>Bash / Shell</option>
              <option value="sql" ${data.language === 'sql' ? 'selected' : ''}>SQL</option>
            </select>
          </div>
        `;
      } else if (name === 'apiendpoint') {
        group.innerHTML = `
          <div class="kc-field">
            <label for="kc-insp-method">HTTP Method</label>
            <select id="kc-insp-method" class="kc-tool-select">
              <option value="GET" ${data.method === 'GET' ? 'selected' : ''}>GET</option>
              <option value="POST" ${data.method === 'POST' ? 'selected' : ''}>POST</option>
              <option value="PUT" ${data.method === 'PUT' ? 'selected' : ''}>PUT</option>
              <option value="PATCH" ${data.method === 'PATCH' ? 'selected' : ''}>PATCH</option>
              <option value="DELETE" ${data.method === 'DELETE' ? 'selected' : ''}>DELETE</option>
            </select>
          </div>
          <div class="kc-field">
            <label for="kc-insp-endpoint">Endpoint Route</label>
            <input type="text" id="kc-insp-endpoint" class="kc-input" value="${this.escapeHtml(data.endpoint || '')}" placeholder="/api/v1/..." />
          </div>
        `;
      } else {
        group.innerHTML = `
          <p class="kc-empty-hint">Standard visual properties active for <strong>${this.escapeHtml(blockName || 'Block')}</strong>.</p>
        `;
      }

      container.appendChild(group);

      // Bind input events to update EditorJS block
      container.querySelectorAll('input, select, textarea').forEach(input => {
        input.addEventListener('change', () => {
          this.applyBlockUpdate(index, blockName, data);
        });
      });
    }

    applyBlockUpdate(index, blockName, data) {
      const toneSelect = this.host.querySelector('#kc-insp-tone');
      const titleInput = this.host.querySelector('#kc-insp-title');
      const levelSelect = this.host.querySelector('#kc-insp-level');
      const langSelect = this.host.querySelector('#kc-insp-lang');
      const methodSelect = this.host.querySelector('#kc-insp-method');
      const endpointInput = this.host.querySelector('#kc-insp-endpoint');

      const updated = Object.assign({}, data);
      if (toneSelect) updated.tone = toneSelect.value;
      if (titleInput) updated.title = titleInput.value;
      if (levelSelect) updated.level = parseInt(levelSelect.value, 10);
      if (langSelect) updated.language = langSelect.value;
      if (methodSelect) updated.method = methodSelect.value;
      if (endpointInput) updated.endpoint = endpointInput.value;

      const runtime = global.KcEditorRuntime;
      if (runtime && runtime.instance && typeof runtime.instance.blocks?.update === 'function') {
        try {
          const blockObj = runtime.instance.blocks.getBlockByIndex(index);
          if (blockObj) {
            runtime.instance.blocks.update(blockObj.id, updated);
          }
        } catch (err) {
          console.error('[InspectorShell] Block update error:', err);
        }
      }
    }

    bindSpaceControls() {
      const spaceSelect = document.getElementById('kc-doc-space');
      const sectionSelect = document.getElementById('kc-doc-section');
      const versionWrap = document.getElementById('kc-version-field-wrap');
      const versionInput = document.getElementById('kc-doc-version');
      const slugInput = document.getElementById('kc-doc-slug');
      const slugPreview = document.getElementById('kc-slug-preview-link');
      const headSlugPath = document.getElementById('kc-head-slug-path');
      const spaceBadge = document.getElementById('kc-space-type-badge');

      if (!spaceSelect) return;

      const cfg = global.kcEditorConfig || (function () {
        const el = document.getElementById('kc-editor-config');
        if (el) {
          try { return JSON.parse(el.textContent); } catch (e) {}
        }
        return {};
      })();

      const apiUrl = cfg.apiUrl || 'editor-api.php';
      const csrf = cfg.csrf || '';

      const updatePermalink = () => {
        const selectedOpt = spaceSelect.selectedIndex >= 0 ? spaceSelect.options[spaceSelect.selectedIndex] : null;
        const spaceType = selectedOpt ? (selectedOpt.getAttribute('data-type') || '') : '';
        const spaceSlug = selectedOpt ? (selectedOpt.getAttribute('data-slug') || '') : '';
        const docSlug = (slugInput ? slugInput.value.trim() : '') || 'untitled';

        let permalink = '';
        if (spaceSlug) {
          if (spaceType === 'tech') {
            permalink = 'kc.soi.co.in/tech/' + spaceSlug + '/' + docSlug;
          } else {
            permalink = 'kc.soi.co.in/' + spaceType + '/' + spaceSlug + '/' + docSlug;
          }
        } else {
          permalink = '/docs/' + docSlug;
        }

        if (slugPreview) {
          slugPreview.textContent = permalink;
        }
        if (headSlugPath) {
          headSlugPath.textContent = permalink;
        }

        const liveUrl = (permalink.indexOf('http') === 0 || permalink.indexOf('kc.soi.co.in') === 0)
          ? 'https://' + permalink.replace(/^https?:\/\//, '')
          : permalink;
        const liveBtn = document.getElementById('kc-live-link');
        const cardLiveBtn = document.getElementById('kc-card-live-link');
        if (liveBtn) liveBtn.href = liveUrl;
        if (cardLiveBtn) cardLiveBtn.href = liveUrl;
      };

      const updateVersionWrap = () => {
        const selectedOpt = spaceSelect.selectedIndex >= 0 ? spaceSelect.options[spaceSelect.selectedIndex] : null;
        const spaceType = selectedOpt ? (selectedOpt.getAttribute('data-type') || '') : '';
        const isTech = spaceType.toLowerCase() === 'tech';

        if (versionWrap) {
          versionWrap.style.display = isTech ? '' : 'none';
          versionWrap.hidden = !isTech;
        }
        if (spaceBadge) {
          if (spaceType) {
            spaceBadge.textContent = spaceType.toUpperCase();
            spaceBadge.style.display = 'inline-block';
          } else {
            spaceBadge.style.display = 'none';
          }
        }
      };

      const fetchAndPopulateSections = async (spaceId, targetSectionId = 0) => {
        if (!sectionSelect) return;
        const sid = Number(spaceId);
        if (!sid || sid <= 0) {
          sectionSelect.innerHTML = '<option value="0">-- Root Document (No Section) --</option>';
          sectionSelect.value = '0';
          return;
        }

        sectionSelect.disabled = true;
        try {
          const fetchUrl = apiUrl + '?action=sections_list&space_id=' + encodeURIComponent(sid);
          const res = await fetch(fetchUrl, {
            headers: {
              'X-CSRF-TOKEN': csrf,
              'Accept': 'application/json'
            }
          });
          const json = await res.json();
          sectionSelect.disabled = false;

          let html = '<option value="0">-- Root Document (No Section) --</option>';
          if (json.ok && Array.isArray(json.sections)) {
            json.sections.forEach(sec => {
              const secId = Number(sec.id);
              const parentId = Number(sec.parent_id || 0);
              const indent = parentId > 0 ? '—— ' : '';
              const selected = secId === Number(targetSectionId) ? ' selected' : '';
              html += `<option value="${secId}" data-slug="${this.escapeHtml(sec.slug || '')}" data-parent="${parentId}"${selected}>${indent}${this.escapeHtml(sec.title || 'Untitled Section')}</option>`;
            });
          }
          sectionSelect.innerHTML = html;
          if (targetSectionId && Number(targetSectionId) > 0) {
            sectionSelect.value = String(targetSectionId);
          }
        } catch (err) {
          console.error('[InspectorShell] Failed to load sections:', err);
          sectionSelect.disabled = false;
          sectionSelect.innerHTML = '<option value="0">-- Root Document (No Section) --</option>';
        }
      };

      const ensureSpacesLoaded = async () => {
        if (spaceSelect.options.length > 1) return;
        try {
          const fetchUrl = apiUrl + '?action=spaces_list';
          const res = await fetch(fetchUrl, {
            headers: {
              'X-CSRF-TOKEN': csrf,
              'Accept': 'application/json'
            }
          });
          const json = await res.json();
          if (json.ok && Array.isArray(json.spaces) && json.spaces.length > 0) {
            const currentVal = spaceSelect.getAttribute('data-selected-space') || spaceSelect.value || '0';
            let html = '<option value="0" data-type="" data-slug="">-- No Space (Global / Standalone) --</option>';
            json.spaces.forEach(sp => {
              const sId = String(sp.id);
              const selected = sId === String(currentVal) ? ' selected' : '';
              html += `<option value="${sId}" data-type="${this.escapeHtml(sp.type || 'generaldocs')}" data-slug="${this.escapeHtml(sp.slug || '')}"${selected}>${this.escapeHtml(sp.title || '')} (${this.escapeHtml(sp.type || '')})</option>`;
            });
            spaceSelect.innerHTML = html;
            spaceSelect.value = currentVal;
            updateVersionWrap();
            updatePermalink();
          }
        } catch (e) {
          console.error('[InspectorShell] Failed to load spaces:', e);
        }
      };

      spaceSelect.addEventListener('change', () => {
        const spaceId = parseInt(spaceSelect.value, 10) || 0;
        updateVersionWrap();
        updatePermalink();
        fetchAndPopulateSections(spaceId, 0);
      });

      if (slugInput) {
        slugInput.addEventListener('input', () => {
          updatePermalink();
        });
      }

      ensureSpacesLoaded().then(() => {
        const initialSpaceId = parseInt(spaceSelect.value, 10) || 0;
        const initialSectionId = sectionSelect ? (parseInt(sectionSelect.getAttribute('data-selected-section'), 10) || parseInt(sectionSelect.value, 10) || 0) : 0;
        updateVersionWrap();
        updatePermalink();
        if (initialSpaceId > 0 && sectionSelect && sectionSelect.options.length <= 1) {
          fetchAndPopulateSections(initialSpaceId, initialSectionId);
        }
      });
    }

    escapeHtml(str) {
      return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
  }

  global.KcInspectorShell = InspectorShell;
})(typeof window !== 'undefined' ? window : this);

