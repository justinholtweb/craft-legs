/**
 * Legs — the front-end runtime.
 *
 * Shipped as an ES module with no build step and no dependencies. Everything here is
 * enhancement: the server has already rendered a complete, correct, accessible table, and if
 * this file never loads the visitor still gets all of the data — just without sorting,
 * searching or paging.
 *
 * The renderer has done the thinking that needs server-side knowledge: every sortable cell
 * already carries a `data-legs-sort` value for numbers and dates, so nothing here has to guess
 * what "1.10" or "£1,200" or "3/4/25" means.
 */

const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });

class LegsTable {
    constructor(wrapper) {
        this.wrapper = wrapper;
        this.table = wrapper.querySelector('table');
        this.tbody = this.table?.querySelector('tbody');

        if (!this.table || !this.tbody) {
            return;
        }

        this.config = readConfig(wrapper);
        this.texts = this.config.texts || {};
        this.controls = wrapper.querySelector('[data-legs-controls]');
        this.status = wrapper.querySelector('[data-legs-status]');
        this.rows = Array.from(this.tbody.rows);
        this.matching = this.rows.slice();
        this.page = 1;
        this.sortColumn = null;
        this.sortDir = 'asc';
        this.query = '';

        // Cell text is read once per row rather than per keystroke: a 500-row table filtered on
        // every input event is the difference between instant and janky on a phone.
        this.haystacks = this.rows.map((row) => row.textContent.toLowerCase());

        wrapper.classList.add('legs-managed');

        if (this.config.sortable) {
            this.bindSorting();
        }

        if (this.config.searchable || this.config.paginate) {
            this.buildControls();
        }

        if (this.config.defaultSortColumn !== null && this.config.defaultSortColumn !== undefined) {
            const header = this.headerFor(this.config.defaultSortColumn);
            if (header) {
                this.sortBy(header, this.config.defaultSortDir === 'desc' ? 'desc' : 'asc');
            }
        }

        this.apply();
    }

    headerFor(column) {
        return this.table.querySelector(`th[data-legs-sort-col="${column}"]`);
    }

