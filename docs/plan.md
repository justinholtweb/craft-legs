# Legs — plan

A TablePress-class table plugin for Craft CMS 5. Package `justinholtweb/craft-legs`,
namespace `justinholtweb\legs`, handle `legs`.

The pitch: **build a table once, use it anywhere** — in a field, in CKEditor, in Redactor, in a
template — and get a real, accessible, sortable, searchable table on the front end without the
site developer writing table markup or wiring DataTables.

## Decisions locked (2026-08-17)

1. **Editions: Lite (free) + Pro (paid).** Pricing **$29 / $19 renewal** — deliberately the
   cheapest thing in the family (Stub $79, Smoke $129, Caffeine $149), because a table plugin is
   something every site wants and nobody budgets for. `models/Edition.php` is the single pure
   description of the boundary, as in Caffeine.
2. **A bundled, zero-dependency front-end runtime** (`legs.js`, ES modules, no build step):
   click-to-sort, search filter, pagination, responsive stacking. Progressive enhancement over
   server-rendered markup — the table is complete and correct with JS off.
3. **The field type does both**: per-field setting picks *reference* (choose tables from the
   library) or *inline* (grid data lives on the entry).
4. **Import/export: CSV, JSON, HTML paste, XLSX, and live element-query tables.**
   XLSX is a **soft dependency** — `phpoffice/phpspreadsheet` is `suggest`ed, not `require`d, and
   the CP tells you the one command to run. A 10 MB dependency should not be the price of a free
   Lite install that only ever pastes from Sheets.

## The load-bearing idea: ref tags

`craft\htmlfield\HtmlFieldData::__construct()` runs `Craft::$app->getElements()->parseRefs()` over
**every** rich-text field value — CKEditor, Redactor, and any other `craftcms/html-field` field.
`Elements::_getRefTokenReplacement()` resolves `{legs:my-prices:render}` by reading
`$element->render` and splicing the result in **raw**.

So a Table element with `refHandle() = 'legs'` and a `getRender()` returning `Markup` renders
inside rich text with:

- no template changes on the site,
- no output-buffer or page-HTML rewriting,
- no per-editor rendering code — one mechanism serves CKEditor, Redactor, and plain HTML fields,
- correct behaviour in GraphQL and anywhere else the field is cast to a string,
- a graceful miss: an unresolvable tag is left visible rather than silently vanishing.

The editor integrations are therefore *only* editing affordances: they insert and preview the ref
tag. Nothing about rendering depends on which editor produced the content.

Consequence first accepted, then solved: a ref tag looked like it could carry no options — but
Craft's pattern for the attribute part allows parentheses, so `{legs:prices:render(compact)}`
resolves through `Table::__get()` and an embed can say something about *this* appearance without
a second mechanism. Everything left out still follows the table's own settings, so editing the
table changes every embed of it — the TablePress bargain and the point of a central library.

## Architecture

### Elements

`elements\Table` — a first-class element, which buys the element index, search, permissions,
relations, revisions, and (the reason above) ref tags.

- Not localizable in v1: `getSupportedSites()` returns every site so a table resolves on any
  site, but the grid is stored once, keyed on `id` alone, and `afterSave()` skips propagation
  passes. Per-site tables are a later phase, not a v1 promise.
- `getRef()` → handle, so `{legs:my-prices:render}` works alongside `{legs:14:render}`.

### Database

- `{{%legs_tables}}` — `id` PK/FK→elements CASCADE, `handle`, `caption`, `description`,
  `data` (JSON grid), `options` (JSON render options), `source` (manual|query|import),
  `sourceConfig` JSON, `rowCount`, `colCount`, `lastRefreshedAt`.

The grid is one JSON document, not a cell table. Tables are small (a few thousand cells at the
outside), always read whole, and never queried cell-wise — a row per cell would buy nothing and
cost a join.

### Grid format

One 2D `cells` array plus counts, not separate head/body/foot arrays. The editor then has exactly
one thing to edit and the renderer decides which rows become `<thead>` / `<tfoot>`:

```json
{
  "cells": [["Plan", "Price"], ["Basic", "$10"]],
  "columns": [{"label": null, "align": "left", "width": null, "sortAs": "auto", "sortable": true}],
  "rows": [{"class": null, "hidden": false}],
  "headerRows": 1,
  "footerRows": 0,
  "merges": [{"row": 0, "col": 0, "rowspan": 1, "colspan": 2}]
}
```

`models\TableData` owns this shape and is shared by the element *and* the inline field value, so
the editor, the renderer, the importers and the formula engine all speak one format.

### Rendering

`services\Renderer` → `src/templates/_render/table.twig`, overridable per site at
`templates/_legs/table.twig`. Semantic `<table>` with `<caption>`, `<thead>`/`<tbody>`/`<tfoot>`,
`scope` attributes, and `data-legs-*` hooks the runtime reads. CSS ships as one small stylesheet
driven by custom properties; a setting turns it off for sites that style their own.

Entry points: `{{ table.render() }}`, `craft.legs.table('handle')`, a `|legs` Twig filter, and the
ref tag.

### Editions

| | Lite | Pro |
|---|---|---|
| Library tables | 3 | unlimited |
| Grid editor, CSV/JSON/HTML import + export | ✓ | ✓ |
| Rich-text embedding (CKEditor, Redactor) | ✓ | ✓ |
| Field type (both modes) | ✓ | ✓ |
| Sorting | ✓ | ✓ |
| Search, pagination, responsive stacking | — | ✓ |
| Merged cells | — | ✓ |
| Formulas (`=SUM(A1:A9)`) | — | ✓ |
| Element-query tables + scheduled refresh | — | ✓ |
| XLSX import/export | — | ✓ |

Embedding and the editor stay free deliberately: they are what makes the plugin worth installing,
and a table you cannot put on a page is not a table.

## Phases

0. **Scaffold** — composer, `Plugin.php`, settings, install migration, icons, harness wiring.
1. **Element + CP** — `Table` element, query, index, permissions, services, ref tags.
2. **Grid editor** — the spreadsheet UI: keyboard nav, range select, paste from Sheets/Excel,
   insert/delete/move rows and columns, undo/redo, header/footer toggles, column options.
3. **Rendering** — renderer service, Twig templates/tags/variable/filter, CSS, front-end runtime.
4. **Field type** — reference and inline modes, one value object.
5. **Rich text** — CKEditor package (`registerCkeditorPackage` + `EVENT_MODIFY_CONFIG`) and
   Redactor plugin (`EVENT_REGISTER_PLUGIN_PATHS` + `EVENT_DEFINE_REDACTOR_CONFIG`).
6. **Data in and out** — CSV/JSON/HTML/XLSX, element-query tables, refresh jobs.
7. **Editions, tests, docs** — edition boundary enforced server-side, test suite, README,
   CHANGELOG, docs pages.
8. **Second pass (2026-08-17)** — the three things the first pass deliberately left out:
   - **Per-site tables.** `legs_tables` split into what a table *is* and `legs_table_content`
     for what it *says*, per site. Every table exists on every site; `translationMethod` decides
     whether the words are shared or translated. Craft's site switcher in the editor.
   - **Per-embed options.** The ref tag carries them after all: Craft's pattern allows
     parentheses in the attribute, so `{legs:prices:render(compact,perPage=10)}` resolves through
     `Table::__get()`. No second mechanism, and the toolbar buttons write the syntax.
   - **Multi-select references.** One table still gives templates the table; more give an
     `ElementCollection` in the author's order.
