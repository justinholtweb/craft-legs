/**
 * Legs — the table picker.
 *
 * `LegsPicker.open(function(table, overrides) { … })` puts a modal on screen listing the library
 * and hands back the chosen table, plus any presentation overrides for this embed only. Both the
 * CKEditor plugin and the Redactor plugin call this, so the choosing experience is identical
 * wherever an author embeds a table.
 *
 * Clicking a table inserts it straight away — the common case should not cost a second click.
 * The gear opens the overrides pane for the case where this one appearance differs.
 */
(function() {
    'use strict';

    var cache = null;

    function fetchTables() {
        if (cache) {
            return Promise.resolve(cache);
        }

        return Craft.sendActionRequest('GET', 'legs/embeds/tables').then(function(response) {
            cache = response.data;
            return cache;
        });
    }

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function(key) {
            if (key === 'text') {
                node.textContent = attrs[key];
            } else if (key === 'class') {
                node.className = attrs[key];
            } else if (attrs[key] !== null && attrs[key] !== false) {
                node.setAttribute(key, attrs[key]);
            }
        });
        (children || []).forEach(function(child) {
            if (child) {
                node.appendChild(child);
            }
        });
        return node;
    }

    /** `{compact: true, perPage: 10}` → `compact,perPage=10`, matching RenderOptions::parseEmbedOptions(). */
    function toEmbedOptions(overrides) {
        return Object.keys(overrides).map(function(name) {
            var value = overrides[name];
            if (value === true) {
                return name;
            }
            if (value === false) {
                return '!' + name;
            }
            return name + '=' + value;
        }).join(',');
    }

    function optionsPane(data, table, onInsert) {
        var overrides = {};
        var pane = el('div', { class: 'legs-picker-options' });

        pane.appendChild(el('p', { class: 'legs-picker-meta', text: data.labels.inherit }));

        data.options.forEach(function(option) {
            if (option.pro && !data.isPro) {
                return;
            }

            var row = el('label', { class: 'legs-picker-option' });

            if (option.type === 'number') {
                var number = el('input', { type: 'number', min: '1', placeholder: '25' });
                number.addEventListener('input', function() {
                    if (number.value === '') {
                        delete overrides[option.name];
                    } else {
                        overrides[option.name] = parseInt(number.value, 10);
                    }
                });
                row.append(el('span', { text: option.label }), number);
            } else {
                // Three states, because "leave it alone" is the default and is not the same as
                // "off": an unchecked box would otherwise silently override the table.
                var select = el('select');
                [['', '—'], ['1', 'On'], ['0', 'Off']].forEach(function(pair) {
                    var opt = el('option', { value: pair[0], text: pair[1] });
                    select.appendChild(opt);
                });
                select.addEventListener('change', function() {
                    if (select.value === '') {
                        delete overrides[option.name];
                    } else {
                        overrides[option.name] = select.value === '1';
                    }
                });
                row.append(el('span', { text: option.label }), select);
            }

            pane.appendChild(row);
        });

        var insert = el('button', { type: 'button', class: 'btn submit', text: data.labels.insert });
        insert.addEventListener('click', function() {
            onInsert(table, overrides);
        });

        pane.appendChild(el('div', { class: 'legs-picker-actions' }, [insert]));

        return pane;
    }

    function open(callback) {
        var container = el('div', { class: 'modal legs-picker' });
        container.innerHTML = '<div class="body"><h2 class="legs-picker-heading">'
            + Craft.t('legs', 'Insert a table')
            + '</h2><div class="legs-picker-list spinner"></div></div>'
            + '<div class="footer"><div class="buttons right"><button type="button" class="btn" data-legs-cancel>'
            + Craft.t('app', 'Cancel') + '</button></div></div>';

        var modal = new Garnish.Modal($(container), { resizable: false });
        var list = container.querySelector('.legs-picker-list');
        var heading = container.querySelector('.legs-picker-heading');

        container.querySelector('[data-legs-cancel]').addEventListener('click', function() {
            modal.hide();
        });

        function insert(table, overrides) {
            modal.hide();
            callback(table, overrides || {});
        }

        fetchTables().then(function(data) {
            list.classList.remove('spinner');

            if (!data.tables.length) {
                list.innerHTML = '<p>' + Craft.t('legs', 'No tables yet.') + ' <a class="go" href="'
                    + data.newTableUrl + '">' + Craft.t('legs', 'Create one') + '</a></p>';
                return;
            }

            list.innerHTML = '';

            data.tables.forEach(function(table) {
                var choose = el('button', { type: 'button', class: 'legs-picker-item' }, [
                    el('span', { class: 'legs-picker-title', text: table.title }),
                    el('span', { class: 'legs-picker-meta', text: table.handle + ' · ' + table.size }),
                ]);
                choose.addEventListener('click', function() {
                    insert(table);
                });

                var gear = el('button', {
                    type: 'button',
                    class: 'legs-picker-gear',
                    title: data.labels.options,
                    'aria-label': data.labels.options,
                    text: '⚙',
                });
                gear.addEventListener('click', function() {
                    heading.textContent = table.title;
                    list.innerHTML = '';
                    list.appendChild(optionsPane(data, table, insert));
                });

                list.appendChild(el('div', { class: 'legs-picker-row' }, [choose, gear]));
            });
        }).catch(function() {
            list.classList.remove('spinner');
            list.textContent = Craft.t('legs', 'Couldn’t load tables.');
        });
    }

    /** The markup an embed becomes in a rich-text field, in both editors. */
    function embedHtml(table, overrides) {
        var list = toEmbedOptions(overrides || {});
        var tag = list ? '{legs:' + table.handle + ':render(' + list + ')}' : '{legs:' + table.handle + ':render}';

        return '<div class="legs-embed" data-legs-handle="' + table.handle + '"'
            + (list ? ' data-legs-options="' + list + '"' : '')
            + '>' + tag + '</div>';
    }

    /** Called when the library changes, so a picker opened later does not show a stale list. */
    function clearCache() {
        cache = null;
    }

    window.LegsPicker = {
        open: open,
        embedHtml: embedHtml,
        toEmbedOptions: toEmbedOptions,
        clearCache: clearCache,
    };
})();
