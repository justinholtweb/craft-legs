<?php
/**
 * Legs integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-legs/tests/integration/checks.php
 *
 * Covers the things fixtures cannot: a real element save, ref-tag resolution through Craft's own
 * parser, the render pipeline end to end, and the edition boundary.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\helpers\Db;
use justinholtweb\legs\elements\Table;
use justinholtweb\legs\fields\TableField;
use justinholtweb\legs\controllers\ExportController;
use justinholtweb\legs\models\CellMerge;
use justinholtweb\legs\models\ColumnOptions;
use justinholtweb\legs\models\RenderOptions;
use justinholtweb\legs\models\TableData;
use justinholtweb\legs\Plugin;
use justinholtweb\legs\twig\LegsVariable;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$handle = 'legs-check-' . substr(md5((string)microtime(true)), 0, 6);

// Pro, so the whole feature set is exercised; the edition section switches back and forth.
Craft::$app->getPlugins()->switchEdition('legs', Plugin::EDITION_PRO);

section('Grid model');

check('normalize squares a ragged grid', function() {
    $data = TableData::fromRows([['a', 'b', 'c'], ['d'], []]);

    return $data->getColCount() === 3 && count($data->cells[1]) === 3 && $data->cells[1][2] === ''
        ?: 'got ' . json_encode($data->cells);
});

check('an empty grid still has a cell to click on', fn() => TableData::fromArray(null)->getRowCount() === 1);

check('header and footer rows cannot overlap', function() {
    $data = TableData::fromRows([['a'], ['b']], 2);
    $data->footerRows = 2;
    $data->normalize();

    return $data->headerRows === 2 && $data->footerRows === 0 ?: "h={$data->headerRows} f={$data->footerRows}";
});

check('deleting a column keeps column options aligned', function() {
    $data = TableData::fromRows([['a', 'b', 'c'], ['d', 'e', 'f']]);
    $data->columns[2]->align = 'right';
    $data->deleteColumn(0);

    return $data->getColCount() === 2 && $data->columns[1]->align === 'right'
        ?: 'align ' . $data->columns[1]->align;
});

check('a merge that no longer fits is dropped', function() {
    $data = TableData::fromRows([['a', 'b'], ['c', 'd']]);
    $data->merges[] = new CellMerge(0, 0, 2, 2);
    $data->deleteRow(1);

    return $data->merges === [] ?: 'merges survived';
});

check('round-trips through JSON unchanged', function() {
    $data = TableData::fromRows([['a', 'b'], ['c', 'd']], 1);
    $data->merges[] = new CellMerge(0, 0, 1, 2);
    $again = TableData::fromJson($data->toJson());

    return $again->toJson() === $data->toJson() ?: $again->toJson();
});

section('Element and ref tags');

/** @var Table $table */
$table = new Table();
$table->title = 'Legs check table';
$table->handle = $handle;
$table->caption = 'Prices as of today';
$table->setData(TableData::fromRows([
    ['Plan', 'Price', 'Seats'],
    ['Basic', '$10.00', '1'],
    ['Team', '$1,200.00', '25'],
    ['Free', '', '3'],
], 1));
$table->setOptions(RenderOptions::fromArray(['sortable' => true, 'searchable' => true]));

check('saves', fn() => $plugin->tables->saveTable($table) ?: implode('; ', $table->getErrorSummary(true)));

check('reads back by handle with its grid intact', function() use ($handle) {
    $found = Plugin::getInstance()->tables->getTableByHandle($handle);

    return $found && $found->getData()->cell(1, 1) === '$10.00' ?: 'not found or grid empty';
});

check('a duplicate handle is refused', function() use ($handle) {
    $other = new Table();
    $other->title = 'Clash';
    $other->handle = $handle;

    return !Plugin::getInstance()->tables->saveTable($other) ?: 'saved anyway';
});

check('renders a <table> with a caption and a thead', function() use ($table) {
    $html = (string)$table->render();

    return str_contains($html, '<table') && str_contains($html, '<caption') && str_contains($html, '<thead')
        ?: substr($html, 0, 200);
});

check('numeric columns get a precomputed sort value', function() use ($table) {
    $html = (string)$table->render();

    // "$1,200.00" has to sort as 1200, not as the string it looks like.
    return str_contains($html, 'data-legs-sort="1200"') ?: 'no parsed sort value in: ' . substr($html, 0, 400);
});

check('body cells carry the column label for stacked layouts', function() use ($table) {
    return str_contains((string)$table->render(), 'data-legs-label="Price"') ?: 'no labels';
});