    bindSorting() {
        const headers = this.table.querySelectorAll('th[data-legs-sort-col]');

        headers.forEach((header) => {
            header.tabIndex = 0;
            header.setAttribute('role', 'button');

            const activate = (event) => {
                event.preventDefault();
                const next = header.getAttribute('aria-sort') === 'ascending' ? 'desc' : 'asc';
                this.sortBy(header, next);
                this.apply();
            };

            header.addEventListener('click', activate);
            header.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    activate(event);
                }
            });
        });
    }

    sortBy(header, dir) {
        const column = Number(header.dataset.legsSortCol);
        const sortAs = header.dataset.legsSortAs || 'text';
        const factor = dir === 'desc' ? -1 : 1;

        this.table.querySelectorAll('th[data-legs-sort-col]').forEach((other) => {
            other.setAttribute('aria-sort', 'none');
        });
        header.setAttribute('aria-sort', dir === 'desc' ? 'descending' : 'ascending');

        this.sortColumn = column;
        this.sortDir = dir;

        const keyed = this.rows.map((row, index) => ({
            row,
            index,
            key: sortKey(row, column, sortAs),
        }));

        keyed.sort((a, b) => {
            // Empty cells sort last in both directions: a blank is missing data, not a small
            // number, and burying it under a descending sort hides the rows that matter.
            if (a.key === null && b.key === null) return a.index - b.index;
            if (a.key === null) return 1;
            if (b.key === null) return -1;

            let result;
            if (typeof a.key === 'number' && typeof b.key === 'number') {
                result = a.key - b.key;
            } else {
                result = collator.compare(String(a.key), String(b.key));
            }

            // A stable tiebreak on the original order, so re-sorting a column with ties does
            // not reshuffle rows the visitor was already reading.
            return result === 0 ? a.index - b.index : result * factor;
        });

        this.rows = keyed.map((entry) => entry.row);
        const haystacks = keyed.map((entry) => this.haystacks[entry.index]);
        this.haystacks = haystacks;

        const fragment = document.createDocumentFragment();
        this.rows.forEach((row) => fragment.appendChild(row));
        this.tbody.appendChild(fragment);
    }

    buildControls() {
        if (!this.controls) {
            return;
        }

        this.controls.hidden = false;

        if (this.config.searchable) {
            const box = document.createElement('div');
            box.className = 'legs-search';

            const label = document.createElement('label');
            label.className = 'legs-status';
            const id = `${this.table.id || 'legs'}-search`;
            label.htmlFor = id;
            label.textContent = this.texts.search || 'Search this table';

            const input = document.createElement('input');
            input.type = 'search';
            input.id = id;
            input.placeholder = this.texts.searchPlaceholder || 'Search…';
            input.autocomplete = 'off';

            let timer = null;
            input.addEventListener('input', () => {
                window.clearTimeout(timer);
                timer = window.setTimeout(() => {
                    this.query = input.value.trim().toLowerCase();
                    this.page = 1;
                    this.apply();
                }, 120);
            });

            box.append(label, input);
            this.controls.appendChild(box);
        }

        this.count = document.createElement('div');
        this.count.className = 'legs-count';
        this.controls.appendChild(this.count);

        if (this.config.paginate) {
            this.pagination = document.createElement('nav');
            this.pagination.className = 'legs-pagination';

            this.prev = document.createElement('button');
            this.prev.type = 'button';
            this.prev.textContent = this.texts.previous || 'Previous';
            this.prev.addEventListener('click', () => {
                this.page = Math.max(1, this.page - 1);
                this.apply();
            });

            this.next = document.createElement('button');
            this.next.type = 'button';
            this.next.textContent = this.texts.next || 'Next';
            this.next.addEventListener('click', () => {
                this.page = Math.min(this.pageCount(), this.page + 1);
                this.apply();
            });

            this.pageLabel = document.createElement('span');
            this.pageLabel.className = 'legs-count';

            this.pagination.append(this.prev, this.pageLabel, this.next);
            this.wrapper.appendChild(this.pagination);
        }
    }

    pageCount() {
        const perPage = Math.max(1, Number(this.config.perPage) || 25);
        return Math.max(1, Math.ceil(this.matching.length / perPage));
    }

    apply() {
        this.matching = this.query
            ? this.rows.filter((row, index) => this.haystacks[index].includes(this.query))
            : this.rows.slice();

        const perPage = Math.max(1, Number(this.config.perPage) || 25);
        const paging = Boolean(this.config.paginate);
        this.page = Math.min(this.page, this.pageCount());

        const from = paging ? (this.page - 1) * perPage : 0;
        const to = paging ? from + perPage : this.matching.length;
        const visible = new Set(this.matching.slice(from, to));

        let alternate = false;
        this.rows.forEach((row) => {
            const show = visible.has(row);
            row.hidden = !show;
            if (show) {
                if (alternate) {
                    row.setAttribute('data-legs-alt', '');
                } else {
                    row.removeAttribute('data-legs-alt');
                }
                alternate = !alternate;
            } else {
                row.removeAttribute('data-legs-alt');
            }
        });

        this.renderEmptyState();
        this.renderCounts(from, Math.min(to, this.matching.length));
    }

    renderEmptyState() {
        const existing = this.tbody.querySelector('.legs-empty');

        if (this.matching.length > 0) {
            existing?.remove();
            return;
        }

        if (existing) {
            return;
        }

        const row = document.createElement('tr');
        row.className = 'legs-empty';
        const cell = document.createElement('td');
        cell.colSpan = this.table.querySelectorAll('thead tr:last-child th').length || 1;
        cell.textContent = this.texts.noResults || 'No matching rows';
        row.appendChild(cell);
        this.tbody.appendChild(row);
    }

    renderCounts(from, to) {
        const total = this.matching.length;

        if (this.count) {
            this.count.textContent = total
                ? format(this.texts.showing || 'Showing {from}–{to} of {total}', {
                    from: total ? from + 1 : 0,
                    to,
                    total,
                })
                : '';
        }

        if (this.pagination) {
            const pages = this.pageCount();
            this.pagination.hidden = pages < 2;
            this.prev.disabled = this.page <= 1;
            this.next.disabled = this.page >= pages;
            this.pageLabel.textContent = format(this.texts.page || 'Page {page} of {pages}', {
                page: this.page,
                pages,
            });
        }

        if (this.status) {
            this.status.textContent = total
                ? format(this.texts.showing || 'Showing {from}–{to} of {total}', {
                    from: from + 1,
                    to,
                    total,
                })
                : (this.texts.noResults || 'No matching rows');
        }
    }
}

function sortKey(row, column, sortAs) {
    const cell = cellForColumn(row, column);

    if (!cell) {
        return null;
    }

    const explicit = cell.getAttribute('data-legs-sort');

    if (explicit !== null && explicit !== '') {
        const number = Number(explicit);
        return Number.isNaN(number) ? explicit : number;
    }

    const text = cell.textContent.trim();

    if (text === '') {
        return null;
    }

    return sortAs === 'number' ? null : text;
}

/**
 * Finds the cell in a visual column, honouring colspans — a row with a merged cell does not
 * have its columns and its cells at the same indexes.
 */
function cellForColumn(row, column) {
    let index = 0;

    for (const cell of row.cells) {
        const span = cell.colSpan || 1;

        if (column >= index && column < index + span) {
            return cell;
        }

        index += span;
    }

    return null;
}

function readConfig(wrapper) {
    try {
        return JSON.parse(wrapper.getAttribute('data-legs') || '{}');
    } catch (error) {
        return {};
    }
}

function format(template, values) {
    return String(template).replace(/\{(\w+)\}/g, (match, key) => (
        Object.prototype.hasOwnProperty.call(values, key) ? values[key] : match
    ));
}

export function init(root = document) {
    root.querySelectorAll('[data-legs]:not([data-legs-ready])').forEach((wrapper) => {
        wrapper.setAttribute('data-legs-ready', '');
        // eslint-disable-next-line no-new
        new LegsTable(wrapper);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => init());
} else {
    init();
}

window.Legs = { init };

export default LegsTable;
