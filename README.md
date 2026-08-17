# Legs

Build a table once, use it anywhere.

Legs is a table plugin for Craft CMS 5. Authors edit tables in a spreadsheet-style grid in the
control panel; visitors get a real, accessible `<table>` that sorts, searches and pages without
you writing a line of table markup or wiring up DataTables.

If you have used TablePress on WordPress, you already know the shape of it — with a few things
WordPress cannot do, like building a table from an element query.

---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later

Optional:

- [craftcms/ckeditor](https://github.com/craftcms/ckeditor) 5.0+ — adds a toolbar button
- [craftcms/redactor](https://github.com/craftcms/redactor) — adds a toolbar button
- `phpoffice/phpspreadsheet` — XLSX import and export (Pro)

## Installation

```sh
composer require justinholtweb/craft-legs
php craft plugin/install legs
```

---

## Four ways to put a table on a page

A table you build in **Legs → Tables** gets a handle. Everything below refers to that handle.

**1. In rich text**, with a reference tag:

```
{legs:price-list:render}
```

This works in CKEditor, in Redactor, and in any other field built on `craftcms/html-field` —
including plain HTML fields — because Craft parses reference tags over every rich-text value
itself. There is no template change, no output filter, and nothing to configure. If both editor
plugins are installed, each gets a toolbar button that writes the tag for you.

A tag can also carry options for that one appearance:

```
{legs:price-list:render(compact,!striped,perPage=10)}
```

A bare name turns something on, `!name` turns it off, `name=value` sets it, and anything you
leave out follows the table's own settings. **No spaces** — Craft's reference-tag pattern ends at
the first space. The toolbar button writes these for you: pick a table, click the gear, and set
only what should differ here.

**2. In a template**, with the `craft.legs` variable:

```twig
{{ craft.legs.render('price-list') }}
{{ craft.legs.render('price-list', { paginate: true, perPage: 10 }) }}
```

**3. With the filter or function**, whichever reads better where you are:

```twig
{{ 'price-list'|legs }}
{{ legs('price-list') }}
{{ entry.specTable|legs }}
```

**4. With a Legs field** on an entry, which comes in two modes (see below):

```twig
{{ entry.specTable.render() }}
```

Every one of these lands on the same renderer, so a table looks and behaves the same wherever it
came from.

---

## The field

A Legs field holds one of two things, chosen in the field's settings:

- **Its own table** — the grid lives on the element, edited inline with the same grid editor the
  control panel uses. Good for a spec table that belongs to one product and nothing else.
- **A table from the library** — the field points at one or more Legs tables, so one edit
  updates every place they appear.

A reference field set to hold **one** table gives templates that table, so `entry.spec.render()`
reads the way you would expect. Set to hold more (or unlimited), it gives an element collection
in the order the author arranged them:

```twig
{% for table in entry.relatedTables %}
    <h2>{{ table.title }}</h2>
    {{ table.render() }}
{% endfor %}
```

Inline and single-reference fields both give templates something with `.render()`, `.data` and
`.options`, so switching a field between those two modes does not rewrite your templates.

---

## The grid editor

- Type, `Tab` and `Enter` to move. Tabbing off the last cell adds a row, like a spreadsheet.
- **Paste a spreadsheet into any cell** and the grid fills out from there, growing as needed —
  Excel, Numbers, Google Sheets, or any tab- or comma-delimited text.
- Insert and delete rows and columns, mark header and footer rows, transpose, undo and redo.
- Cells accept HTML — a link, a `<strong>`, an image — purified on save and again on render. Turn
  that off in the settings if you would rather cells were plain text.

## Tables on a multi-site install

Every table exists on every site — an embed on the Spanish page has to be able to find it — but
each table decides whether it *says* the same thing everywhere:

- **The same on all sites** (the default) — one grid, copied to every site on save.
- **Translated per site** — a grid per site, edited independently. A new translation starts as a
  copy of what was there, so an author opens something to edit rather than an empty grid.

The editor gets Craft's usual site switcher in the breadcrumbs, and every way of rendering a
table resolves it in the site being rendered: `{legs:prices:render}` on the Spanish page renders
the Spanish grid, and a Legs field on a translated entry points at the translation.

Add a site later and Craft's resave jobs give existing tables a presence on it. Legs does not
wait for that queue to drain: a table that has no row for a site yet is still found (and still
renders) using the content it does have.

## Data in and out

| Format | Import | Export |
|---|---|---|
| CSV / TSV (delimiter sniffed) | ✓ | ✓ |
| JSON — a grid, or a list of records | ✓ | ✓ |
| HTML — a pasted `<table>`, colspans and all | ✓ | ✓ |
| XLSX / XLS / ODS | Pro | Pro |

The JSON export is the round-trip format: it carries options, merges and column settings, so a
table can be moved between sites whole. CSV is deliberately lossy — there is nowhere in a CSV to
put a merged cell.

XLSX needs PhpSpreadsheet, which Legs suggests rather than requires — a 10 MB dependency should
not be the price of an install that only ever pastes from Sheets:

```sh
composer require phpoffice/phpspreadsheet
```

## Tables built from an element query (Pro)

A table can be defined as a query instead of typed by hand: pick an element type, give it
criteria, and define what each column reads — an attribute, a custom field, or an object template.

```
Element type   Entries
Criteria       {"section": "products", "orderBy": "title asc", "limit": 200}
Columns        Product   attribute   title
               Price     field       price
               Link      template    <a href="{{ element.url }}">Details</a>
```

The grid is **materialised**, not resolved at render: a table on a busy page must not run an
element query per request. Saving a matching element queues a rebuild on a short debounce, so a
resave of 400 entries costs one rebuild rather than four hundred. You can also rebuild on a
schedule instead:

```sh
php craft legs/tables/refresh
```

## Formulas (Pro)

Cells starting with `=` are calculated when the table renders:

```
=SUM(B2:B9)        =AVERAGE(C2:C10)      =ROUND(B2*1.2, 2)
=MIN(B2:B9)        =MAX(B2:B9)           =MEDIAN(B2:B9)
=COUNT(A2:A9)      =PRODUCT(B2:B3)       =ABS(D4)
```

References are spreadsheet-style (`B7`, `$B$7`), ranges work, and a formula may reference another
formula. Numbers are read out of formatted cells, so `=SUM(B2:B4)` works over `$1,200.00`. The
grid keeps what the author typed; only the render sees the result. Anything that does not parse
shows as `#ERROR!` — the evaluator is arithmetic and a fixed function list, and cannot reach PHP.

---

## The front end

The server renders the whole table. The runtime — one dependency-free ES module, loaded only on
pages that need it — adds:

- **Sorting** by clicking a column heading, with type sniffed per column and the sort value
  computed on the server, so `$1,200.00`, `1.10` and `3/4/25` all sort as what they are.
- **Search** that filters rows as the visitor types (Pro).
- **Pagination** (Pro).
- **Responsive stacking**, where each row becomes a labelled card below the breakpoint (Pro).
  The alternative — scroll sideways — is free and is the default.

With JavaScript off, a visitor still gets every row of a complete, accessible table.

### Styling

Legs ships one small stylesheet driven by custom properties. Retheme it without overriding rules:

```css
.legs {
    --legs-accent: #30636f;
    --legs-border: #e3e5e8;
    --legs-stripe: #f7f8f9;
    --legs-head-bg: #f2f4f6;
    --legs-pad-y: 0.55rem;
    --legs-pad-x: 0.75rem;
}
```

Or turn the stylesheet off entirely in the settings and style the markup yourself.

### Overriding the markup

Copy the plugin's `src/templates/_render/table.twig` to `templates/_legs/table.twig` in your site
and it takes over. Everything that needs a decision — which rows are headers, which cells a merge
swallows, what a column sorts as, what a stacked cell is labelled — has already been made and
handed to the template as `head`, `body`, `foot` and `columns`, so an override changes
presentation and cannot accidentally change correctness.

---

## Rich-text editors

Both integrations are *editing affordances only*. Rendering is Craft's own reference-tag parsing,
so an embed keeps working if the editor plugin is removed, or the content is moved from a Redactor
field to a CKEditor one, or an author types the tag by hand.

**CKEditor**: open the field's settings and drag the **Table** button (the one with the grid icon)
into the toolbar. Requires `craftcms/ckeditor` 5.0 or later; older, DLL-based versions of that
plugin are left alone rather than half-supported.

**Redactor**: the button is added automatically to every Redactor config that does not already
list a `plugins` array of its own.

Both insert the same block:

```html
<div class="legs-embed" data-legs-handle="price-list">{legs:price-list:render}</div>
```

---

## Editions

|  | Lite (free) | Pro |
|---|---|---|
| Library tables | 3 | unlimited |
| Grid editor, CSV/JSON/HTML import and export | ✓ | ✓ |
| Rich-text embedding (CKEditor, Redactor) | ✓ | ✓ |
| Field type, both modes, multi-select references | ✓ | ✓ |
| Per-site (translated) tables | ✓ | ✓ |
| Per-embed option overrides | ✓ | ✓ |
| Sorting | ✓ | ✓ |
| Search, pagination, responsive stacking | — | ✓ |
| Merged cells | — | ✓ |
| Formulas | — | ✓ |
| Element-query tables and scheduled refresh | — | ✓ |
| XLSX import and export | — | ✓ |

A lapsed Pro licence **downgrades** rather than breaks: stored options are left untouched, the
control panel keeps showing them, and the front end serves the Lite feature set until the licence
is renewed.

---

## Settings

Configurable in **Settings → Legs**, or in `config/legs.php`:

```php
return [
    'registerCss' => true,          // Legs' stylesheet, when a table renders
    'registerJs' => true,           // the runtime, when a table needs one
    'allowHtmlInCells' => true,     // purified on save and on render
    'purifierConfig' => null,       // a file in config/htmlpurifier/, without the extension
    'defaultOptions' => [],         // render options handed to every new table
    'refreshOnElementSave' => true, // rebuild query tables when a matching element is saved
    'refreshInterval' => 3600,
];
```

## Console commands

```sh
php craft legs/tables                       # list every table
php craft legs/tables/refresh               # rebuild all query-backed tables
php craft legs/tables/refresh --handle=team # rebuild one
php craft legs/tables/export price-list --format=json
php craft legs/tables/import price-list ./prices.csv
```

## Permissions

- **View tables** — see the Legs section and use the picker
  - **Create and edit tables**
  - **Delete tables**

---

## Licence

Proprietary. See [LICENSE.md](LICENSE.md).
