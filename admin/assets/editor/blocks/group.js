(function (global) {
    'use strict';
    const GroupBlockEditor = {
        type: 'group',
        label: 'Group Container',
        category: 'layout',
        icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>',
        capabilities: { nestable: true, reusable: true, wide: true },
        defaultData() {
            return { backgroundColor: 'transparent', borderStyle: 'none', padding: 'medium', blocks: [] };
        },
        renderCanvas(block, actions) {
            const data = block.data || this.defaultData();
            const container = document.createElement('div');
            const classes = ['kc-block-group'];
            if (data.borderStyle && data.borderStyle !== 'none') classes.push(`kc-group-border-${data.borderStyle}`);
            if (data.padding && data.padding !== 'none') classes.push(`kc-group-padding-${data.padding}`);
            container.className = classes.join(' ');
            container.setAttribute('data-block-id', block.id);
            container.setAttribute('role', 'region');
            container.setAttribute('aria-label', 'Group Container Block');
            if (data.backgroundColor && data.backgroundColor !== 'transparent') container.style.backgroundColor = data.backgroundColor;
            const innerDropZone = document.createElement('div');
            innerDropZone.className = 'kc-block-dropzone';
            innerDropZone.setAttribute('data-nest-target', block.id);
            container.appendChild(innerDropZone);
            return container;
        },
        renderInspector(block, actions) {
            const inspector = document.createElement('div');
            inspector.className = 'kc-inspector-panel';
            return inspector;
        }
    };
    if (global.KC && typeof global.KC.registerBlock === 'function') {
        global.KC.registerBlock('group', GroupBlockEditor);
    } else {
        global.GroupBlockEditor = GroupBlockEditor;
    }
})(typeof window !== 'undefined' ? window : this);