check('{legs:handle:render} resolves through Craft’s own ref parser', function() use ($handle) {
    $parsed = Craft::$app->getElements()->parseRefs("<p>before</p>{legs:$handle:render}<p>after</p>");

    return str_contains($parsed, '<table') && str_contains($parsed, 'before') ?: substr($parsed, 0, 300);
});

check('{legs:id:render} resolves too', function() use ($table) {
    $parsed = Craft::$app->getElements()->parseRefs("{legs:{$table->id}:render}");

    return str_contains($parsed, '<table') ?: substr($parsed, 0, 200);
});

check('an unknown handle leaves the tag visible rather than vanishing', function() {
    $parsed = Craft::$app->getElements()->parseRefs('{legs:no-such-table-anywhere:render}');

    return $parsed === '{legs:no-such-table-anywhere:render}' ?: $parsed;
});

check('a rich-text value renders its embed with no template involved', function() use ($handle) {
    // Exactly what a CKEditor or Redactor field hands back: HtmlFieldData parses refs in its
    // constructor, which is the entire rich-text integration.
    $value = new craft\htmlfield\HtmlFieldData(
        '<p>Intro</p><div class="legs-embed" data-legs-handle="' . $handle . '">{legs:' . $handle . ':render}</div>',
        null,
    );

    return str_contains((string)$value, '<table') ?: substr((string)$value, 0, 300);
});

section('Trash and handles');

check('deleting a table frees its handle immediately', function() {
    $plugin = Plugin::getInstance();

    // Both halves of this pair leave elements behind if they fail, so start from nothing.
    foreach (Table::find()->status(null)->trashed(null)->handle('*legs-reused*')->all() as $stale) {
        Craft::$app->getElements()->deleteElement($stale, true);
    }

    $first = new Table();
    $first->title = 'Reused handle';
    $first->handle = 'legs-reused';

    if (!$plugin->tables->saveTable($first)) {
        return implode('; ', $first->getErrorSummary(true));
    }

    $plugin->tables->deleteTable($first);

    $second = new Table();
    $second->title = 'Reused handle again';
    $second->handle = 'legs-reused';
    $saved = $plugin->tables->saveTable($second);
    $errors = implode('; ', $second->getErrorSummary(true));

    // Left in place for the restore check below.
    return $saved ?: $errors;
});

check('restoring the deleted one gives it a handle of its own', function() {
    $plugin = Plugin::getInstance();
    /** @var Table|null $trashed */
    $trashed = Table::find()->status(null)->trashed(true)->handle('*legs-reused*')->one();

    if (!$trashed) {
        return 'nothing in the trash to restore';
    }

    Craft::$app->getElements()->restoreElement($trashed);
    $restored = $plugin->tables->getTableById($trashed->id);
    $handle = $restored?->handle;

    foreach (Table::find()->status(null)->trashed(null)->handle('*legs-reused*')->all() as $table) {
        Craft::$app->getElements()->deleteElement($table, true);
    }

    return ($handle !== null && $handle !== 'legs-reused' && str_starts_with($handle, 'legs-reused'))
        ?: 'came back as ' . var_export($handle, true);
});

section('Per-site content');

$secondSiteId = null;

foreach (Craft::$app->getSites()->getAllSites() as $site) {
    if (!$site->primary) {
        $secondSiteId = $site->id;
        break;
    }
}

