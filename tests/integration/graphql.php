<?php
/**
 * Legs GraphQL checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-legs/tests/integration/graphql.php
 *
 * Every query goes through `Craft::$app->getGql()->executeQuery()` against an in-memory schema —
 * nothing is saved to project config. Craft caches GraphQL types per process, so each schema runs
 * in a child process of its own (`--case=<name>`); the parent creates the tables and the entry,
 * runs the children, and cleans up.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\models\GqlSchema;
use justinholtweb\legs\elements\Table;
use justinholtweb\legs\gql\interfaces\TableInterface;
use justinholtweb\legs\models\CellMerge;
use justinholtweb\legs\models\RenderOptions;
use justinholtweb\legs\models\TableData;
use justinholtweb\legs\Plugin;

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

const SECTION_HANDLE = 'testEntries';
const ENTRY_TYPE = 'testEntry';

$plugin = Plugin::getInstance();
// In memory only: the harness's project config is shared.
$plugin->edition = Plugin::EDITION_PRO;

$options = getopt('', ['case:', 'state:']);

// -----------------------------------------------------------------------------------------------
// Child: one schema, one process.
// -----------------------------------------------------------------------------------------------

if (isset($options['case'])) {
    $state = json_decode(base64_decode($options['state']), true);
    $case = $options['case'];

    $site = Craft::$app->getSites()->getPrimarySite();
    $section = Craft::$app->getEntries()->getSectionByHandle(SECTION_HANDLE);
    $scope = ['sites.' . $site->uid . ':read', 'sections.' . $section->uid . ':read'];

    $scope = array_merge($scope, match ($case) {
        'all', 'lite' => ['legsTables.all:read', 'elements.inactive:read'],
        'one' => ['legsTables.' . $state['uids']['open'] . ':read'],
        default => [],
    });

    if ($case === 'lite') {
        $plugin->edition = Plugin::EDITION_LITE;
    }

    // Craft caches GraphQL results keyed on the schema, and an unsaved schema has no UID — so
    // without this, one case would be answered from another case's cache.
    Craft::$app->getConfig()->getGeneral()->enableGraphqlCaching = false;
    $schema = new GqlSchema(['name' => "Legs check $case", 'uid' => craft\helpers\StringHelper::UUID(), 'scope' => $scope]);
    $gql = Craft::$app->getGql();
    $run = fn(string $query) => $gql->executeQuery($schema, $query, [], null, true);
    $h = $state['handles'];
    $handleList = json_encode(array_values($h));

    section("Schema: $case");

    if ($case === 'all') {
        check('legsTables returns live tables and leaves out a disabled one', function() use ($run, $h, $handleList) {
            $result = $run("{ legsTables(handle: $handleList) { handle } }");
            $handles = array_column($result['data']['legsTables'] ?? [], 'handle');
            sort($handles);
            $expected = [$h['open'], $h['other']];
            sort($expected);

            return $handles === $expected ?: json_encode($result);
        });

        check('a schema that may see inactive elements still cannot ask for a trashed table', function() use ($run, $h) {
            $result = $run("{ legsTables(handle: \"{$h['trashed']}\", trashed: true) { handle } }");

            return ($result['data']['legsTables'] ?? null) === [] ?: json_encode($result);
        });

        check('legsTableCount agrees with legsTables', function() use ($run, $handleList) {
            $result = $run("{ legsTableCount(handle: $handleList) }");

            return ($result['data']['legsTableCount'] ?? null) === 2 ?: json_encode($result);
        });

        check('legsTable(handle:) returns the table, with its element fields', function() use ($run, $h) {
            $result = $run("{ legsTable(handle: \"{$h['open']}\") { id uid title handle caption source translatable rowCount colCount embedCode } }");
            $table = $result['data']['legsTable'] ?? null;

            return $table
                && $table['handle'] === $h['open']
                && $table['title'] === 'GraphQL open'
                && $table['caption'] === 'Prices'
                && $table['source'] === 'manual'
                && $table['translatable'] === false
                && $table['rowCount'] === 5
                && $table['colCount'] === 4
                && $table['embedCode'] === "{legs:{$h['open']}:render}"
                ?: json_encode($result);
        });

        $grid = $run("{ legsTable(handle: \"{$h['open']}\") { grid {
            headerRows footerRows rowCount colCount
            columns { index label align sortAs sortable metadata }
            header { index cells { col text html colspan rowspan } }
            rows { index class cells { col text html colspan rowspan label sortValue metadata } }
            footer { index cells { col text } }
            merges { row col rowspan colspan }
        } } }");
        $g = $grid['data']['legsTable']['grid'] ?? null;

        check('the grid query runs without errors', fn() => $g !== null && empty($grid['errors']) ?: json_encode($grid));

        check('header, body and footer are split by the stored counts', function() use ($g) {
            return array_column($g['header'], 'index') === [0]
                && array_column($g['rows'], 'index') === [1, 3]
                && array_column($g['footer'], 'index') === [4]
                && $g['headerRows'] === 1 && $g['footerRows'] === 1
                ?: json_encode([$g['header'], $g['rows'], $g['footer']]);
        });

        check('a hidden row is left out', fn() => !in_array(2, array_column($g['rows'], 'index'), true) ?: 'row 2 present');

        check('a hidden column is left out of columns and cells, a metadata column is kept and flagged', function() use ($g) {
            $columns = array_column($g['columns'], 'index');
            $cols = array_column($g['rows'][1]['cells'], 'col');
            $meta = array_values(array_filter($g['rows'][1]['cells'], fn($c) => $c['col'] === 3))[0] ?? null;
            $metaColumn = array_values(array_filter($g['columns'], fn($c) => $c['index'] === 3))[0] ?? null;

            return $columns === [0, 1, 3]
                && $cols === [0, 1, 3]
                && ($meta['metadata'] ?? null) === true
                && ($metaColumn['metadata'] ?? null) === true
                ?: json_encode(['columns' => $g['columns'], 'row' => $g['rows'][1]]);
        });

        check('a merge arrives as a colspan on its anchor and swallows the covered cell', function() use ($g) {
            $first = $g['rows'][0]['cells'];

            return $first[0]['col'] === 0 && $first[0]['colspan'] === 2 && $first[1]['col'] === 3
                && $g['merges'] === [['row' => 1, 'col' => 0, 'rowspan' => 1, 'colspan' => 2]]
                ?: json_encode([$first, $g['merges']]);
        });

        check('cell HTML is purified — no script survives', function() use ($g) {
            $html = $g['rows'][0]['cells'][0]['html'];

            return !str_contains($html, '<script') && str_contains($html, '<strong>') ?: $html;
        });

        check('body cells carry their column label and a sort value', function() use ($g) {
            $cell = $g['rows'][1]['cells'][1];

            return $cell['label'] === 'Price' && $cell['sortValue'] !== null ?: json_encode($cell);
        });

        check('formulas arrive evaluated, not as source', function() use ($g) {
            $text = $g['footer'][0]['cells'][1]['text'] ?? null;

            return $text !== null && !str_starts_with($text, '=') && str_contains($text, '30') ?: json_encode($g['footer']);
        });

        check('options reflect the table, Pro features included', function() use ($run, $h) {
            $result = $run("{ legsTable(handle: \"{$h['open']}\") { options { searchable paginate perPage formulas responsive } } }");
            $o = $result['data']['legsTable']['options'] ?? null;

            return $o === ['searchable' => true, 'paginate' => true, 'perPage' => 10, 'formulas' => true, 'responsive' => 'scroll'] ?: json_encode($result);
        });

        check('html renders the table, and takes per-call overrides in embed syntax', function() use ($run, $h) {
            $result = $run("{ legsTable(handle: \"{$h['open']}\") { plain: html compact: html(options: \"compact,nonsense=1\") } }");
            $t = $result['data']['legsTable'] ?? [];

            return str_contains($t['plain'] ?? '', '<table') && !str_contains($t['plain'], 'legs-compact')
                && str_contains($t['compact'] ?? '', 'legs-compact')
                ?: json_encode($result);
        });

        check('the type exposes no source config', function() use ($run) {
            $result = $run('{ __type(name: "LegsTable") { fields { name } } }');
            $fields = array_column($result['data']['__type']['fields'] ?? [], 'name');

            return $fields !== [] && !in_array('sourceConfig', $fields, true) && in_array('grid', $fields, true) ?: json_encode($fields);
        });

        check('an inline Legs field resolves to its own grid', function() use ($run, $state) {
            $result = $run("{ entry(id: {$state['entryId']}) { ... on " . ENTRY_TYPE . "_Entry { legsInline { caption grid { rows { cells { text } } } options { striped } html } } } }");
            $inline = $result['data']['entry']['legsInline'] ?? null;

            return $inline
                && $inline['caption'] === 'Inline'
                && ($inline['grid']['rows'][0]['cells'][0]['text'] ?? null) === 'x'
                && str_contains($inline['html'], '<table')
                ?: json_encode($result);
        });

        check('a reference field returns granted, live tables only — the disabled one is dropped', function() use ($run, $state, $h) {
            $result = $run("{ entry(id: {$state['entryId']}) { ... on " . ENTRY_TYPE . "_Entry { legsRef { handle } } } }");
            $handles = array_column($result['data']['entry']['legsRef'] ?? [], 'handle');

            return $handles === [$h['open'], $h['other']] ?: json_encode($result);
        });
    }

    if ($case === 'one') {
        check('legsTables returns only the granted table', function() use ($run, $h, $handleList) {
            $result = $run("{ legsTables(handle: $handleList) { handle } }");

            return array_column($result['data']['legsTables'] ?? [], 'handle') === [$h['open']] ?: json_encode($result);
        });

        check('legsTable for an ungranted table is null, by handle or by id', function() use ($run, $h, $state) {
            $byHandle = $run("{ legsTable(handle: \"{$h['other']}\") { handle } }");
            $byId = $run("{ legsTable(id: {$state['ids']['other']}) { handle } }");

            return array_key_exists('legsTable', $byHandle['data'] ?? []) && $byHandle['data']['legsTable'] === null
                && $byId['data']['legsTable'] === null
                ?: json_encode([$byHandle, $byId]);
        });

        check('legsTableCount counts only the granted table', function() use ($run, $handleList) {
            $result = $run("{ legsTableCount(handle: $handleList) }");

            return ($result['data']['legsTableCount'] ?? null) === 1 ?: json_encode($result);
        });

        check('a reference field cannot reach an ungranted table', function() use ($run, $state, $h) {
            $result = $run("{ entry(id: {$state['entryId']}) { ... on " . ENTRY_TYPE . "_Entry { legsRef { handle } } } }");

            return array_column($result['data']['entry']['legsRef'] ?? [], 'handle') === [$h['open']] ?: json_encode($result);
        });

        check('status and trashed arguments are not offered without the inactive grant', function() use ($run, $h) {
            $result = $run("{ legsTables(handle: \"{$h['trashed']}\", trashed: true) { handle } }");

            return !empty($result['errors']) && empty($result['data']['legsTables']) ?: json_encode($result);
        });
    }

    if ($case === 'none') {
        check('no grant, no legsTables query', function() use ($run) {
            $result = $run('{ legsTables { handle } }');

            return !array_key_exists('legsTables', $result['data'] ?? []) ?: json_encode($result);
        });

        check('no grant, no legsTable query', function() use ($run, $h) {
            $result = $run("{ legsTable(handle: \"{$h['open']}\") { handle } }");

            return !array_key_exists('legsTable', $result['data'] ?? []) ?: json_encode($result);
        });

        check('no grant, no reference field on the entry type', function() use ($run, $state) {
            $result = $run("{ entry(id: {$state['entryId']}) { ... on " . ENTRY_TYPE . "_Entry { legsRef { handle } } } }");

            return !empty($result['errors']) && !isset($result['data']['entry']['legsRef']) ?: json_encode($result);
        });

        check('an inline field still resolves — it is the entry’s own content', function() use ($run, $state) {
            $result = $run("{ entry(id: {$state['entryId']}) { ... on " . ENTRY_TYPE . "_Entry { legsInline { caption } } } }");

            return ($result['data']['entry']['legsInline']['caption'] ?? null) === 'Inline' ?: json_encode($result);
        });
    }

    if ($case === 'lite') {
        check('Lite reports the downgraded options and leaves formulas unevaluated', function() use ($run, $h) {
            $result = $run("{ legsTable(handle: \"{$h['open']}\") { options { searchable paginate formulas } grid { footer { cells { text } } } } }");
            $t = $result['data']['legsTable'] ?? null;

            return $t
                && $t['options'] === ['searchable' => false, 'paginate' => false, 'formulas' => false]
                && str_starts_with($t['grid']['footer'][0]['cells'][1]['text'], '=')
                ?: json_encode($result);
        });
    }

    echo "__COUNTS__ $passed $failed\n";
    exit(0);
}

// -----------------------------------------------------------------------------------------------
// Parent: fixtures, children, cleanup.
// -----------------------------------------------------------------------------------------------

$suffix = substr(md5((string)microtime(true)), 0, 6);
$handles = [
    'open' => "gql-open-$suffix",
    'other' => "gql-other-$suffix",
    'disabled' => "gql-disabled-$suffix",
    'trashed' => "gql-trashed-$suffix",
];
$tables = [];
$entry = null;

$makeTable = function(string $key, string $title) use ($plugin, $handles, &$tables): Table {
    $table = new Table();
    $table->title = $title;
    $table->handle = $handles[$key];
    $table->setData(TableData::fromRows([['a'], ['b']], 1));

    if (!$plugin->tables->saveTable($table)) {
        throw new RuntimeException(implode('; ', $table->getErrorSummary(true)));
    }

    $tables[$key] = $table;

    return $table;
};

section('Fixtures');

check('the tables save', function() use ($plugin, $handles, $makeTable, &$tables) {
    $open = new Table();
    $open->title = 'GraphQL open';
    $open->handle = $handles['open'];
    $open->caption = 'Prices';

    $data = TableData::fromRows([
        ['Item', 'Price', 'Notes', 'Tags'],
        ['<strong>Tea</strong><script>alert(1)</script>', '10', 'n', 'cat:hot'],
        ['Secret', '99', 'n', 'cat:x'],
        ['Cake', '20', 'n', 'cat:sweet'],
        ['Total', '=SUM(B2,B4)', '', ''],
    ], 1);
    $data->footerRows = 1;
    $data->rows[2]->hidden = true;
    $data->columns[2]->hidden = true;
    $data->columns[3]->hidden = true;
    $data->columns[3]->metadata = true;
    $data->merges[] = new CellMerge(1, 0, 1, 2);
    $open->setData($data->normalize());

    $options = new RenderOptions();
    $options->searchable = true;
    $options->paginate = true;
    $options->perPage = 10;
    $options->formulas = true;
    $open->setOptions($options);

    if (!$plugin->tables->saveTable($open)) {
        return implode('; ', $open->getErrorSummary(true));
    }

    $tables['open'] = $open;
    $makeTable('other', 'GraphQL other');

    $disabled = $makeTable('disabled', 'GraphQL disabled');
    $disabled->enabled = false;

    if (!$plugin->tables->saveTable($disabled)) {
        return 'disable: ' . implode('; ', $disabled->getErrorSummary(true));
    }

    $trashed = $makeTable('trashed', 'GraphQL trashed');
    $plugin->tables->deleteTable($trashed);

    return true;
});

check('the merge on the open table survived the save', function() use (&$tables) {
    $fresh = Plugin::getInstance()->tables->getTableById($tables['open']->id);

    return count($fresh->getData()->merges) === 1 ?: json_encode($fresh->getData()->toArray()['merges']);
});

check('an entry with an inline table and three references saves', function() use (&$tables, &$entry) {
    $section = Craft::$app->getEntries()->getSectionByHandle(SECTION_HANDLE);
    $type = array_values(array_filter($section->getEntryTypes(), fn($t) => $t->handle === ENTRY_TYPE))[0] ?? null;

    if (!$type) {
        return 'no ' . ENTRY_TYPE . ' entry type';
    }

    $entry = new Entry();
    $entry->sectionId = $section->id;
    $entry->typeId = $type->id;
    $entry->title = 'Legs GraphQL check';
    $entry->authorId = 1;
    $entry->setFieldValue('legsInline', [
        'data' => TableData::fromRows([['h'], ['x']], 1)->toArray(),
        'options' => (new RenderOptions())->toArray(),
        'caption' => 'Inline',
    ]);
    $entry->setFieldValue('legsRef', [$tables['open']->id, $tables['other']->id, $tables['disabled']->id]);

    return Craft::$app->getElements()->saveElement($entry) ?: implode('; ', $entry->getErrorSummary(true));
});

section('Schema components');

check('the schema editor offers the whole library and each table', function() use (&$tables) {
    $components = Craft::$app->getGql()->getAllSchemaComponents();
    $legs = $components['queries']['Legs tables'] ?? [];

    return isset($legs['legsTables.all:read'], $legs['legsTables.' . $tables['open']->uid . ':read'])
        ?: 'got ' . json_encode(array_keys($legs));
});

check('a table names both scopes that grant it', function() use (&$tables) {
    $scopes = Table::gqlScopesByContext($tables['open']);

    return $scopes === ['legsTables.all', 'legsTables.' . $tables['open']->uid] ?: json_encode($scopes);
});

check('the base type is the table interface', fn() => Table::baseGqlType()->name === TableInterface::getName());

if ($entry && isset($tables['open'], $tables['other'])) {
    $state = base64_encode(json_encode([
        'handles' => $handles,
        'uids' => ['open' => $tables['open']->uid],
        'ids' => ['open' => $tables['open']->id, 'other' => $tables['other']->id],
        'entryId' => $entry->id,
    ]));

    // The no-access schema first, per the round's gotcha list; then each in its own process anyway.
    foreach (['none', 'one', 'all', 'lite'] as $case) {
        $output = [];
        exec(sprintf('php %s --case=%s --state=%s 2>&1', escapeshellarg(__FILE__), $case, escapeshellarg($state)), $output);

        foreach ($output as $line) {
            if (preg_match('/^__COUNTS__ (\d+) (\d+)$/', $line, $m)) {
                $passed += (int)$m[1];
                $failed += (int)$m[2];
                continue;
            }

            echo $line . "\n";
        }

        if (!preg_grep('/^__COUNTS__/', $output)) {
            $failed++;
            echo "  ✗ case $case did not finish\n";
        }
    }
}

section('Cleanup');

check('the fixtures delete', function() use (&$tables, &$entry) {
    if ($entry) {
        Craft::$app->getElements()->deleteElement($entry, true);
    }

    foreach ($tables as $table) {
        Craft::$app->getElements()->deleteElement($table, true);
    }

    return true;
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
