(function (global) {
    'use strict';

    const CardsBlockEditor = {
        type: 'cards',
        label: 'Cards Grid',
        category: 'layout',

        icon:
            '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
            '<rect x="3" y="3" width="8" height="8" rx="1"/>' +
            '<rect x="13" y="3" width="8" height="8" rx="1"/>' +
            '<rect x="3" y="13" width="8" height="8" rx="1"/>' +
            '<rect x="13" y="13" width="8" height="8" rx="1"/>' +
            '</svg>',

        capabilities: {
            nestable: false,
            reusable: true,
            wide: true
        },

        defaultData() {
            return {
                columns: 3,
                cards: [
                    {
                        title: 'Card title',
                        description: 'Card description',
                        image: '',
                        icon: '',
                        link: ''
                    },
                    {
                        title: 'Card title',
                        description: 'Card description',
                        image: '',
                        icon: '',
                        link: ''
                    },
                    {
                        title: 'Card title',
                        description: 'Card description',
                        image: '',
                        icon: '',
                        link: ''
                    }
                ]
            };
        },

        normalizeData(block) {
            const defaults = this.defaultData();
            const source = block && block.data ? block.data : {};

            const columns = Math.max(
                1,
                Math.min(4, parseInt(source.columns, 10) || defaults.columns)
            );

            const cards = Array.isArray(source.cards)
                ? source.cards
                : defaults.cards;

            return {
                columns: columns,
                cards: cards.map(function (card) {
                    card = card && typeof card === 'object' ? card : {};

                    return {
                        title: String(card.title || ''),
                        description: String(card.description || ''),
                        image: String(card.image || ''),
                        icon: String(card.icon || ''),
                        link: String(card.link || '')
                    };
                })
            };
        },

        sanitizeUrl(url) {
            const value = String(url || '').trim();

            if (/^\s*javascript\s*:/i.test(value)) {
                return '';
            }

            return value;
        },

        escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        renderCanvas(block, actions) {
            const data = this.normalizeData(block);

            const container = document.createElement('div');

            container.className =
                'kc-cards-grid kc-cards-cols-' + data.columns;

            container.setAttribute('data-block-id', block.id);
            container.setAttribute('role', 'list');
            container.setAttribute('aria-label', 'Cards Grid');

            data.cards.forEach(function (card) {
                const article = document.createElement('article');

                article.className = 'kc-card';
                article.setAttribute('role', 'listitem');

                if (card.image) {
                    const media = document.createElement('div');
                    media.className = 'kc-card-media';

                    const image = document.createElement('img');
                    image.src = card.image;
                    image.alt = '';
                    image.loading = 'lazy';

                    media.appendChild(image);
                    article.appendChild(media);
                }

                const body = document.createElement('div');
                body.className = 'kc-card-body';

                if (card.icon) {
                    const icon = document.createElement('div');
                    icon.className = 'kc-card-icon';
                    icon.setAttribute('aria-hidden', 'true');
                    icon.textContent = card.icon;

                    body.appendChild(icon);
                }

                if (card.title) {
                    const heading = document.createElement('h3');
                    heading.className = 'kc-card-title';

                    if (card.link) {
                        const link = document.createElement('a');
                        link.href = this.sanitizeUrl(card.link);
                        link.textContent = card.title;

                        heading.appendChild(link);
                    } else {
                        heading.textContent = card.title;
                    }

                    body.appendChild(heading);
                }

                if (card.description) {
                    const description = document.createElement('p');
                    description.className = 'kc-card-description';
                    description.textContent = card.description;

                    body.appendChild(description);
                }

                if (card.link && !card.title) {
                    const link = document.createElement('a');

                    link.className = 'kc-card-link';
                    link.href = this.sanitizeUrl(card.link);
                    link.textContent = 'Learn more';

                    link.setAttribute(
                        'aria-label',
                        'Open card link'
                    );

                    body.appendChild(link);
                }

                article.appendChild(body);
                container.appendChild(article);
            }, this);

            return container;
        },

        renderInspector(block, actions) {
            const self = this;
            const data = this.normalizeData(block);

            const inspector = document.createElement('div');
            inspector.className = 'kc-inspector-panel';

            /*
             * Grid settings
             */
            const gridSection = document.createElement('section');
            gridSection.className = 'kc-inspector-section';

            const gridHeading = document.createElement('h3');
            gridHeading.textContent = 'Cards Grid';

            gridSection.appendChild(gridHeading);

            const columnsField = document.createElement('div');
            columnsField.className = 'kc-field';

            const columnsLabel = document.createElement('label');
            columnsLabel.textContent = 'Columns';
            columnsLabel.setAttribute('for', 'kc-cards-columns-' + block.id);

            const columnsSelect = document.createElement('select');
            columnsSelect.className = 'kc-tool-select';
            columnsSelect.id = 'kc-cards-columns-' + block.id;
            columnsSelect.setAttribute('aria-label', 'Cards grid columns');

            [1, 2, 3, 4].forEach(function (count) {
                const option = document.createElement('option');

                option.value = String(count);
                option.textContent = String(count);

                if (count === data.columns) {
                    option.selected = true;
                }

                columnsSelect.appendChild(option);
            });

            columnsSelect.addEventListener('change', function () {
                data.columns = Math.max(
                    1,
                    Math.min(4, parseInt(columnsSelect.value, 10) || 3)
                );

                self.updateBlock(block, data, actions);
            });

            columnsField.appendChild(columnsLabel);
            columnsField.appendChild(columnsSelect);
            gridSection.appendChild(columnsField);

            inspector.appendChild(gridSection);

            /*
             * Cards
             */
            const cardsSection = document.createElement('section');
            cardsSection.className = 'kc-inspector-section';

            const cardsHeading = document.createElement('h3');
            cardsHeading.textContent = 'Cards';

            cardsSection.appendChild(cardsHeading);

            const cardsWrap = document.createElement('div');

            data.cards.forEach(function (card, index) {
                const row = self.createCardEditor(
                    card,
                    index,
                    data,
                    block,
                    actions,
                    function () {
                        self.updateBlock(block, data, actions);
                    }
                );

                cardsWrap.appendChild(row);
            });

            cardsSection.appendChild(cardsWrap);

            const addButton = document.createElement('button');
            addButton.type = 'button';
            addButton.className = 'kc-items-add';
            addButton.textContent = '+ Add card';
            addButton.setAttribute('aria-label', 'Add card');

            addButton.addEventListener('click', function () {
                data.cards.push({
                    title: 'Card title',
                    description: '',
                    image: '',
                    icon: '',
                    link: ''
                });

                self.updateBlock(block, data, actions);

                /*
                 * Re-render the inspector so the newly-created card gets
                 * its own real controls.
                 */
                if (actions && typeof actions.renderInspector === 'function') {
                    actions.renderInspector();
                }
            });

            cardsSection.appendChild(addButton);
            inspector.appendChild(cardsSection);

            return inspector;
        },

        createCardEditor(
            card,
            index,
            data,
            block,
            actions,
            onChange
        ) {
            const self = this;

            const row = document.createElement('div');
            row.className = 'kc-items-row';

            const fields = document.createElement('div');
            fields.className = 'kc-items-fields';

            function addField(
                labelText,
                property,
                type,
                placeholder
            ) {
                const field = document.createElement('label');
                field.className = 'kc-items-field';

                const label = document.createElement('span');
                label.textContent = labelText;

                const input = document.createElement(type === 'textarea'
                    ? 'textarea'
                    : 'input');

                input.className = 'kc-items-input';
                input.placeholder = placeholder || '';
                input.value = card[property] || '';
                input.setAttribute(
                    'aria-label',
                    'Card ' + (index + 1) + ' ' + labelText
                );

                if (type === 'textarea') {
                    input.rows = 3;
                } else {
                    input.type = type;
                }

                input.addEventListener('input', function () {
                    card[property] = input.value;
                    onChange();
                });

                field.appendChild(label);
                field.appendChild(input);

                fields.appendChild(field);
            }

            addField(
                'Title',
                'title',
                'text',
                'Card title'
            );

            addField(
                'Description',
                'description',
                'textarea',
                'Card description'
            );

            addField(
                'Image URL',
                'image',
                'url',
                'https://example.com/image.jpg'
            );

            addField(
                'Icon',
                'icon',
                'text',
                'Icon or symbol'
            );

            addField(
                'Link URL',
                'link',
                'url',
                'https://example.com'
            );

            const actionsWrap = document.createElement('div');
            actionsWrap.className = 'kc-items-actions';

            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className =
                'kc-items-btn kc-items-btn-danger';

            removeButton.textContent = '×';
            removeButton.setAttribute(
                'aria-label',
                'Remove card ' + (index + 1)
            );

            removeButton.addEventListener('click', function () {
                data.cards.splice(index, 1);
                onChange();

                if (
                    actions &&
                    typeof actions.renderInspector === 'function'
                ) {
                    actions.renderInspector();
                }
            });

            actionsWrap.appendChild(removeButton);

            row.appendChild(fields);
            row.appendChild(actionsWrap);

            return row;
        },

        updateBlock(block, data, actions) {
            block.data = {
                columns: data.columns,
                cards: data.cards.map(function (card) {
                    return {
                        title: String(card.title || ''),
                        description: String(card.description || ''),
                        image: String(card.image || ''),
                        icon: String(card.icon || ''),
                        link: this.sanitizeUrl(card.link || '')
                    };
                }, this)
            };

            /*
             * Use the editor's existing update mechanism when available.
             * No fake UI state is maintained here.
             */
            if (actions && typeof actions.updateBlock === 'function') {
                actions.updateBlock(block);
            } else if (actions && typeof actions.onChange === 'function') {
                actions.onChange(block);
            }
        }
    };

    if (
        global.KC &&
        typeof global.KC.registerBlock === 'function'
    ) {
        global.KC.registerBlock('cards', CardsBlockEditor);
    } else {
        global.CardsBlockEditor = CardsBlockEditor;
    }
})(typeof window !== 'undefined' ? window : this);