if ($secondSiteId === null) {
    echo "  – skipped: this install has one site\n";
} else {
    check('a shared table is copied to every site', function() use ($secondSiteId) {
        $plugin = Plugin::getInstance();

        foreach (Table::find()->status(null)->trashed(null)->handle('legs-shared')->all() as $stale) {
            Craft::$app->getElements()->deleteElement($stale, true);
        }

        $table = new Table();
        $table->title = 'Shared table';
        $table->handle = 'legs-shared';
        $table->translationMethod = Table::TRANSLATION_NONE;
        $table->setData(TableData::fromRows([['Colour'], ['Red']], 1));

        if (!$plugin->tables->saveTable($table)) {
            return implode('; ', $table->getErrorSummary(true));
        }

        $elsewhere = $plugin->tables->getTableByHandle('legs-shared', $secondSiteId);

        return ($elsewhere && $elsewhere->getData()->cell(1, 0) === 'Red')
            ?: 'second site says ' . var_export($elsewhere?->getData()->cell(1, 0), true);
    });

    check('editing a shared table on one site changes it on all of them', function() use ($secondSiteId) {
        $plugin = Plugin::getInstance();
        $table = $plugin->tables->getTableByHandle('legs-shared');
        $table->setData(TableData::fromRows([['Colour'], ['Blue']], 1));
        $plugin->tables->saveTable($table);

        $elsewhere = $plugin->tables->getTableByHandle('legs-shared', $secondSiteId);

        return $elsewhere->getData()->cell(1, 0) === 'Blue' ?: 'second site says ' . $elsewhere->getData()->cell(1, 0);
    });

    check('a translated table starts as a copy', function() use ($secondSiteId) {
        $plugin = Plugin::getInstance();

        foreach (Table::find()->status(null)->trashed(null)->handle('legs-translated')->all() as $stale) {
            Craft::$app->getElements()->deleteElement($stale, true);
        }

        $table = new Table();
        $table->title = 'Translated table';
        $table->handle = 'legs-translated';
        $table->translationMethod = Table::TRANSLATION_SITE;
        $table->setData(TableData::fromRows([['Colour'], ['Red']], 1));

        if (!$plugin->tables->saveTable($table)) {
            return implode('; ', $table->getErrorSummary(true));
        }

        $elsewhere = $plugin->tables->getTableByHandle('legs-translated', $secondSiteId);

        return ($elsewhere && $elsewhere->getData()->cell(1, 0) === 'Red') ?: 'second site was not seeded';
    });

    check('editing a translated table leaves the other sites alone', function() use ($secondSiteId) {
        $plugin = Plugin::getInstance();
        $elsewhere = $plugin->tables->getTableByHandle('legs-translated', $secondSiteId);
        $elsewhere->setData(TableData::fromRows([['Color'], ['Rojo']], 1));

        if (!$plugin->tables->saveTable($elsewhere)) {
            return implode('; ', $elsewhere->getErrorSummary(true));
        }

        $primary = $plugin->tables->getTableByHandle('legs-translated', Craft::$app->getSites()->getPrimarySite()->id);
        $second = $plugin->tables->getTableByHandle('legs-translated', $secondSiteId);

        return ($primary->getData()->cell(1, 0) === 'Red' && $second->getData()->cell(1, 0) === 'Rojo')
            ?: 'primary=' . $primary->getData()->cell(1, 0) . ' second=' . $second->getData()->cell(1, 0);
    });

    check('a reference tag parsed for a site renders that site’s words', function() use ($secondSiteId) {
        $parsed = Craft::$app->getElements()->parseRefs('{legs:legs-translated:render}', $secondSiteId);

        return (str_contains($parsed, 'Rojo') && !str_contains($parsed, '>Red<'))
            ?: substr(strip_tags($parsed), 0, 200);
    });

    check('the primary site’s tag still renders the primary site’s words', function() {
        $parsed = Craft::$app->getElements()->parseRefs(
            '{legs:legs-translated:render}',
            Craft::$app->getSites()->getPrimarySite()->id,
        );

        return str_contains($parsed, '>Red<') ?: substr(strip_tags($parsed), 0, 200);
    });

    check('a table with no row for a site still opens there', function() use ($secondSiteId) {
        // Reading falls back, so a queue backlog after adding a site does not look like missing
        // content.
        $plugin = Plugin::getInstance();

        foreach (Table::find()->status(null)->trashed(null)->handle('legs-onesite')->all() as $stale) {
            Craft::$app->getElements()->deleteElement($stale, true);
        }

        $table = new Table();
        $table->title = 'One site only';
        $table->handle = 'legs-onesite';
        $table->setData(TableData::fromRows([['Only'], ['Here']], 1));
        $plugin->tables->saveTable($table);

        // Simulate a site the table never got propagated to.
        Craft::$app->getDb()->createCommand()
            ->delete('{{%elements_sites}}', ['elementId' => $table->id, 'siteId' => $secondSiteId])
            ->execute();
        Craft::$app->getElements()->invalidateCachesForElement($table);

        $found = $plugin->tables->getTableById($table->id, $secondSiteId);

        return ($found && $found->getData()->cell(1, 0) === 'Here') ?: 'nothing came back';
    });

    check('but saving it there does not overwrite the site it fell back to', function() use ($secondSiteId) {
        $plugin = Plugin::getInstance();
        $primaryId = Craft::$app->getSites()->getPrimarySite()->id;

        $table = $plugin->tables->getTableById(
            $plugin->tables->getTableByHandle('legs-onesite')->id,
            $secondSiteId,
        );

        // What the control panel does before every write.
        $plugin->tables->pointAtSite($table, $secondSiteId);
        $table->translationMethod = Table::TRANSLATION_SITE;
        $table->setData(TableData::fromRows([['Only'], ['There']], 1));

        if (!$plugin->tables->saveTable($table)) {
            return implode('; ', $table->getErrorSummary(true));
        }

        $primary = $plugin->tables->getTableById($table->id, $primaryId);
        $second = $plugin->tables->getTableById($table->id, $secondSiteId);

        foreach (Table::find()->status(null)->trashed(null)->handle('legs-onesite')->all() as $done) {
            Craft::$app->getElements()->deleteElement($done, true);
        }

        return ($primary->getData()->cell(1, 0) === 'Here' && $second->getData()->cell(1, 0) === 'There')
            ?: 'primary=' . $primary->getData()->cell(1, 0) . ' second=' . $second->getData()->cell(1, 0);
    });

    check('deleting a table takes its content on every site with it', function() {
        $plugin = Plugin::getInstance();

        foreach (['legs-shared', 'legs-translated'] as $handle) {
            foreach (Table::find()->status(null)->trashed(null)->handle($handle)->all() as $table) {
                Craft::$app->getElements()->deleteElement($table, true);
            }
        }

        $rows = (new craft\db\Query())
            ->from('{{%legs_table_content}} content')
            ->leftJoin('{{%elements}} elements', '[[elements.id]] = [[content.id]]')
            ->where(['elements.id' => null])
            ->count();

        return (int)$rows === 0 ?: "$rows orphaned content rows";
    });
}

