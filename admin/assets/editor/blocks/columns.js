(function (global) {
    'use strict';
    const ColumnsBlockEditor = {
        type: 'columns',
        label: 'Columns Layout',
        category: 'layout',
        icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="8" height="18" rx="1"/><rect x="13" y="3" width="8" height="18" rx="1"/></svg>',
        capabilities: { nestable: true, reusable: true, wide: true },
        defaultData() {
            return { layout: '50-50', columns: [{ blocks: [] }, { blocks: [] }] };
        },
        renderCanvas(block, actions) {
            const data = block.data || this.defaultData();
            const container = document.createElement('div');
            container.className = `kc-columns kc-cols-${data.layout || '50-50'}`;
            container.setAttribute('data-block-id', block.id);
            container.setAttribute('role', 'region');
            container.setAttribute('aria-label', 'Columns Container Block');
            return container;
        },
        renderInspector(block, actions) {
            const inspector = document.createElement('div');
            inspector.className = 'kc-inspector-panel';
            return inspector;
        }
    };
    if (global.KC && typeof global.KC.registerBlock === 'function') {
        global.KC.registerBlock('columns', ColumnsBlockEditor);
    } else {
        global.ColumnsBlockEditor = ColumnsBlockEditor;
    }
})(typeof window !== 'undefined' ? window : this);