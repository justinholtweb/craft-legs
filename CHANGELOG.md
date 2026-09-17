# Release Notes for Legs

## Unreleased

### Added

- **Front-end export** ([#3](https://github.com/justinholtweb/craft-legs/issues/3)) — a table
  marked **Downloadable** is served at `/legs/export/<handle>/<format>` in CSV, JSON, HTML or
  XLSX (Pro), with `craft.legs.exportUrl()` for the link and `craft.legs.csv()`, `.json()` and
  `.html()` for the strings. Off by default per table; the route moves or turns off with the new
  `exportPath` setting.
- **Metadata columns** ([#2](https://github.com/justinholtweb/craft-legs/issues/2)) — a column
  marked `hidden` *and* `metadata` is rendered as a visually hidden cell instead of being left
  out, so it can carry a token for the runtime's search to match and a site can build precise
  facets of its own. `hidden` on its own is unchanged.

### Fixed

- A column of human-formatted dates left on **Auto** sorted by day of month
  ([#1](https://github.com/justinholtweb/craft-legs/issues/1)): `May 19, 2026` was read as the
  number 19.2026, and `5/19/2026` as 5192026, so the date test was never reached.
- A sortable column that came after a hidden one sorted on nothing, because omitting the hidden
  column put the DOM out of step with the column indexes the runtime sorts by.

## 5.0.0

Initial release.

### Added

- **Tables** as a first-class element type, with a spreadsheet-style grid editor: keyboard
  navigation, paste a whole spreadsheet into any cell, insert/delete/move rows and columns,
  header and footer rows, transpose, undo and redo.
- **Reference-tag embedding** — `{legs:my-table:render}` renders a table inside CKEditor,
  Redactor, or any other `craftcms/html-field` field, with no template changes.
- **CKEditor and Redactor toolbar buttons** that write the tag for you, sharing one table picker.
- **A Legs field** in two modes: its own inline table, or a reference to one or more tables in
  the library — one reference gives templates the table itself, several give a collection in the
  author's order.
- **Per-site tables** — every table exists on every site, and each table chooses whether its
  content is shared or translated per site, with Craft's own site switcher in the editor.
- **Per-embed options** — `{legs:prices:render(compact,!striped,perPage=10)}` overrides
  presentation for one appearance without a second mechanism; the editor toolbar buttons write
  the syntax for you.
- **A front-end runtime** — one dependency-free ES module — adding click-to-sort, search,
  pagination and responsive stacking over server-rendered markup, with sort values computed
  server-side so formatted numbers and dates sort correctly.
- **Import and export**: CSV/TSV with the delimiter sniffed, JSON (grids and record lists), HTML
  tables with colspans preserved as merges, and XLSX via PhpSpreadsheet (Pro, optional
  dependency).
- **Element-query tables** (Pro) — build a table from entries, categories, products or anything
  else, materialised and rebuilt on a debounce when a matching element is saved.
- **Formulas** (Pro) — `=SUM(B2:B9)` and friends, evaluated at render.
- Twig: `craft.legs.render()`, `craft.legs.table()`, `craft.legs.tables()`, a `legs` filter and a
  `legs()` function.
- Console commands for listing, refreshing, importing and exporting.
- Lite and Pro editions, with a lapsed licence downgrading rather than breaking.