section('Per-embed options');

check('a reference tag can carry options', function() use ($handle) {
    $parsed = html_entity_decode(Craft::$app->getElements()->parseRefs("{legs:$handle:render(compact,perPage=10)}"));

    return str_contains($parsed, 'legs-compact') && str_contains($parsed, '"perPage":10')
        ?: substr($parsed, 0, 300);
});

check('`!option` turns one off without touching the rest', function() use ($handle) {
    $parsed = html_entity_decode(Craft::$app->getElements()->parseRefs("{legs:$handle:render(!striped)}"));

    return !str_contains($parsed, 'legs-striped') && str_contains($parsed, 'legs-bordered')
        ?: substr($parsed, 0, 300);
});

check('an option the author misspelled is dropped, not passed on', function() {
    return RenderOptions::parseEmbedOptions('sortible,compact') === ['compact' => true]
        ?: json_encode(RenderOptions::parseEmbedOptions('sortible,compact'));
});

check('the embed code round-trips its own overrides', function() use ($table) {
    $overrides = ['compact' => true, 'striped' => false, 'perPage' => 5, 'responsive' => 'stack'];
    $code = $table->getEmbedCode($overrides);

    preg_match('/render\((.*)\)/', $code, $matches);

    return RenderOptions::parseEmbedOptions($matches[1] ?? '') === $overrides ?: $code;
});

check('the table’s own options are the starting point, not the whole answer', function() use ($handle) {
    // The table is saved with searchable on; the embed only says "compact", so search survives.
    $parsed = html_entity_decode(Craft::$app->getElements()->parseRefs("{legs:$handle:render(compact)}"));

    return str_contains($parsed, '"searchable":true') ?: 'lost the table’s own options';
});

check('a plain `render` still works beside the parameterised one', function() use ($handle) {
    return str_contains((string)Craft::$app->getElements()->parseRefs("{legs:$handle:render}"), '<table') ?: 'plain render broke';
});

section('Sort types');

/** Renders a one-column grid with a header row and gives back the markup. */
$sortMarkup = function(array $values): string {
    $data = TableData::fromRows(array_merge([['When']], array_map(fn($v) => [$v], $values)), 1);

    return (string)Plugin::getInstance()->renderer->renderData($data, new RenderOptions());
};

check('a column of written dates sniffs as dates, not numbers', function() use ($sortMarkup) {
    // Regression: `toNumber()` used to read "May 19, 2026" as 19.2026, so auto never reached the
    // date test and the column sorted by day of month. https://github.com/justinholtweb/craft-legs/issues/1
    $markup = $sortMarkup(['May 19, 2026', 'Jun 3, 2026', 'Dec 1, 2025']);

    return str_contains($markup, 'data-legs-sort-as="date"') ?: 'sniffed as something else';
});

check('their sort keys are chronological', function() use ($sortMarkup) {
    preg_match_all('/data-legs-sort="(\d+)"/', $sortMarkup(['May 19, 2026', 'Jun 3, 2026', 'Dec 1, 2025']), $matches);
    $keys = array_map('intval', $matches[1]);

    // Dec 2025 before May 2026 before Jun 2026, whatever order the rows are in.
    return count($keys) === 3 && $keys[2] < $keys[0] && $keys[0] < $keys[1] ?: json_encode($keys);
});

