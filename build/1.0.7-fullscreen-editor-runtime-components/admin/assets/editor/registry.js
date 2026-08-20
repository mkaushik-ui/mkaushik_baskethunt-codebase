/**
 * KC structured editor — client block registry.
 * Custom tools register here so the editor core stays extension-friendly.
 */
(function (global) {
  'use strict';

  const tools = Object.create(null);
  const meta = Object.create(null);

  function register(type, toolClass, info) {
    if (!type || !toolClass) return;
    tools[type] = toolClass;
    meta[type] = Object.assign({ type: type, label: type, group: 'blocks' }, info || {});
  }

  function getTools() {
    return tools;
  }

  function catalog() {
    return Object.keys(meta).map(function (key) { return meta[key]; });
  }

  global.KcEditorRegistry = {
    register: register,
    getTools: getTools,
    catalog: catalog
  };
})(window);
