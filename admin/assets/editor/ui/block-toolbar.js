/**
 * Task A2-T19: Contextual Block Toolbar.
 *
 * Displays a floating quick-action toolbar when an Editor.js block
 * is selected.
 *
 * Actions:
 * - Move Up
 * - Move Down
 * - Duplicate
 * - Delete
 * - Transform
 *
 * Duplicate explicitly calls:
 * window.KcWorkspace.duplicateBlock()
 */
(function (global) {
    'use strict';

    class BlockToolbar {
        constructor() {
            this.toolbar = null;
            this.selectedBlock = null;
            this.selectedIndex = -1;
            this.editor = null;
            this.bound = false;
            this.lastFocusedElement = null;
        }

        init() {
            if (this.bound) {
                return;
            }

            this.editor = this.resolveEditor();

            if (!this.editor) {
                /*
                 * The editor may not be ready yet.
                 * Retry after the main editor runtime has initialized.
                 */
                window.setTimeout(() => {
                    this.init();
                }, 250);

                return;
            }

            this.createToolbar();
            this.bindSelectionEvents();
            this.bindEditorEvents();

            this.bound = true;
        }

        resolveEditor() {
            if (
                global.KcEditorRuntime &&
                global.KcEditorRuntime.instance
            ) {
                return global.KcEditorRuntime.instance;
            }

            return null;
        }

        createToolbar() {
            if (document.getElementById('kc-block-toolbar')) {
                this.toolbar =
                    document.getElementById('kc-block-toolbar');

                return;
            }

            this.toolbar = document.createElement('div');

            this.toolbar.id = 'kc-block-toolbar';
            this.toolbar.className = 'kc-block-toolbar';
            this.toolbar.hidden = true;

            this.toolbar.setAttribute('role', 'toolbar');
            this.toolbar.setAttribute(
                'aria-label',
                'Block actions'
            );

            /*
             * Self-contained styling.
             *
             * Uses existing KC design tokens where available and
             * falls back to sensible inherited values without
             * requiring changes to kc-editor.css.
             */
            this.toolbar.style.position = 'fixed';
            this.toolbar.style.zIndex = '9999';
            this.toolbar.style.display = 'flex';
            this.toolbar.style.alignItems = 'center';
            this.toolbar.style.gap = '4px';
            this.toolbar.style.padding = '5px';
            this.toolbar.style.background =
                'var(--soi-surface, #ffffff)';
            this.toolbar.style.border =
                '1px solid var(--soi-border, #d7dce2)';
            this.toolbar.style.borderRadius =
                'var(--soi-radius, 8px)';
            this.toolbar.style.boxShadow =
                '0 6px 20px rgba(0, 0, 0, 0.12)';
            this.toolbar.style.fontFamily =
                'Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';

            const actions = [
                {
                    action: 'move-up',
                    label: 'Move Up',
                    icon: '↑'
                },
                {
                    action: 'move-down',
                    label: 'Move Down',
                    icon: '↓'
                },
                {
                    action: 'duplicate',
                    label: 'Duplicate',
                    icon: '⧉'
                },
                {
                    action: 'delete',
                    label: 'Delete',
                    icon: '🗑'
                },
                {
                    action: 'transform',
                    label: 'Transform',
                    icon: '⇄'
                }
            ];

            actions.forEach((item) => {
                const button = document.createElement('button');

                button.type = 'button';
                button.className = 'kc-btn-mini kc-block-toolbar-btn';
                button.dataset.action = item.action;
                button.setAttribute(
                    'aria-label',
                    item.label
                );
                button.title = item.label;

                button.textContent = item.icon;

                button.style.fontFamily =
                    'inherit';
                button.style.cursor = 'pointer';
                button.style.minWidth = '30px';
                button.style.minHeight = '30px';
                button.style.padding = '4px 7px';
                button.style.border =
                    '1px solid var(--soi-border, #d7dce2)';
                button.style.borderRadius =
                    'var(--soi-radius, 6px)';
                button.style.background =
                    'var(--soi-surface, #ffffff)';
                button.style.color =
                    'inherit';

                this.toolbar.appendChild(button);
            });

            document.body.appendChild(this.toolbar);

            this.toolbar.addEventListener(
                'click',
                (event) => {
                    const button =
                        event.target.closest(
                            '[data-action]'
                        );

                    if (!button) {
                        return;
                    }

                    event.preventDefault();
                    event.stopPropagation();

                    this.handleAction(
                        button.dataset.action
                    );
                }
            );

            /*
             * Keep keyboard interaction accessible.
             */
            this.toolbar.addEventListener(
                'keydown',
                (event) => {
                    if (event.key === 'Escape') {
                        this.hide();
                    }
                }
            );
        }

        bindSelectionEvents() {
            const runtime =
                global.KcEditorRuntime;

            if (
                runtime &&
                typeof runtime.on === 'function'
            ) {
                runtime.on(
                    'selectionChange',
                    (block) => {
                        this.setSelectedBlock(block);
                    }
                );
            }
        }

        bindEditorEvents() {
            /*
             * Editor.js does not expose a single universal block
             * selection event across every version, so listen for
             * clicks inside the editor holder as a reliable fallback.
             */
            const holder =
                document.getElementById('kc-editorjs');

            if (!holder) {
                return;
            }

            holder.addEventListener(
                'click',
                (event) => {
                    const blockElement =
                        event.target.closest(
                            '[data-cy="editorjs-block"], .ce-block'
                        );

                    if (!blockElement) {
                        return;
                    }

                    this.detectBlockFromElement(
                        blockElement
                    );
                }
            );

            document.addEventListener(
                'click',
                (event) => {
                    if (!this.toolbar) {
                        return;
                    }

                    if (
                        this.toolbar.contains(event.target)
                    ) {
                        return;
                    }

                    const blockElement =
                        event.target.closest(
                            '.ce-block'
                        );

                    if (!blockElement) {
                        this.hide();
                    }
                }
            );

            window.addEventListener(
                'resize',
                () => {
                    if (
                        !this.toolbar.hidden &&
                        this.selectedBlock
                    ) {
                        this.positionToolbar();
                    }
                }
            );

            window.addEventListener(
                'scroll',
                () => {
                    if (
                        !this.toolbar.hidden &&
                        this.selectedBlock
                    ) {
                        this.positionToolbar();
                    }
                },
                true
            );
        }

        detectBlockFromElement(blockElement) {
            const editor =
                this.resolveEditor();

            if (!editor || !editor.blocks) {
                return;
            }

            const holders =
                document.querySelectorAll(
                    '#kc-editorjs .ce-block'
                );

            let index = -1;

            holders.forEach((holder, i) => {
                if (holder === blockElement) {
                    index = i;
                }
            });

            if (index < 0) {
                return;
            }

            let block = null;

            try {
                block =
                    editor.blocks.getBlockByIndex(
                        index
                    );
            } catch (error) {
                return;
            }

            if (!block) {
                return;
            }

            this.setSelectedBlock(block, index);
        }

        setSelectedBlock(block, explicitIndex) {
            if (!block) {
                this.hide();
                return;
            }

            this.editor =
                this.resolveEditor();

            if (!this.editor) {
                return;
            }

            let index =
                typeof explicitIndex === 'number'
                    ? explicitIndex
                    : this.findBlockIndex(block);

            if (index < 0) {
                return;
            }

            this.selectedBlock = block;
            this.selectedIndex = index;

            this.lastFocusedElement =
                document.activeElement;

            this.updateButtonState();
            this.show();
            this.positionToolbar();
        }

        findBlockIndex(block) {
            if (
                !this.editor ||
                !this.editor.blocks ||
                typeof this.editor.blocks.getBlocksCount !==
                'function'
            ) {
                return -1;
            }

            const count =
                this.editor.blocks.getBlocksCount();

            for (
                let index = 0;
                index < count;
                index++
            ) {
                const current =
                    this.editor.blocks.getBlockByIndex(
                        index
                    );

                if (
                    current === block ||
                    (
                        current &&
                        block &&
                        current.id &&
                        block.id &&
                        current.id === block.id
                    )
                ) {
                    return index;
                }
            }

            return -1;
        }

        updateButtonState() {
            if (!this.toolbar) {
                return;
            }

            const count =
                this.editor &&
                    this.editor.blocks &&
                    typeof this.editor.blocks.getBlocksCount ===
                    'function'
                    ? this.editor.blocks.getBlocksCount()
                    : 0;

            const moveUp =
                this.toolbar.querySelector(
                    '[data-action="move-up"]'
                );

            const moveDown =
                this.toolbar.querySelector(
                    '[data-action="move-down"]'
                );

            if (moveUp) {
                moveUp.disabled =
                    this.selectedIndex <= 0;
                moveUp.setAttribute(
                    'aria-disabled',
                    moveUp.disabled
                        ? 'true'
                        : 'false'
                );
            }

            if (moveDown) {
                moveDown.disabled =
                    this.selectedIndex >= count - 1;
                moveDown.setAttribute(
                    'aria-disabled',
                    moveDown.disabled
                        ? 'true'
                        : 'false'
                );
            }
        }

        show() {
            if (!this.toolbar) {
                return;
            }

            this.toolbar.hidden = false;
            this.toolbar.style.display = 'flex';
        }

        hide() {
            if (!this.toolbar) {
                return;
            }

            this.toolbar.hidden = true;
            this.toolbar.style.display = 'none';

            this.selectedBlock = null;
            this.selectedIndex = -1;
        }

        positionToolbar() {
            if (
                !this.toolbar ||
                this.toolbar.hidden ||
                !this.selectedBlock
            ) {
                return;
            }

            const holder =
                this.getSelectedHolder();

            if (!holder) {
                this.hide();
                return;
            }

            const rect =
                holder.getBoundingClientRect();

            const toolbarRect =
                this.toolbar.getBoundingClientRect();

            let top =
                rect.top - toolbarRect.height - 8;

            let left =
                rect.left + (rect.width / 2) -
                (toolbarRect.width / 2);

            /*
             * Keep toolbar inside viewport.
             */
            if (top < 8) {
                top = rect.bottom + 8;
            }

            if (
                left + toolbarRect.width >
                window.innerWidth - 8
            ) {
                left =
                    window.innerWidth -
                    toolbarRect.width -
                    8;
            }

            if (left < 8) {
                left = 8;
            }

            this.toolbar.style.top =
                Math.round(top) + 'px';

            this.toolbar.style.left =
                Math.round(left) + 'px';
        }

        getSelectedHolder() {
            if (!this.editor || !this.editor.blocks) {
                return null;
            }

            try {
                const block =
                    this.editor.blocks.getBlockByIndex(
                        this.selectedIndex
                    );

                if (block && block.holder) {
                    return block.holder;
                }
            } catch (error) {
                // Fall through to DOM lookup.
            }

            const holders =
                document.querySelectorAll(
                    '#kc-editorjs .ce-block'
                );

            return holders[this.selectedIndex] || null;
        }

        async handleAction(action) {
            if (
                !this.selectedBlock ||
                this.selectedIndex < 0
            ) {
                return;
            }

            switch (action) {
                case 'move-up':
                    await this.moveBlock(-1);
                    break;

                case 'move-down':
                    await this.moveBlock(1);
                    break;

                case 'duplicate':
                    await this.duplicateBlock();
                    break;

                case 'delete':
                    await this.deleteBlock();
                    break;

                case 'transform':
                    this.transformBlock();
                    break;

                default:
                    break;
            }
        }

        async moveBlock(direction) {
            const workspace =
                global.KcWorkspace;

            if (
                workspace &&
                typeof workspace.moveBlock ===
                'function'
            ) {
                await workspace.moveBlock(
                    this.selectedIndex,
                    this.selectedIndex + direction
                );

                return;
            }

            /*
             * Fallback to Editor.js block API where available.
             */
            if (
                this.editor &&
                this.editor.blocks &&
                typeof this.editor.blocks.move ===
                'function'
            ) {
                try {
                    await this.editor.blocks.move(
                        this.selectedIndex + direction
                    );
                } catch (error) {
                    console.warn(
                        '[BlockToolbar] Move failed:',
                        error
                    );
                }
            }
        }

        async duplicateBlock() {
            /*
             * T19 acceptance criterion:
             *
             * Clicking Duplicate must call:
             * window.KcWorkspace.duplicateBlock()
             */
            const workspace =
                global.KcWorkspace;

            if (
                workspace &&
                typeof workspace.duplicateBlock ===
                'function'
            ) {
                try {
                    await workspace.duplicateBlock(
                        this.selectedIndex
                    );

                    return;
                } catch (error) {
                    console.error(
                        '[BlockToolbar] Duplicate failed:',
                        error
                    );

                    return;
                }
            }

            console.warn(
                '[BlockToolbar] window.KcWorkspace.duplicateBlock() is unavailable.'
            );
        }

        async deleteBlock() {
            if (
                !this.editor ||
                !this.editor.blocks ||
                typeof this.editor.blocks.delete !==
                'function'
            ) {
                return;
            }

            try {
                await this.editor.blocks.delete(
                    this.selectedIndex
                );

                this.hide();
            } catch (error) {
                console.error(
                    '[BlockToolbar] Delete failed:',
                    error
                );
            }
        }

        transformBlock() {
            /*
             * Transform is intentionally delegated to the
             * existing workspace transform subsystem when present.
             */
            const workspace =
                global.KcWorkspace;

            if (
                workspace &&
                typeof workspace.transformBlock ===
                'function'
            ) {
                workspace.transformBlock(
                    this.selectedIndex
                );

                return;
            }

            console.warn(
                '[BlockToolbar] Transform action is unavailable.'
            );
        }
    }

    global.KcBlockToolbar = BlockToolbar;

    function initialize() {
        if (global.kcBlockToolbar) {
            return;
        }

        global.kcBlockToolbar =
            new BlockToolbar();

        global.kcBlockToolbar.init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            initialize
        );
    } else {
        initialize();
    }
})(typeof window !== 'undefined' ? window : this);