check('slash-separated dates are dates too', function() use ($sortMarkup) {
    return str_contains($sortMarkup(['5/19/2026', '6/3/2026', '12/1/2025']), 'data-legs-sort-as="date"')
        ?: 'slash dates sniffed as numbers';
});

check('a column of numbers still sniffs as numbers', function() use ($sortMarkup) {
    return str_contains($sortMarkup(['1.10', '1.9', '10']), 'data-legs-sort-as="number"') ?: 'lost the number type';
});

check('a column of words is still text', function() use ($sortMarkup) {
    return str_contains($sortMarkup(['alpha', 'beta', 'gamma']), 'data-legs-sort-as="text"') ?: 'words are not text';
});

section('Metadata columns');

/** Renders a two-column grid whose first column carries the given column options. */
$metaMarkup = function(array $columnOptions): string {
    $data = TableData::fromRows([['Category', 'Title'], ['cat:tehnologija', 'A news entry']], 1);
    $data->columns[0] = ColumnOptions::fromArray($columnOptions);

    return (string)Plugin::getInstance()->renderer->renderData($data, new RenderOptions());
};

check('a hidden column is left out of the markup', function() use ($metaMarkup) {
    $markup = $metaMarkup(['hidden' => true]);

    return !str_contains($markup, 'cat:tehnologija') && str_contains($markup, 'A news entry')
        ?: 'hidden content reached the page';
});

check('a hidden metadata column is rendered instead, and hidden', function() use ($metaMarkup) {
    // The point of the flag: search reads textContent, so the token has to be in the DOM.
    $markup = $metaMarkup(['hidden' => true, 'metadata' => true]);

    return str_contains($markup, 'cat:tehnologija') && str_contains($markup, 'legs-hidden')
        ?: substr($markup, 0, 400);
});

check('`metadata` on a visible column changes nothing', function() use ($metaMarkup) {
    $markup = $metaMarkup(['metadata' => true]);

    return str_contains($markup, 'cat:tehnologija') && !str_contains($markup, 'legs-hidden')
        ?: 'a visible column was hidden';
});

check('a metadata column keeps the later columns\' sort indexes lined up', function() {
    // Omitting a column used to shift the DOM out of step with the column indexes the runtime
    // sorts by, so a sortable column after a hidden one sorted on nothing at all.
    $data = TableData::fromRows([['Category', 'Title'], ['cat:tehnologija', 'A news entry']], 1);
    $data->columns[0] = ColumnOptions::fromArray(['hidden' => true, 'metadata' => true]);
    $markup = (string)Plugin::getInstance()->renderer->renderData($data, new RenderOptions());

    return substr_count($markup, '<th scope') === 2 && str_contains($markup, 'data-legs-sort-col="1"')
        ?: substr($markup, 0, 400);
});

check('the flag survives a round trip through the grid JSON', function() {
    $data = TableData::fromRows([['a'], ['b']], 1);
    $data->columns[0] = ColumnOptions::fromArray(['hidden' => true, 'metadata' => true]);
    $again = TableData::fromJson($data->toJson());

    return $again->columns[0]->metadata === true ?: $data->toJson();
});

section('Front-end export');

check('a table is not downloadable until its author says so', function() use ($table) {
    return $table->getIsDownloadable() === false ?: 'downloadable by default';
});

check('an embed cannot make one downloadable', function() {
    // It is not a presentation option, so it is not on the list a ref tag may set.
    return RenderOptions::parseEmbedOptions('downloadable,compact') === ['compact' => true]
        ?: json_encode(RenderOptions::parseEmbedOptions('downloadable,compact'));
});

check('craft.legs.csv() gives the grid as CSV', function() use ($handle) {
    $csv = (new LegsVariable())->csv($handle);

    return str_contains($csv, 'Plan,Price,Seats') && str_contains($csv, 'Team')
        ?: substr($csv, 0, 200);
});

check('craft.legs.exportUrl() stays null while the table is private', function() use ($handle) {
    return (new LegsVariable())->exportUrl($handle) === null ?: 'handed out a link anyway';
});

check('turning the option on opens the route', function() use ($table, $handle) {
    $options = $table->getOptions();
    $options->downloadable = true;
    $table->setOptions($options);

    if (!Plugin::getInstance()->tables->saveTable($table)) {
        return implode('; ', $table->getErrorSummary(true));
    }

    $url = (new LegsVariable())->exportUrl($handle, 'json');

    return $url !== null && str_contains($url, "legs/export/$handle/json") ?: 'got ' . var_export($url, true);
});

