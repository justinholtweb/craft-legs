# Legs — Craft CMS 5 Plugin

## Project Overview

Legs is a table plugin for Craft CMS 5 — a TablePress-class table library with a spreadsheet
editor in the CP, embedding in rich text, a field type, and a front-end runtime that sorts,
searches and pages. Distributed as `justinholtweb/craft-legs`. Lite (free) + Pro editions.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- No build step anywhere: the CP editor is a classic script, the front-end runtime is a plain ES
  module, and the CKEditor plugin is an ES module written against the `ckeditor5` import map.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\legs`
- Package: `justinholtweb/craft-legs`
- Handle: `legs`

### The load-bearing idea: reference tags

`craft\htmlfield\HtmlFieldData::__construct()` runs `Elements::parseRefs()` over every rich-text
value, and `Elements::_getRefTokenReplacement()` splices the result in **raw**. So a Table element
with `refHandle() = 'legs'` and a `getRender()` returning `Markup` renders inside CKEditor,
Redactor and plain HTML fields with no template changes and no per-editor rendering code.

**The editor integrations are editing affordances only.** They insert
`<div class="legs-embed" data-legs-handle="x">{legs:x:render}</div>`; the ref tag inside is what
renders. Never make rendering depend on an editor.

Consequence: a ref tag carries no parameters, so an embed always uses the table's own options.

### Data model

- `elements\Table` — the library element. **Localized**: every table exists on every site (an
  embed on another site has to be able to find it), and `translationMethod` decides whether the
  content is shared or per-site.
- `{{%legs_tables}}` — what a table *is*: `id` PK/FK→elements CASCADE, `handle` (unique),
  `options`, `source`, `sourceConfig`, `translationMethod`, denormalised `rowCount`/`colCount`
  for index sorting.
- `{{%legs_table_content}}` — what it *says*, per site: `(id, siteId)` PK, `caption`,
  `description`, `data` (JSON grid), `lastRefreshedAt`. The grid is one JSON document, not a row
  per cell — tables are small, always read whole, never queried cell-wise.
- Writing rule: a **shared** table writes a content row for every supported site from the
  non-propagating save (a propagated element carries the *target* site's content, so it has
  nothing to copy from); a **translated** one writes only its own site, and propagation seeds a
  site that has no row yet.
- `models\TableData` is the grid: one 2D `cells` array plus `headerRows`/`footerRows` **counts**,
  column/row options and merges. Shared verbatim by the element and by inline field values, so
  the editor, renderer, importers and formula engine all speak one format. `normalize()` squares
  it up and is enforced on both sides — PHP and the editor's JS both implement it.

### Services

- `tables` — the library; `handleIsTaken()`/`uniqueHandle()` are the handle authority
- `renderer` — grid → markup; decides headers, merges, sort types, stack labels
- `formulas` — `=SUM(A1:A9)`, a recursive-descent evaluator that cannot reach PHP
- `importer` / `exporter` — CSV, JSON, HTML, XLSX
- `querySource` — element-query tables, materialised + debounced rebuilds

## Traps found while building this

- **An ES module URL must carry a timestamp.** Craft's published-directory hash comes from the
  path plus the *directory* mtime, and editing a file inside a directory does not change that —
  so `getAssetUrl($bundle, 'index.js', false)` keeps returning the same URL while the contents
  change, and browsers keep running the old module. Pass `true`. Symptom: new code visible in
  `fetch()`, old behaviour at runtime.
- **A site-aware lookup that falls back is dangerous on the way in.** `Tables::getTableById()`
  falls back to another site when the requested one has no row yet (a site added after the table
  was created, resave jobs still queued) — right for reading, catastrophic for writing, because
  the save then lands on whichever site answered. Every write goes through
  `Tables::pointAtSite()` first. Found by watching an English table turn Spanish.

- **Handles and the trash.** A soft-deleted table keeps its `legs_tables` row, so a
  `UniqueValidator` (and the unique index) would hold its handle forever against a table nobody
  can see. `afterDelete()` parks the handle as `handle--trashed-<id>`; `afterRestore()` claims it
  back, or a variation if it has been reused. Validation asks an element query, which excludes
  trashed rows.
- **CKEditor 5.x is a different plugin from CKEditor 3/4.** The current one uses ESM + an import
  map (`registerCkeditorPackage($class, 'index.js')`, `$namespace`, named exports); the older one
  used DLL globals. Legs targets the new one and no-ops when `craft\ckeditor\helpers\CkeditorConfig`
  is absent.
- **Plugin init order beats the CKEditor package hooks.** `ckeditor` initialises before `legs`
  (handle order), so its loop that adds import-map entries has already run — Legs registers its
  own `registerJsImport()`. Likewise `CkeditorConfig::registerPackage()` is called directly at
  init rather than left to `EVENT_AFTER_REGISTER_ASSET_BUNDLE`, which fires after the field
  settings screen has computed its list of available toolbar items.
- **`renderObjectTemplate()` calls the subject `object`, not `element`.** Query-table template
  columns pass both.
- **HTML Purifier drops `data-*` it has not been told about.** `richtext\PurifierSupport`
  registers `data-legs-handle` against `craft\htmlfield\HtmlField` — Yii matches class-level
  handlers up the inheritance chain, so one handler covers CKEditor and Redactor.
- **Config JSON rides in an HTML attribute**, so it comes back HTML-escaped; tests have to
  `html_entity_decode()` before looking for `"searchable":true`.
- A cycle marker returned from a referenced cell is read as the number 0 unless it is propagated
  — `Formulas::$cycleDetected` rides back up the chain.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-legs/tests/integration/checks.php     # 61 checks
ddev exec bash -c 'find /var/www/craft-legs/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

The checks are idempotent and self-cleaning. Front-end demo page: `/legs-test` in the harness.
`ddev exec php craft clear-caches/cp-resources` after editing anything under `web/assets/*/dist`,
or Craft keeps serving the published copy.

## Coding conventions

- `Craft::t('legs', '…')` for user-facing strings; `src/translations/en/legs.php` lists them
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
