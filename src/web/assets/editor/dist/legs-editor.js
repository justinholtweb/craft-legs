/**
 * Legs — the grid editor.
 *
 * One editor for both places a grid is edited: the table screen in the CP and an inline Legs
 * field on an entry. Initialised from a hidden input holding the same JSON document the PHP
 * side reads and writes, so nothing about the format is duplicated in a second shape here.
 *
 * Cells are `contenteditable` rather than inputs, which buys three things at once: rich text in
 * a cell without a second editor, native paste of formatted content, and — the important one —
 * a paste event that can see a whole spreadsheet on the clipboard and expand it into the grid
 * instead of dropping it into one cell.
 */
(function() {
    'use strict';

    var UNDO_LIMIT = 60;

    function h(tag, attrs, children) {
        var el = document.createElement(tag);
        attrs = attrs || {};
        Object.keys(attrs).forEach(function(key) {
            if (key === 'class') {
                el.className = attrs[key];
            } else if (key === 'text') {
                el.textContent = attrs[key];
            } else if (key === 'html') {
                el.innerHTML = attrs[key];
            } else if (attrs[key] !== null && attrs[key] !== false) {
                el.setAttribute(key, attrs[key]);
            }
        });
        (children || []).forEach(function(child) {
            if (child) {
                el.appendChild(child);
            }
        });
        return el;
    }

    function columnName(index) {
        var name = '';
        index += 1;
        while (index > 0) {
            var remainder = (index - 1) % 26;
            name = String.fromCharCode(65 + remainder) + name;
            index = Math.floor((index - 1) / 26);
        }
        return name;
    }

    /** Parses a spreadsheet paste: tab-delimited, with quoted cells for embedded tabs/newlines. */
    function parseDelimited(text, delimiter) {
        var rows = [];
        var row = [];
        var cell = '';
        var quoted = false;

        for (var i = 0; i < text.length; i++) {
            var char = text[i];

            if (quoted) {
                if (char === '"') {
                    if (text[i + 1] === '"') {
                        cell += '"';
                        i++;
                    } else {
                        quoted = false;
                    }
                } else {
                    cell += char;
                }
                continue;
            }

            if (char === '"' && cell === '') {
                quoted = true;
            } else if (char === delimiter) {
                row.push(cell);
                cell = '';
            } else if (char === '\n') {
                row.push(cell);
                rows.push(row);
                row = [];
                cell = '';
            } else if (char !== '\r') {
                cell += char;
            }
        }

        if (cell !== '' || row.length) {
            row.push(cell);
            rows.push(row);
        }

        return rows;
    }

    function sniffDelimiter(text) {
        var sample = text.split('\n').slice(0, 10);
        var best = '\t';
        var bestScore = -1;

        ['\t', ',', ';', '|'].forEach(function(delimiter) {
            var counts = sample.map(function(line) {
                return line.split(delimiter).length - 1;
            });
            var max = Math.max.apply(null, counts);
            if (max === 0) {
                return;
            }
            var consistent = counts.every(function(count) { return count === counts[0]; });
            var score = max * (consistent ? 10 : 1);
            if (score > bestScore) {
                bestScore = score;
                best = delimiter;
            }
        });

        return best;
    }

    function Editor(root) {
        this.root = root;
        this.input = document.getElementById(root.getAttribute('data-legs-input'));
        this.isPro = root.getAttribute('data-legs-pro') === '1';

        if (!this.input) {
            return;
        }

        this.state = this.read();
        this.undoStack = [];
        this.redoStack = [];
        this.selectedRow = null;
        this.selectedCol = null;
        this.focus = { row: 0, col: 0 };

        this.build();
        this.render();
    }

    Editor.prototype.read = function() {
        var parsed;
        try {
            parsed = JSON.parse(this.input.value || '{}');
        } catch (error) {
            parsed = {};
        }

        return this.normalize(parsed);
    };

    /**
     * Squares the grid up, exactly as `TableData::normalize()` does on the PHP side. Both ends
     * enforce it because both ends are entry points: the editor must never *show* a ragged grid,
     * and the server must never *store* one, whatever posted it.
     */
    Editor.prototype.normalize = function(state) {
        state = state || {};
        var cells = Array.isArray(state.cells) ? state.cells : [];

        var colCount = cells.reduce(function(max, row) {
            return Math.max(max, Array.isArray(row) ? row.length : 0);
        }, 0) || 1;

        if (!cells.length) {
            cells = [['']];
        }

        cells = cells.map(function(row) {
            row = Array.isArray(row) ? row.slice() : [];
            while (row.length < colCount) {
                row.push('');
            }
            return row.slice(0, colCount).map(function(cell) {
                return typeof cell === 'string' ? cell : String(cell == null ? '' : cell);
            });
        });

        var columns = Array.isArray(state.columns) ? state.columns.slice(0, colCount) : [];
        while (columns.length < colCount) {
            columns.push({});
        }

        var rows = Array.isArray(state.rows) ? state.rows.slice(0, cells.length) : [];
        while (rows.length < cells.length) {
            rows.push({});
        }

        var headerRows = Math.min(Math.max(0, parseInt(state.headerRows, 10) || 0), cells.length);
        var footerRows = Math.min(Math.max(0, parseInt(state.footerRows, 10) || 0), cells.length - headerRows);

        var merges = (Array.isArray(state.merges) ? state.merges : []).filter(function(merge) {
            return merge
                && (merge.rowspan > 1 || merge.colspan > 1)
                && merge.row + merge.rowspan <= cells.length
                && merge.col + merge.colspan <= colCount;
        });

        return {
            cells: cells,
            columns: columns,
            rows: rows,
            headerRows: headerRows,
            footerRows: footerRows,
            merges: merges,
        };
    };

    Editor.prototype.write = function() {
        this.input.value = JSON.stringify(this.state);
        this.input.dispatchEvent(new Event('change', { bubbles: true }));
    };

    Editor.prototype.snapshot = function() {
        this.undoStack.push(JSON.stringify(this.state));
        if (this.undoStack.length > UNDO_LIMIT) {
            this.undoStack.shift();
        }
        this.redoStack.length = 0;
    };

    Editor.prototype.undo = function() {
        if (!this.undoStack.length) {
            return;
        }
        this.redoStack.push(JSON.stringify(this.state));
        this.state = this.normalize(JSON.parse(this.undoStack.pop()));
        this.render();
    };

    Editor.prototype.redo = function() {
        if (!this.redoStack.length) {
            return;
        }
        this.undoStack.push(JSON.stringify(this.state));
        this.state = this.normalize(JSON.parse(this.redoStack.pop()));
        this.render();
    };

    Editor.prototype.build = function() {
        var self = this;

        this.toolbar = h('div', { class: 'legs-editor-toolbar' });
        this.scroll = h('div', { class: 'legs-editor-scroll' });
        this.status = h('span', { class: 'legs-editor-status' });
        this.footer = h('div', { class: 'legs-editor-footer' }, [
            h('span', { class: 'legs-editor-hint', html: 'Tab / Enter move · paste a spreadsheet into any cell to fill the grid' }),
            h('span', { class: 'legs-spacer', style: 'flex:1 1 auto' }),
            this.status,
        ]);

        [
            ['Row above', function() { self.insertRow(self.focus.row, 'above'); }],
            ['Row below', function() { self.insertRow(self.focus.row, 'below'); }],
            ['Column left', function() { self.insertColumn(self.focus.col, 'left'); }],
            ['Column right', function() { self.insertColumn(self.focus.col, 'right'); }],
            ['Delete row', function() { self.deleteRow(self.selectedRow === null ? self.focus.row : self.selectedRow); }],
            ['Delete column', function() { self.deleteColumn(self.selectedCol === null ? self.focus.col : self.selectedCol); }],
            ['Header row', function() { self.toggleHeader(); }],
            ['Footer row', function() { self.toggleFooter(); }],
            ['Transpose', function() { self.transpose(); }],
            ['Paste data…', function() { self.openPaste(); }],
            ['Undo', function() { self.undo(); }],
            ['Redo', function() { self.redo(); }],
        ].forEach(function(entry) {
            var button = h('button', { type: 'button', class: 'btn small', text: entry[0] });
            button.addEventListener('click', function(event) {
                event.preventDefault();
                entry[1]();
            });
            self.toolbar.appendChild(button);
        });

        this.root.append(this.toolbar, this.scroll, this.footer);
    };

    Editor.prototype.render = function() {
        var self = this;
        var state = this.state;
        var colCount = state.cells[0].length;

        var table = h('table', { class: 'legs-editor-grid' });
        var thead = h('thead');
        var headRow = h('tr');
        headRow.appendChild(h('th', { class: 'legs-corner', text: '' }));

        for (var c = 0; c < colCount; c++) {
            (function(index) {
                var th = h('th', { class: 'legs-colhead' + (self.selectedCol === index ? ' is-selected' : ''), text: columnName(index) });
                th.addEventListener('click', function() {
                    self.selectedCol = self.selectedCol === index ? null : index;
                    self.selectedRow = null;
                    self.render();
                });
                headRow.appendChild(th);
            })(c);
        }

        thead.appendChild(headRow);
        table.appendChild(thead);

        var tbody = h('tbody');

        state.cells.forEach(function(row, r) {
            var classes = ['legs-editor-row'];
            if (r < state.headerRows) {
                classes.push('is-header');
            }
            if (r >= state.cells.length - state.footerRows && state.footerRows > 0) {
                classes.push('is-footer');
            }
            if (state.rows[r] && state.rows[r].hidden) {
                classes.push('is-hidden');
            }

            var tr = h('tr', { class: classes.join(' ') });
            var rowHead = h('th', { class: 'legs-rowhead' + (self.selectedRow === r ? ' is-selected' : ''), text: String(r + 1) });
            rowHead.addEventListener('click', function() {
                self.selectedRow = self.selectedRow === r ? null : r;
                self.selectedCol = null;
                self.render();
            });
            tr.appendChild(rowHead);

            row.forEach(function(cell, c) {
                if (self.isCovered(r, c)) {
                    return;
                }

                var merge = self.mergeAt(r, c);
                var td = h('td', {
                    colspan: merge && merge.colspan > 1 ? merge.colspan : null,
                    rowspan: merge && merge.rowspan > 1 ? merge.rowspan : null,
                });

                var editable = h('div', {
                    class: 'legs-editor-cell' + (cell.trim().charAt(0) === '=' ? ' is-formula' : ''),
                    contenteditable: 'true',
                    'data-row': r,
                    'data-col': c,
                    html: cell,
                });

                td.appendChild(editable);
                tr.appendChild(td);
            });

            tbody.appendChild(tr);
        });

        table.appendChild(tbody);
        this.scroll.innerHTML = '';
        this.scroll.appendChild(table);

        this.bindCells();
        this.write();
        this.status.textContent = state.cells.length + ' × ' + colCount;
    };

    Editor.prototype.bindCells = function() {
        var self = this;

        this.scroll.querySelectorAll('.legs-editor-cell').forEach(function(cell) {
            var row = parseInt(cell.getAttribute('data-row'), 10);
            var col = parseInt(cell.getAttribute('data-col'), 10);

            cell.addEventListener('focus', function() {
                self.focus = { row: row, col: col };
            });

            cell.addEventListener('input', function() {
                // Text edits do not re-render: rebuilding the table under a caret would move it
                // to the start of the cell on every keystroke.
                self.state.cells[row][col] = cell.innerHTML;
                self.write();
            });

            cell.addEventListener('blur', function() {
                cell.classList.toggle('is-formula', cell.textContent.trim().charAt(0) === '=');
            });

            cell.addEventListener('keydown', function(event) {
                if (event.key === 'Tab') {
                    event.preventDefault();
                    self.move(row, col, event.shiftKey ? -1 : 1, 0);
                } else if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    self.move(row, col, 0, 1);
                } else if ((event.metaKey || event.ctrlKey) && event.key === 'z') {
                    event.preventDefault();
                    if (event.shiftKey) {
                        self.redo();
                    } else {
                        self.undo();
                    }
                }
            });

            cell.addEventListener('paste', function(event) {
                var text = (event.clipboardData || window.clipboardData).getData('text/plain');

                if (!text || (text.indexOf('\t') === -1 && text.indexOf('\n') === -1)) {
                    return;
                }

                // A multi-cell paste means the grid, not the cell.
                event.preventDefault();
                self.snapshot();
                self.pasteGrid(row, col, parseDelimited(text.replace(/\r\n/g, '\n').replace(/\n+$/, ''), sniffDelimiter(text)));
            });
        });
    };

    Editor.prototype.move = function(row, col, dx, dy) {
        var colCount = this.state.cells[0].length;
        var next = { row: row + dy, col: col + dx };

        if (next.col >= colCount) {
            next.col = 0;
            next.row += 1;
        } else if (next.col < 0) {
            next.col = colCount - 1;
            next.row -= 1;
        }

        // Tabbing off the last cell grows the table, which is how a spreadsheet behaves and how
        // an author expects to add a row without reaching for the toolbar.
        if (next.row >= this.state.cells.length) {
            this.snapshot();
            this.state.cells.push(new Array(colCount).fill(''));
            this.state.rows.push({});
            this.render();
        }

        if (next.row < 0) {
            return;
        }

        var target = this.scroll.querySelector('.legs-editor-cell[data-row="' + next.row + '"][data-col="' + next.col + '"]');

        if (target) {
            target.focus();
            var selection = window.getSelection();
            var range = document.createRange();
            range.selectNodeContents(target);
            range.collapse(false);
            selection.removeAllRanges();
            selection.addRange(range);
        }
    };

    Editor.prototype.pasteGrid = function(row, col, rows) {
        var state = this.state;
        var neededCols = col + Math.max.apply(null, rows.map(function(r) { return r.length; }));
        var neededRows = row + rows.length;

        while (state.cells.length < neededRows) {
            state.cells.push([]);
            state.rows.push({});
        }

        state.cells = state.cells.map(function(existing) {
            var copy = existing.slice();
            while (copy.length < neededCols) {
                copy.push('');
            }
            return copy;
        });

        while (state.columns.length < neededCols) {
            state.columns.push({});
        }

        rows.forEach(function(cells, r) {
            cells.forEach(function(value, c) {
                state.cells[row + r][col + c] = value;
            });
        });

        this.state = this.normalize(state);
        this.render();
    };

    Editor.prototype.insertRow = function(index, where) {
        this.snapshot();
        var at = where === 'above' ? index : index + 1;
        var colCount = this.state.cells[0].length;
        this.state.cells.splice(at, 0, new Array(colCount).fill(''));
        this.state.rows.splice(at, 0, {});
        if (at < this.state.headerRows) {
            this.state.headerRows++;
        }
        this.state = this.normalize(this.state);
        this.render();
    };

    Editor.prototype.insertColumn = function(index, where) {
        this.snapshot();
        var at = where === 'left' ? index : index + 1;
        this.state.cells.forEach(function(row) {
            row.splice(at, 0, '');
        });
        this.state.columns.splice(at, 0, {});
        this.state = this.normalize(this.state);
        this.render();
    };

    Editor.prototype.deleteRow = function(index) {
        if (this.state.cells.length <= 1) {
            return;
        }
        this.snapshot();
        this.state.cells.splice(index, 1);
        this.state.rows.splice(index, 1);
        if (index < this.state.headerRows) {
            this.state.headerRows--;
        }
        this.selectedRow = null;
        this.state = this.normalize(this.state);
        this.render();
    };

    Editor.prototype.deleteColumn = function(index) {
        if (this.state.cells[0].length <= 1) {
            return;
        }
        this.snapshot();
        this.state.cells.forEach(function(row) {
            row.splice(index, 1);
        });
        this.state.columns.splice(index, 1);
        this.selectedCol = null;
        this.state = this.normalize(this.state);
        this.render();
    };

    Editor.prototype.toggleHeader = function() {
        this.snapshot();
        this.state.headerRows = this.state.headerRows ? 0 : 1;
        this.state = this.normalize(this.state);
        this.render();
    };

    Editor.prototype.toggleFooter = function() {
        this.snapshot();
        this.state.footerRows = this.state.footerRows ? 0 : 1;
        this.state = this.normalize(this.state);
        this.render();
    };

    Editor.prototype.transpose = function() {
        this.snapshot();
        var cells = this.state.cells;
        var transposed = [];

        for (var c = 0; c < cells[0].length; c++) {
            transposed.push(cells.map(function(row) { return row[c]; }));
        }

        this.state = this.normalize({
            cells: transposed,
            headerRows: this.state.headerRows,
            footerRows: 0,
        });
        this.render();
    };

    Editor.prototype.openPaste = function() {
        var self = this;
        var textarea = h('textarea', { class: 'text legs-editor-paste', placeholder: 'Paste CSV or spreadsheet data here…' });
        var replace = h('label', {}, [
            h('input', { type: 'checkbox', checked: 'checked' }),
            document.createTextNode(' Replace the whole table'),
        ]);

        var wrapper = h('div', { class: 'body', style: 'padding:24px;min-width:420px' }, [
            h('h2', { text: 'Paste data' }),
            textarea,
            replace,
            h('div', { class: 'buttons right' }),
        ]);

        var cancel = h('button', { type: 'button', class: 'btn', text: 'Cancel' });
        var submit = h('button', { type: 'button', class: 'btn submit', text: 'Import' });
        wrapper.querySelector('.buttons').append(cancel, submit);

        // Garnish speaks jQuery, which the CP always has by the time an asset bundle runs.
        var overlay = new Garnish.Modal($(h('div', { class: 'modal' }, [wrapper])), { resizable: false });

        cancel.addEventListener('click', function() { overlay.hide(); });
        submit.addEventListener('click', function() {
            var text = textarea.value.replace(/\r\n/g, '\n').replace(/\n+$/, '');
            if (text) {
                self.snapshot();
                var rows = parseDelimited(text, sniffDelimiter(text));
                if (replace.querySelector('input').checked) {
                    self.state = self.normalize({ cells: rows, headerRows: self.state.headerRows });
                    self.render();
                } else {
                    self.pasteGrid(self.focus.row, self.focus.col, rows);
                }
            }
            overlay.hide();
        });

        textarea.focus();
    };

    Editor.prototype.mergeAt = function(row, col) {
        return this.state.merges.find(function(merge) {
            return merge.row === row && merge.col === col;
        }) || null;
    };

    Editor.prototype.isCovered = function(row, col) {
        return this.state.merges.some(function(merge) {
            if (merge.row === row && merge.col === col) {
                return false;
            }
            return row >= merge.row && row < merge.row + merge.rowspan
                && col >= merge.col && col < merge.col + merge.colspan;
        });
    };

    function init(root) {
        (root || document).querySelectorAll('[data-legs-editor]:not([data-legs-ready])').forEach(function(el) {
            el.setAttribute('data-legs-ready', '');
            new Editor(el);
        });
    }

    window.LegsEditor = { init: init, Editor: Editor };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { init(); });
    } else {
        init();
    }
})();