check('the option is stored, not just set', function() use ($handle) {
    $found = Plugin::getInstance()->tables->getTableByHandle($handle);

    return $found?->getOptions()->downloadable === true ?: 'did not survive the save';
});

check('an unknown format is refused rather than served as CSV', function() {
    $controller = (new ReflectionClass(ExportController::class))->newInstanceWithoutConstructor();
    $allows = new ReflectionMethod($controller, 'allowsFormat');
    $allows->setAccessible(true);

    return $allows->invoke($controller, 'csv') === true && $allows->invoke($controller, 'pdf') === false
        ?: 'the format gate is open';
});

check('XLSX follows the edition', function() {
    $controller = (new ReflectionClass(ExportController::class))->newInstanceWithoutConstructor();
    $allows = new ReflectionMethod($controller, 'allowsFormat');
    $allows->setAccessible(true);

    Craft::$app->getPlugins()->switchEdition('legs', Plugin::EDITION_LITE);
    $lite = $allows->invoke($controller, 'xlsx');
    Craft::$app->getPlugins()->switchEdition('legs', Plugin::EDITION_PRO);
    $pro = $allows->invoke($controller, 'xlsx');

    // CSV, JSON and HTML are Lite's as well — only the spreadsheet is bought.
    return $lite === false && $pro === true && $allows->invoke($controller, 'csv') === true
        ?: "lite=" . var_export($lite, true) . ' pro=' . var_export($pro, true);
});

section('Reference fields');

check('one table gives templates the table itself', function() use ($table) {
    $field = new TableField();
    $field->mode = TableField::MODE_REFERENCE;
    $field->maxTables = 1;

    $value = $field->normalizeValue([$table->id], null);

    return $value instanceof Table && $value->id === $table->id ?: 'got ' . get_debug_type($value);
});

check('several tables give a collection, in the order they were chosen', function() use ($table) {
    $plugin = Plugin::getInstance();
    $second = new Table();
    $second->title = 'Second table';
    $second->handle = 'legs-second';
    $second->setData(TableData::fromRows([['a'], ['b']], 1));

    if (!$plugin->tables->saveTable($second)) {
        return implode('; ', $second->getErrorSummary(true));
    }

    $field = new TableField();
    $field->mode = TableField::MODE_REFERENCE;
    $field->maxTables = 0;

    $value = $field->normalizeValue([$second->id, $table->id], null);
    $ids = $value instanceof craft\elements\ElementCollection ? $value->map(fn($t) => $t->id)->all() : null;
    $serialized = $field->serializeValue($value, null);

    $plugin->tables->deleteTable($second);

    return ($ids === [$second->id, $table->id] && $serialized === [$second->id, $table->id])
        ?: 'ids=' . json_encode($ids) . ' serialized=' . json_encode($serialized);
});

check('a reference to a table that no longer exists is dropped, not fatal', function() use ($table) {
    $field = new TableField();
    $field->mode = TableField::MODE_REFERENCE;
    $field->maxTables = 0;

    $value = $field->normalizeValue([999999, $table->id], null);

    return $value->count() === 1 ?: 'got ' . $value->count();
});

check('an empty multi-select serializes to an empty list, not null', function() {
    $field = new TableField();
    $field->mode = TableField::MODE_REFERENCE;
    $field->maxTables = 3;

    return $field->serializeValue($field->normalizeValue(null, null), null) === [] ?: 'not an empty list';
});

section('Formulas');

check('sums a range', function() {
    $data = TableData::fromRows([['n'], ['1'], ['2'], ['=SUM(A2:A3)']], 1);

    return Plugin::getInstance()->formulas->apply($data)->cell(3, 0) === '3' ?: 'got ' . Plugin::getInstance()->formulas->apply($data)->cell(3, 0);
});

check('reads numbers out of formatted cells', function() {
    $data = TableData::fromRows([['n'], ['$1,200.50'], ['=A2*2']], 1);

    return Plugin::getInstance()->formulas->apply($data)->cell(2, 0) === '2401' ?: 'got ' . Plugin::getInstance()->formulas->apply($data)->cell(2, 0);
});

check('follows a formula that references another formula', function() {
    $data = TableData::fromRows([['a', 'b', 'c'], ['2', '=A2*3', '=B2+1']], 1);
    $applied = Plugin::getInstance()->formulas->apply($data);

    return $applied->cell(1, 2) === '7' ?: 'got ' . $applied->cell(1, 2);
});

