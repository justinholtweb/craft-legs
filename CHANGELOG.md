# Release Notes for Legs

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
