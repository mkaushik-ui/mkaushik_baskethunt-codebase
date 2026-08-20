/**
 * Enterprise Knowledge Center Editor.js tools (1.0.7+).
 * Multi-item structured components + layout blocks.
 */
(function (global) {
  'use strict';

  function el(tag, className, attrs) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (attrs) {
      Object.keys(attrs).forEach(function (key) {
        if (key === 'text') node.textContent = attrs[key];
        else if (key === 'html') node.innerHTML = attrs[key];
        else node.setAttribute(key, attrs[key]);
      });
    }
    return node;
  }

  function bindChange(node, cb) {
    node.addEventListener('input', cb);
    node.addEventListener('change', cb);
  }

  function makeItemRow(fields, onRemove, onMove) {
    const row = el('div', 'kc-items-row');
    const body = el('div', 'kc-items-fields');
    fields.forEach(function (field) { body.appendChild(field); });
    const actions = el('div', 'kc-items-actions');
    const up = el('button', 'kc-items-btn', { type: 'button', text: '↑', title: 'Move up' });
    const down = el('button', 'kc-items-btn', { type: 'button', text: '↓', title: 'Move down' });
    const remove = el('button', 'kc-items-btn kc-items-btn-danger', { type: 'button', text: 'Remove', title: 'Remove' });
    up.addEventListener('click', function () { onMove(-1); });
    down.addEventListener('click', function () { onMove(1); });
    remove.addEventListener('click', onRemove);
    actions.appendChild(up);
    actions.appendChild(down);
    actions.appendChild(remove);
    row.appendChild(body);
    row.appendChild(actions);
    return row;
  }

  function fieldInput(label, value, placeholder, multiline) {
    const wrap = el('label', 'kc-items-field');
    wrap.appendChild(el('span', '', { text: label }));
    const input = multiline ? el('textarea', 'kc-items-input') : el('input', 'kc-items-input');
    if (!multiline) input.type = 'text';
    input.value = value || '';
    input.placeholder = placeholder || '';
    if (multiline) input.rows = 2;
    wrap.appendChild(input);
    return { wrap: wrap, input: input };
  }

  function fieldSelect(label, value, options) {
    const wrap = el('label', 'kc-items-field');
    wrap.appendChild(el('span', '', { text: label }));
    const select = el('select', 'kc-items-input');
    options.forEach(function (opt) {
      const o = el('option', '', { value: opt.value || opt, text: opt.label || opt });
      if (String(opt.value || opt) === String(value)) o.selected = true;
      select.appendChild(o);
    });
    wrap.appendChild(select);
    return { wrap: wrap, input: select };
  }

  function fieldCheck(label, checked) {
    const wrap = el('label', 'kc-items-field kc-items-check');
    const input = el('input', '');
    input.type = 'checkbox';
    input.checked = !!checked;
    wrap.appendChild(input);
    wrap.appendChild(document.createTextNode(' ' + label));
    return { wrap: wrap, input: input };
  }

  class ItemListTool {
    constructor({ data, readOnly, config }) {
      this.readOnly = !!readOnly;
      this.config = config || {};
      this.items = Array.isArray(data.items) ? data.items.slice() : [];
      if (!this.items.length && this.config.defaultItems) {
        this.items = this.config.defaultItems.map(function (item) { return Object.assign({}, item); });
      }
      this.nodes = {};
    }

    render() {
      const wrap = el('div', 'kc-tool kc-tool-items ' + (this.config.wrapClass || ''));
      const list = el('div', 'kc-items-list');
      wrap.appendChild(list);
      if (!this.readOnly) {
        const add = el('button', 'kc-items-add', { type: 'button', text: this.config.addLabel || '+ Add item' });
        add.addEventListener('click', () => {
          this.items = this.collect();
          this.items.push(Object.assign({}, this.config.blankItem || {}));
          this.paint();
        });
        wrap.appendChild(add);
      }
      this.nodes = { wrap: wrap, list: list };
      this.paint();
      return wrap;
    }

    paint() {
      const list = this.nodes.list;
      list.innerHTML = '';
      const self = this;
      this.items.forEach(function (item, index) {
        const fields = self.config.renderFields(item, index, function () {
          // no-op; values read on save
        });
        const fieldNodes = fields.map(function (f) { return f.wrap; });
        const row = makeItemRow(
          fieldNodes,
          function () {
            self.items = self.collect();
            self.items.splice(index, 1);
            self.paint();
          },
          function (delta) {
            self.items = self.collect();
            const next = index + delta;
            if (next < 0 || next >= self.items.length) return;
            const tmp = self.items[index];
            self.items[index] = self.items[next];
            self.items[next] = tmp;
            self.paint();
          }
        );
        row._fields = fields;
        row._index = index;
        list.appendChild(row);
      });
    }

    collect() {
      const rows = this.nodes.list.querySelectorAll('.kc-items-row');
      const items = [];
      const self = this;
      rows.forEach(function (row) {
        const fields = row._fields || [];
        items.push(self.config.readFields(fields));
      });
      return items;
    }

    save() {
      return { items: this.collect() };
    }
  }

  // ---- Steps ----
  class KcSteps extends ItemListTool {
    static get toolbox() {
      return { title: 'Steps', icon: '<span style="font-weight:800">1.</span>' };
    }
    static get isReadOnlySupported() { return true; }
    constructor(args) {
      super({
        data: args.data || {},
        readOnly: args.readOnly,
        config: {
          wrapClass: 'kc-tool-steps',
          addLabel: '+ Add step',
          blankItem: { title: '', content: '' },
          defaultItems: [
            { title: 'Step 1', content: '' },
            { title: 'Step 2', content: '' }
          ],
          renderFields: function (item) {
            return [
              fieldInput('Title', item.title, 'Step title'),
              fieldInput('Content', item.content, 'Instructions', true)
            ];
          },
          readFields: function (fields) {
            return { title: fields[0].input.value.trim(), content: fields[1].input.value };
          }
        }
      });
    }
  }

  // ---- Accordion ----
  class KcAccordion extends ItemListTool {
    static get toolbox() {
      return { title: 'Accordion', icon: '<span>▾</span>' };
    }
    static get isReadOnlySupported() { return true; }
    constructor(args) {
      super({
        data: args.data || {},
        readOnly: args.readOnly,
        config: {
          wrapClass: 'kc-tool-accordion',
          addLabel: '+ Add section',
          blankItem: { title: '', content: '', open: false },
          defaultItems: [{ title: 'Section title', content: '', open: true }],
          renderFields: function (item) {
            return [
              fieldInput('Title', item.title, 'Section title'),
              fieldInput('Content', item.content, 'Section content', true),
              fieldCheck('Open by default', item.open)
            ];
          },
          readFields: function (fields) {
            return {
              title: fields[0].input.value.trim(),
              content: fields[1].input.value,
              open: !!fields[2].input.checked
            };
          }
        }
      });
    }
  }

  // ---- FAQ ----
  class KcFaq extends ItemListTool {
    static get toolbox() {
      return { title: 'FAQ', icon: '<span style="font-weight:800">?</span>' };
    }
    static get isReadOnlySupported() { return true; }
    constructor(args) {
      const data = args.data || {};
      if (Array.isArray(data.items)) {
        data.items = data.items.map(function (item) {
          return {
            question: item.question || item.title || '',
            answer: item.answer || item.content || ''
          };
        });
      }
      super({
        data: data,
        readOnly: args.readOnly,
        config: {
          wrapClass: 'kc-tool-faq',
          addLabel: '+ Add question',
          blankItem: { question: '', answer: '' },
          defaultItems: [{ question: 'What is this?', answer: '' }],
          renderFields: function (item) {
            return [
              fieldInput('Question', item.question, 'Question'),
              fieldInput('Answer', item.answer, 'Answer', true)
            ];
          },
          readFields: function (fields) {
            return { question: fields[0].input.value.trim(), answer: fields[1].input.value };
          }
        }
      });
    }
  }

  // ---- Tabs ----
  class KcTabs extends ItemListTool {
    static get toolbox() {
      return { title: 'Tabs', icon: '<span>↹</span>' };
    }
    static get isReadOnlySupported() { return true; }
    constructor(args) {
      super({
        data: args.data || {},
        readOnly: args.readOnly,
        config: {
          wrapClass: 'kc-tool-tabs',
          addLabel: '+ Add tab',
          blankItem: { title: '', content: '' },
          defaultItems: [
            { title: 'Windows', content: '' },
            { title: 'macOS', content: '' },
            { title: 'Linux', content: '' }
          ],
          renderFields: function (item) {
            return [
              fieldInput('Tab title', item.title, 'Windows / PHP / …'),
              fieldInput('Content', item.content, 'Tab content', true)
            ];
          },
          readFields: function (fields) {
            return { title: fields[0].input.value.trim(), content: fields[1].input.value };
          }
        }
      });
    }
  }

  // ---- Code Group ----
  class KcCodeGroup extends ItemListTool {
    static get toolbox() {
      return { title: 'Code Group', icon: '<span>{}</span>' };
    }
    static get isReadOnlySupported() { return true; }
    static get enableLineBreaks() { return true; }
    constructor(args) {
      super({
        data: args.data || {},
        readOnly: args.readOnly,
        config: {
          wrapClass: 'kc-tool-codegroup',
          addLabel: '+ Add language',
          blankItem: { label: '', language: '', code: '', caption: '' },
          defaultItems: [
            { label: 'cURL', language: 'bash', code: '', caption: '' },
            { label: 'PHP', language: 'php', code: '', caption: '' }
          ],
          renderFields: function (item) {
            return [
              fieldInput('Label', item.label, 'PHP'),
              fieldInput('Language', item.language || item.label, 'php'),
              fieldInput('Caption', item.caption, 'Optional caption'),
              fieldInput('Code', item.code, 'Raw code…', true)
            ];
          },
          readFields: function (fields) {
            return {
              label: fields[0].input.value.trim(),
              language: fields[1].input.value.trim(),
              caption: fields[2].input.value.trim(),
              code: fields[3].input.value
            };
          }
        }
      });
    }
  }

  // ---- Definition List ----
  class KcDefinitionList extends ItemListTool {
    static get toolbox() {
      return { title: 'Definition List', icon: '<span>≡</span>' };
    }
    static get isReadOnlySupported() { return true; }
    constructor(args) {
      const data = args.data || {};
      if (Array.isArray(data.items)) {
        data.items = data.items.map(function (item) {
          return {
            term: item.term || item.title || '',
            description: item.description || item.content || ''
          };
        });
      }
      super({
        data: data,
        readOnly: args.readOnly,
        config: {
          wrapClass: 'kc-tool-deflist',
          addLabel: '+ Add term',
          blankItem: { term: '', description: '' },
          defaultItems: [{ term: '', description: '' }],
          renderFields: function (item) {
            return [
              fieldInput('Term', item.term, 'Entity ID'),
              fieldInput('Description', item.description, 'Definition', true)
            ];
          },
          readFields: function (fields) {
            return { term: fields[0].input.value.trim(), description: fields[1].input.value };
          }
        }
      });
    }
  }

  // ---- Status Badge ----
  class KcStatusBadge {
    static get toolbox() {
      return { title: 'Status / Badge', icon: '<span>●</span>' };
    }
    static get isReadOnlySupported() { return true; }
    static STATUSES() {
      return ['stable', 'beta', 'deprecated', 'internal', 'experimental', 'recommended'];
    }
    constructor({ data, readOnly }) {
      this.readOnly = !!readOnly;
      this.data = {
        status: data.status || 'stable',
        label: data.label || ''
      };
    }
    render() {
      const wrap = el('div', 'kc-tool kc-tool-status');
      const status = fieldSelect('Status', this.data.status, KcStatusBadge.STATUSES());
      const label = fieldInput('Label (optional)', this.data.label, 'Stable');
      if (this.readOnly) {
        status.input.disabled = true;
        label.input.disabled = true;
      }
      wrap.appendChild(status.wrap);
      wrap.appendChild(label.wrap);
      this.nodes = { status: status.input, label: label.input };
      return wrap;
    }
    save() {
      return {
        status: this.nodes.status.value,
        label: this.nodes.label.value.trim()
      };
    }
  }

  // ---- Group ----
  class KcGroup {
    static get toolbox() {
      return { title: 'Group', icon: '<span>▢</span>' };
    }
    static get isReadOnlySupported() { return true; }
    constructor({ data, readOnly }) {
      this.readOnly = !!readOnly;
      this.data = { title: data.title || '', content: data.content || '' };
    }
    render() {
      const wrap = el('div', 'kc-tool kc-tool-group');
      const title = fieldInput('Title', this.data.title, 'Optional group title');
      const content = fieldInput('Content', this.data.content, 'Related content', true);
      if (this.readOnly) {
        title.input.disabled = true;
        content.input.disabled = true;
      }
      wrap.appendChild(title.wrap);
      wrap.appendChild(content.wrap);
      this.nodes = { title: title.input, content: content.input };
      return wrap;
    }
    save() {
      return { title: this.nodes.title.value.trim(), content: this.nodes.content.value };
    }
  }

  // ---- Columns ----
  class KcColumns {
    static get toolbox() {
      return { title: 'Columns', icon: '<span>▥</span>' };
    }
    static get isReadOnlySupported() { return true; }
    static LAYOUTS() {
      return [
        { value: '50-50', label: '50 / 50' },
        { value: '33-67', label: '33 / 67' },
        { value: '67-33', label: '67 / 33' },
        { value: '33-33-33', label: '33 / 33 / 33' }
      ];
    }
    constructor({ data, readOnly }) {
      this.readOnly = !!readOnly;
      this.data = {
        layout: data.layout || '50-50',
        columns: Array.isArray(data.columns) ? data.columns.slice() : [{ content: '' }, { content: '' }]
      };
      this.syncColumnCount();
    }
    syncColumnCount() {
      const count = this.data.layout === '33-33-33' ? 3 : 2;
      while (this.data.columns.length < count) this.data.columns.push({ content: '' });
      this.data.columns = this.data.columns.slice(0, count);
    }
    render() {
      const wrap = el('div', 'kc-tool kc-tool-columns');
      const layout = fieldSelect('Layout', this.data.layout, KcColumns.LAYOUTS());
      const cols = el('div', 'kc-columns-editor');
      if (this.readOnly) layout.input.disabled = true;
      bindChange(layout.input, () => {
        this.data.layout = layout.input.value;
        this.syncColumnCount();
        this.paintColumns(cols);
      });
      wrap.appendChild(layout.wrap);
      wrap.appendChild(cols);
      this.nodes = { layout: layout.input, cols: cols };
      this.paintColumns(cols);
      return wrap;
    }
    paintColumns(cols) {
      cols.innerHTML = '';
      const self = this;
      this.data.columns.forEach(function (col, i) {
        const field = fieldInput('Column ' + (i + 1), col.content || '', 'Column content', true);
        if (self.readOnly) field.input.disabled = true;
        field.input.dataset.colIndex = String(i);
        cols.appendChild(field.wrap);
      });
    }
    save() {
      const layout = this.nodes.layout.value;
      const count = layout === '33-33-33' ? 3 : 2;
      const inputs = this.nodes.cols.querySelectorAll('textarea');
      const columns = [];
      for (let i = 0; i < count; i++) {
        columns.push({ content: inputs[i] ? inputs[i].value : '' });
      }
      return { layout: layout, columns: columns };
    }
  }

  // ---- Cards ----
  class KcCards extends ItemListTool {
    static get toolbox() {
      return { title: 'Cards', icon: '<span>▤</span>' };
    }
    static get isReadOnlySupported() { return true; }
    constructor(args) {
      super({
        data: args.data || {},
        readOnly: args.readOnly,
        config: {
          wrapClass: 'kc-tool-cards',
          addLabel: '+ Add card',
          blankItem: { title: '', description: '', icon: '', imageUrl: '', linkUrl: '' },
          defaultItems: [{ title: 'Card title', description: '', icon: '', imageUrl: '', linkUrl: '' }],
          renderFields: function (item) {
            return [
              fieldInput('Title', item.title, 'Card title'),
              fieldInput('Description', item.description, 'Description', true),
              fieldInput('Icon', item.icon, 'Optional emoji/icon'),
              fieldInput('Image URL', item.imageUrl, 'https://…'),
              fieldInput('Link URL', item.linkUrl, 'https://…')
            ];
          },
          readFields: function (fields) {
            return {
              title: fields[0].input.value.trim(),
              description: fields[1].input.value,
              icon: fields[2].input.value.trim(),
              imageUrl: fields[3].input.value.trim(),
              linkUrl: fields[4].input.value.trim()
            };
          }
        }
      });
    }
  }

  // ---- API Endpoint ----
  class KcApiEndpoint {
    static get toolbox() {
      return { title: 'API Endpoint', icon: '<span>⚡</span>' };
    }
    static get isReadOnlySupported() { return true; }
    static METHODS() { return ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD']; }
    constructor({ data, readOnly }) {
      this.readOnly = !!readOnly;
      this.data = {
        method: data.method || 'GET',
        endpoint: data.endpoint || data.path || '/api/v1/resource',
        title: data.title || '',
        description: data.description || '',
        auth: data.auth || 'Bearer JWT',
        parameters: Array.isArray(data.parameters) ? data.parameters.slice() : [],
        requestBody: data.requestBody || data.request || '',
        responseBody: data.responseBody || data.response || ''
      };
      this.nodes = {};
    }
    render() {
      const wrap = el('div', 'kc-tool kc-tool-api');
      const headerRow = el('div', 'kc-api-editor-head');
      const methodField = fieldSelect('Method', this.data.method, KcApiEndpoint.METHODS().map(m => ({ value: m, label: m })));
      const endpointField = fieldInput('Endpoint Path', this.data.endpoint, '/api/v1/…');
      const authField = fieldInput('Auth Required', this.data.auth, 'Bearer Token');
      headerRow.appendChild(methodField.wrap);
      headerRow.appendChild(endpointField.wrap);
      headerRow.appendChild(authField.wrap);

      const titleField = fieldInput('Title / Summary', this.data.title, 'e.g. Get User Profile');
      const descField = fieldInput('Description', this.data.description, 'Detailed explanation of endpoint', true);

      // Parameters container
      const paramsWrap = el('div', 'kc-api-params-wrap');
      paramsWrap.appendChild(el('div', 'kc-field-title', { text: 'Parameters' }));
      const paramsList = el('div', 'kc-api-params-list');
      paramsWrap.appendChild(paramsList);

      const addParamBtn = el('button', 'kc-items-add', { type: 'button', text: '+ Add parameter' });
      addParamBtn.addEventListener('click', () => {
        this.data.parameters.push({ name: '', type: 'string', required: true, description: '' });
        this.paintParams(paramsList);
      });
      if (!this.readOnly) paramsWrap.appendChild(addParamBtn);

      const reqField = fieldInput('Request Payload (JSON)', this.data.requestBody, '{\n  "name": "example"\n}', true);
      const resField = fieldInput('Response Payload (JSON)', this.data.responseBody, '{\n  "ok": true\n}', true);

      wrap.appendChild(headerRow);
      wrap.appendChild(titleField.wrap);
      wrap.appendChild(descField.wrap);
      wrap.appendChild(paramsWrap);
      wrap.appendChild(reqField.wrap);
      wrap.appendChild(resField.wrap);

      this.nodes = {
        method: methodField.input,
        endpoint: endpointField.input,
        auth: authField.input,
        title: titleField.input,
        description: descField.input,
        paramsList: paramsList,
        requestBody: reqField.input,
        responseBody: resField.input
      };

      this.paintParams(paramsList);
      return wrap;
    }
    paintParams(container) {
      container.innerHTML = '';
      const self = this;
      this.data.parameters.forEach(function (param, i) {
        const row = el('div', 'kc-items-row');
        const f1 = fieldInput('Name', param.name, 'param_name');
        const f2 = fieldInput('Type', param.type || 'string', 'string / int / bool');
        const f3 = fieldCheck('Required', param.required);
        const f4 = fieldInput('Description', param.description, 'Parameter description');
        const body = el('div', 'kc-items-fields');
        body.appendChild(f1.wrap);
        body.appendChild(f2.wrap);
        body.appendChild(f3.wrap);
        body.appendChild(f4.wrap);

        const actions = el('div', 'kc-items-actions');
        const remove = el('button', 'kc-items-btn kc-items-btn-danger', { type: 'button', text: '✕', title: 'Remove' });
        remove.addEventListener('click', function () {
          self.data.parameters.splice(i, 1);
          self.paintParams(container);
        });
        actions.appendChild(remove);

        row.appendChild(body);
        row.appendChild(actions);
        row._pfields = [f1.input, f2.input, f3.input, f4.input];
        container.appendChild(row);
      });
    }
    save() {
      const rows = this.nodes.paramsList.querySelectorAll('.kc-items-row');
      const params = [];
      rows.forEach(function (row) {
        if (row._pfields) {
          params.push({
            name: row._pfields[0].value.trim(),
            type: row._pfields[1].value.trim() || 'string',
            required: !!row._pfields[2].checked,
            description: row._pfields[3].value.trim()
          });
        }
      });
      return {
        method: this.nodes.method.value,
        endpoint: this.nodes.endpoint.value.trim(),
        auth: this.nodes.auth.value.trim(),
        title: this.nodes.title.value.trim(),
        description: this.nodes.description.value.trim(),
        parameters: params,
        requestBody: this.nodes.requestBody.value,
        responseBody: this.nodes.responseBody.value
      };
    }
  }

  // ---- Key / Value Reference ----
  class KcKeyValues extends ItemListTool {
    static get toolbox() {
      return { title: 'Key / Value', icon: '<span>☷</span>' };
    }
    static get isReadOnlySupported() { return true; }
    constructor(args) {
      super({
        data: args.data || {},
        readOnly: args.readOnly,
        config: {
          wrapClass: 'kc-tool-keyvalues',
          addLabel: '+ Add key-value pair',
          blankItem: { key: '', value: '' },
          defaultItems: [
            { key: 'Owner', value: 'Engineering' },
            { key: 'Environment', value: 'Production' }
          ],
          renderFields: function (item) {
            return [
              fieldInput('Key / Property', item.key, 'e.g. Version'),
              fieldInput('Value', item.value, 'e.g. 1.1.0')
            ];
          },
          readFields: function (fields) {
            return { key: fields[0].input.value.trim(), value: fields[1].input.value.trim() };
          }
        }
      });
      this.titleVal = (args.data && args.data.title) || '';
    }
    save() {
      return {
        title: this.titleVal,
        items: this.collect()
      };
    }
  }

  // ---- Keyboard Keys ----
  class KcKbd {
    static get toolbox() {
      return { title: 'Keyboard Key', icon: '<span>⌨</span>' };
    }
    static get isReadOnlySupported() { return true; }
    constructor({ data, readOnly }) {
      this.readOnly = !!readOnly;
      const keys = Array.isArray(data.keys) ? data.keys.join(' + ') : (data.text || 'Ctrl + Shift + P');
      this.data = { keys: keys, description: data.description || '' };
    }
    render() {
      const wrap = el('div', 'kc-tool kc-tool-kbd');
      const keysField = fieldInput('Shortcut Keys (e.g. Ctrl + Shift + P)', this.data.keys, 'Ctrl + Shift + P');
      const descField = fieldInput('Description (optional)', this.data.description, 'e.g. Open Command Palette');
      wrap.appendChild(keysField.wrap);
      wrap.appendChild(descField.wrap);
      this.nodes = { keys: keysField.input, description: descField.input };
      return wrap;
    }
    save() {
      const raw = this.nodes.keys.value;
      const parts = raw.split(/[\+,]/).map(s => s.trim()).filter(Boolean);
      return {
        keys: parts,
        description: this.nodes.description.value.trim()
      };
    }
  }

  // ---- Reusable Block Reference ----
  class KcReusable {
    static get toolbox() {
      return { title: 'Reusable Block', icon: '<span>♻</span>' };
    }
    static get isReadOnlySupported() { return true; }
    constructor({ data, readOnly }) {
      this.readOnly = !!readOnly;
      this.data = {
        reusable_id: parseInt(data.reusable_id || data.id, 10) || 0,
        title: data.title || ''
      };
    }
    render() {
      const wrap = el('div', 'kc-tool kc-tool-reusable');
      const head = el('div', 'kc-reusable-box');
      head.innerHTML = '<span class="kc-reusable-pill">REUSABLE SYNCED COMPONENT</span><div class="kc-reusable-desc">This block references a shared reusable component. Changes to the reusable source will update all documents using it.</div>';
      const idField = fieldInput('Reusable Component ID #', String(this.data.reusable_id || ''), 'e.g. 1');
      const titleField = fieldInput('Label / Title', this.data.title, 'Optional label');
      wrap.appendChild(head);
      wrap.appendChild(idField.wrap);
      wrap.appendChild(titleField.wrap);
      this.nodes = { id: idField.input, title: titleField.input };
      return wrap;
    }
    save() {
      return {
        reusable_id: parseInt(this.nodes.id.value, 10) || 0,
        title: this.nodes.title.value.trim()
      };
    }
  }

  const registry = global.KcEditorRegistry;
  const tools = {
    steps: KcSteps,
    accordion: KcAccordion,
    faq: KcFaq,
    tabs: KcTabs,
    codeGroup: KcCodeGroup,
    definitionList: KcDefinitionList,
    statusBadge: KcStatusBadge,
    group: KcGroup,
    columns: KcColumns,
    cards: KcCards,
    apiEndpoint: KcApiEndpoint,
    keyValues: KcKeyValues,
    kbd: KcKbd,
    reusable: KcReusable
  };

  Object.keys(tools).forEach(function (key) {
    if (registry) registry.register(key, tools[key], { label: key, group: 'enterprise' });
    global['Kc' + key.charAt(0).toUpperCase() + key.slice(1)] = tools[key];
  });

  // Friendly globals
  global.KcSteps = KcSteps;
  global.KcAccordion = KcAccordion;
  global.KcFaq = KcFaq;
  global.KcTabs = KcTabs;
  global.KcCodeGroup = KcCodeGroup;
  global.KcDefinitionList = KcDefinitionList;
  global.KcStatusBadge = KcStatusBadge;
  global.KcGroup = KcGroup;
  global.KcColumns = KcColumns;
  global.KcCards = KcCards;
  global.KcApiEndpoint = KcApiEndpoint;
  global.KcKeyValues = KcKeyValues;
  global.KcKbd = KcKbd;
  global.KcReusable = KcReusable;
})(window);