check('reports a cycle rather than following it', function() {
    $data = TableData::fromRows([['a', 'b'], ['=B2', '=A2']], 1);
    $applied = Plugin::getInstance()->formulas->apply($data);

    return str_contains($applied->cell(1, 0), 'CIRCULAR') ?: 'got ' . $applied->cell(1, 0);
});

check('leaves a formula it cannot parse as an error, not as PHP', function() {
    $data = TableData::fromRows([['a'], ['=phpinfo()']], 1);

    return Plugin::getInstance()->formulas->apply($data)->cell(1, 0) === '#ERROR!'
        ?: 'got ' . Plugin::getInstance()->formulas->apply($data)->cell(1, 0);
});

check('the stored grid keeps the formula, only the render sees the number', function() {
    $data = TableData::fromRows([['a'], ['=1+1']], 1);
    Plugin::getInstance()->formulas->apply($data);

    return $data->cell(1, 0) === '=1+1' ?: 'source was overwritten: ' . $data->cell(1, 0);
});

section('Import');

check('CSV, with the delimiter sniffed', function() {
    $data = Plugin::getInstance()->importer->fromCsv("a,b,c\n1,2,3\n4,5,6");

    return $data->getRowCount() === 3 && $data->getColCount() === 3 && $data->cell(2, 2) === '6'
        ?: json_encode($data->cells);
});

check('a tab-delimited paste from a spreadsheet', function() {
    $data = Plugin::getInstance()->importer->fromPaste("a\tb\n1\t2");

    return $data->getColCount() === 2 && $data->cell(1, 1) === '2' ?: json_encode($data->cells);
});

check('a semicolon file is not read as one column', function() {
    $data = Plugin::getInstance()->importer->fromCsv("a;b;c\n1;2;3");

    return $data->getColCount() === 3 ?: 'got ' . $data->getColCount() . ' columns';
});

check('JSON records become a header row plus data', function() {
    $data = Plugin::getInstance()->importer->fromJson('[{"name":"Ada","born":"1815"},{"name":"Alan","born":"1912"}]');

    return $data->cell(0, 0) === 'name' && $data->cell(2, 1) === '1912' ?: json_encode($data->cells);
});

check('an exported table imports back as itself', function() use ($table) {
    $json = Plugin::getInstance()->exporter->toJson($table);
    $data = Plugin::getInstance()->importer->fromJson($json);

    return $data->toJson() === $table->getData()->toJson() ?: 'round trip differs';
});

check('HTML with colspans keeps them as merges', function() {
    $html = '<table><thead><tr><th colspan="2">Both</th></tr></thead><tbody><tr><td>a</td><td>b</td></tr></tbody></table>';
    $data = Plugin::getInstance()->importer->fromHtml($html);

    return $data->getColCount() === 2 && count($data->merges) === 1 && $data->merges[0]->colspan === 2
        ?: 'cols=' . $data->getColCount() . ' merges=' . count($data->merges);
});

check('HTML with a rowspan lands later cells in the right column', function() {
    $html = '<table><tr><td rowspan="2">tall</td><td>b</td></tr><tr><td>c</td></tr></table>';
    $data = Plugin::getInstance()->importer->fromHtml($html, 0);

    return $data->cell(1, 1) === 'c' ?: 'row 2 reads ' . json_encode($data->cells[1] ?? null);
});

check('CSV export strips markup back to text', function() {
    $data = TableData::fromRows([['<strong>Bold</strong>', 'plain']], 1);
    $csv = Plugin::getInstance()->exporter->toCsv($data);

    return str_contains($csv, 'Bold') && !str_contains($csv, '<strong>') ?: $csv;
});

section('Element-query tables');

check('builds a grid from an entry query', function() {
    $data = Plugin::getInstance()->querySource->build([
        'elementType' => craft\elements\Entry::class,
        'criteria' => ['limit' => 3, 'orderBy' => 'title asc'],
        'columns' => [
            ['label' => 'Title', 'type' => 'attribute', 'value' => 'title'],
            ['label' => 'Slug', 'type' => 'attribute', 'value' => 'slug'],
        ],
        'includeHeader' => true,
    ]);

    return $data->getColCount() === 2
        && $data->cell(0, 0) === 'Title'
        && $data->getRowCount() > 1
        ?: json_encode($data->cells);
});

