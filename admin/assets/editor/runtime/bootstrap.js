/**
 * SOI Knowledge Center — Authoritative Editor Runtime Bootstrap.
 * Manages Editor.js 2.30.8 lifecycle, tool registration, selection events,
 * and canonical document serialization.
 */
(function (global) {
  'use strict';

  // Prevent multiple bootstrap definitions
  if (global.KcEditorRuntime) {
    return;
  }

  const LIFECYCLE_STATES = {
    IDLE: 'idle',
    LOADING: 'loading',
    READY: 'ready',
    FAILED: 'failed'
  };

  class EditorRuntime {
    constructor() {
      this.state = LIFECYCLE_STATES.IDLE;
      this.instance = null;
      this.config = null;
      this.listeners = {
        ready: [],
        failed: [],
        stateChange: [],
        change: [],
        selectionChange: []
      };
      this.selectedBlock = null;
      this.dirty = false;
    }

    /**
     * Get current lifecycle state.
     * @returns {'idle'|'loading'|'ready'|'failed'}
     */
    getState() {
      return this.state;
    }

    /**
     * Subscribe to lifecycle and runtime events.
     * @param {'ready'|'failed'|'stateChange'|'change'|'selectionChange'} event
     * @param {Function} callback
     */
    on(event, callback) {
      if (this.listeners[event]) {
        this.listeners[event].push(callback);
      }
    }

    /**
     * Unsubscribe from events.
     * @param {'ready'|'failed'|'stateChange'|'change'|'selectionChange'} event
     * @param {Function} callback
     */
    off(event, callback) {
      if (this.listeners[event]) {
        this.listeners[event] = this.listeners[event].filter(cb => cb !== callback);
      }
    }

    /**
     * Emit internal runtime event.
     * @param {string} event
     * @param {*} data
     */
    emit(event, data) {
      if (this.listeners[event]) {
        this.listeners[event].forEach(cb => {
          try {
            cb(data);
          } catch (err) {
            console.error('[KcEditorRuntime] Listener error:', err);
          }
        });
      }
    }

    /**
     * Transition runtime lifecycle state.
     * @param {string} newState
     * @param {*} extra
     */
    setState(newState, extra = null) {
      this.state = newState;
      this.emit('stateChange', { state: newState, extra });
      if (newState === LIFECYCLE_STATES.READY) {
        this.emit('ready', this.instance);
      } else if (newState === LIFECYCLE_STATES.FAILED) {
        this.emit('failed', extra);
      }
    }

    /**
     * Authoritative bootstrap method.
     * @param {Object} options Configuration object
     * @returns {Promise<Object>} Resolves with the Editor.js instance
     */
    async init(options = {}) {
      if (this.instance) {
        console.warn('[KcEditorRuntime] Destroying existing instance before re-initialization.');
        await this.destroy();
      }

      this.config = options;
      this.setState(LIFECYCLE_STATES.LOADING);

      const holder = options.holder || 'kc-editorjs';
      const holderEl = typeof holder === 'string' ? document.getElementById(holder) : holder;

      if (!holderEl) {
        const err = new Error(`Holder element #${holder} not found in DOM.`);
        this.setState(LIFECYCLE_STATES.FAILED, err);
        throw err;
      }

      if (typeof global.EditorJS !== 'function') {
        const err = new Error('EditorJS core library is not loaded on page.');
        this.setState(LIFECYCLE_STATES.FAILED, err);
        throw err;
      }

      // Collect tools from global registry or options
      let tools = options.tools || {};
      if (global.KcEditorRegistry && typeof global.KcEditorRegistry.getTools === 'function') {
        tools = Object.assign({}, global.KcEditorRegistry.getTools(), tools);
      }

      try {
        const initialData = options.data || { time: Date.now(), blocks: [], version: '2.30.8' };

        this.instance = new global.EditorJS({
          holder: holderEl,
          tools: tools,
          data: initialData,
          placeholder: options.placeholder || 'Type text, press "/" for components, or click "+" ...',
          autofocus: options.autofocus ?? true,
          readOnly: options.readOnly ?? false,
          minHeight: options.minHeight ?? 300,
          onChange: (api, event) => {
            this.dirty = true;
            this.emit('change', { api, event });
          },
          onReady: () => {
            this.setState(LIFECYCLE_STATES.READY);
            this.bindSelectionTracker(holderEl);
          }
        });

        await this.instance.isReady;
        return this.instance;
      } catch (err) {
        console.error('[KcEditorRuntime] Initialization failed:', err);
        this.setState(LIFECYCLE_STATES.FAILED, err);
        throw err;
      }
    }

    /**
     * Track caret/block selection changes inside the canvas.
     * @param {HTMLElement} holderEl
     */
    bindSelectionTracker(holderEl) {
      holderEl.addEventListener('click', (e) => {
        this.updateActiveBlock();
      });

      holderEl.addEventListener('keyup', (e) => {
        if (['ArrowUp', 'ArrowDown', 'Enter', 'Backspace'].includes(e.key)) {
          this.updateActiveBlock();
        }
      });
    }

    /**
     * Update active block metadata and emit selectionChange event.
     */
    updateActiveBlock() {
      if (!this.instance || typeof this.instance.blocks?.getCurrentBlockIndex !== 'function') {
        return;
      }
      try {
        const index = this.instance.blocks.getCurrentBlockIndex();
        const block = this.instance.blocks.getBlockByIndex(index);
        if (block) {
          this.selectedBlock = {
            index,
            id: block.id,
            name: block.name,
            holder: block.holder
          };
          this.emit('selectionChange', this.selectedBlock);
        }
      } catch (err) {
        // Safe ignore during rapid typing
      }
    }

    /**
     * Save and serialize document data.
     * @returns {Promise<Object>} Canonical structured document
     */
    async save() {
      if (!this.instance) {
        throw new Error('Editor instance is not initialized.');
      }
      const raw = await this.instance.save();
      this.dirty = false;
      return raw;
    }

    /**
     * Destroy current instance and release DOM references.
     */
    async destroy() {
      if (this.instance && typeof this.instance.destroy === 'function') {
        try {
          await this.instance.destroy();
        } catch (err) {
          console.error('[KcEditorRuntime] Error destroying instance:', err);
        }
      }
      this.instance = null;
      this.selectedBlock = null;
      this.setState(LIFECYCLE_STATES.IDLE);
    }
  }

  // Export singleton runtime instance
  global.KcEditorRuntime = new EditorRuntime();
  global.KcEditorRuntime.LIFECYCLE_STATES = LIFECYCLE_STATES;

})(typeof window !== 'undefined' ? window : this);