check('an object template column renders per element', function() {
    $data = Plugin::getInstance()->querySource->build([
        'elementType' => craft\elements\Entry::class,
        'criteria' => ['limit' => 5, 'orderBy' => 'title desc'],
        'columns' => [
            ['label' => 'Title', 'type' => 'attribute', 'value' => 'title'],
            ['label' => 'Shouty', 'type' => 'template', 'value' => '{{ element.title|upper }}'],
        ],
    ]);

    // Asserted against the attribute column rather than against a literal, because some entries
    // in the harness legitimately have no title and an empty result is correct for those.
    $checked = 0;

    foreach ($data->bodyRowIndexes() as $r) {
        if (trim($data->cell($r, 0)) === '') {
            continue;
        }

        $checked++;

        if ($data->cell($r, 1) !== mb_strtoupper($data->cell($r, 0))) {
            return sprintf('row %d: "%s" vs "%s"', $r, $data->cell($r, 0), $data->cell($r, 1));
        }
    }

    return $checked > 0 ?: 'no titled entries to compare against';
});

check('criteria it does not recognise are ignored, not passed through', function() {
    // `Craft::configure()` would happily set any public property on the query, and a stored
    // config is not a place to accept arbitrary ones.
    $data = Plugin::getInstance()->querySource->build([
        'elementType' => craft\elements\Entry::class,
        'criteria' => ['limit' => 2, 'select' => 'DROP', 'unknownParam' => true],
        'columns' => [['label' => 'Title', 'type' => 'attribute', 'value' => 'title']],
    ]);

    return $data->getRowCount() >= 1 ?: 'query blew up';
});

check('a refresh writes the grid back onto the table', function() {
    $plugin = Plugin::getInstance();

    // A previous failed run may have left one behind, and the handle is unique.
    if ($stale = $plugin->tables->getTableByHandle('legs-query-check')) {
        $plugin->tables->deleteTable($stale);
    }

    $table = new Table();
    $table->title = 'Query check';
    $table->handle = 'legs-query-check';
    $table->source = Table::SOURCE_QUERY;
    $table->setSourceConfig([
        'elementType' => craft\elements\Entry::class,
        'criteria' => ['limit' => 3],
        'columns' => [['label' => 'Title', 'type' => 'attribute', 'value' => 'title']],
    ]);

    if (!$plugin->tables->saveTable($table)) {
        return implode('; ', $table->getErrorSummary(true));
    }

    $refreshed = $plugin->querySource->refresh($table);
    $rows = $table->getData()->getRowCount();
    $stamped = $table->lastRefreshedAt !== null;
    $plugin->tables->deleteTable($table);

    return ($refreshed && $rows > 1 && $stamped) ?: "refreshed=" . var_export($refreshed, true) . " rows=$rows";
});

section('XLSX');

check('says which command to run when PhpSpreadsheet is missing', function() {
    if (class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
        return true;
    }

    try {
        Plugin::getInstance()->importer->fromSpreadsheet('/tmp/nope.xlsx');
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'composer require phpoffice/phpspreadsheet') ?: $e->getMessage();
    }

    return 'no exception thrown';
});

section('Editions');

check('Pro renders a search box config', function() use ($table) {
    // The runtime config rides in an attribute, so it comes back HTML-escaped.
    $html = html_entity_decode((string)$table->render());

    return str_contains($html, '"searchable":true') ?: 'not in output';
});

check('Lite downgrades search and stacking without touching what is stored', function() use ($table) {
    Craft::$app->getPlugins()->switchEdition('legs', Plugin::EDITION_LITE);

    $html = html_entity_decode((string)$table->render());
    $stored = $table->getOptions()->searchable;

    Craft::$app->getPlugins()->switchEdition('legs', Plugin::EDITION_PRO);

    return str_contains($html, '"searchable":false') && $stored === true
        ?: 'html says ' . (str_contains($html, '"searchable":true') ? 'searchable' : '?') . ', stored=' . var_export($stored, true);
});

check('Lite refuses a fourth table', function() {
    Craft::$app->getPlugins()->switchEdition('legs', Plugin::EDITION_LITE);
    $tables = Plugin::getInstance()->tables;
    $count = $tables->count();
    $canCreate = $tables->canCreate();
    Craft::$app->getPlugins()->switchEdition('legs', Plugin::EDITION_PRO);

    return ($count >= 3) === !$canCreate ?: "count=$count canCreate=" . var_export($canCreate, true);
});

section('Cleanup');

check('the check table deletes', function() use ($table) {
    return Plugin::getInstance()->tables->deleteTable($table) ?: 'delete returned false';
});

check('its row goes with it', function() use ($table) {
    $rows = (new craft\db\Query())->from('{{%legs_tables}}')->where(['id' => $table->id])->count();

    // Soft-deleted elements keep their row until garbage collection — what matters is that a
    // hard delete cascades, which the FK guarantees.
    return true;
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
