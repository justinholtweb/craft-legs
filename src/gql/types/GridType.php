<?php

namespace justinholtweb\legs\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * A table's grid as GraphQL hands it out — the renderer's own decisions, as data.
 *
 * Built from {@see \justinholtweb\legs\services\Renderer::describe()}, so a headless front end
 * gets exactly what the Twig template gets: header, body and footer rows with hidden rows and
 * columns already left out, merges as spans on their anchor cells, purified cell HTML and
 * precomputed sort values. The sub-types are plain object types over arrays; nothing in them
 * needs a resolver of its own.
 */
class GridType
{
    public const NAME = 'LegsGrid';

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::NAME, fn() => new ObjectType([
            'name' => self::NAME,
            'description' => 'A Legs table’s grid, as the renderer lays it out.',
            'fields' => fn() => [
                'headerRows' => [
                    'type' => Type::nonNull(Type::int()),
                    'description' => 'How many leading rows are header rows.',
                ],
                'footerRows' => [
                    'type' => Type::nonNull(Type::int()),
                    'description' => 'How many trailing rows are footer rows.',
                ],
                'rowCount' => [
                    'type' => Type::nonNull(Type::int()),
                    'description' => 'Rows in the stored grid, hidden ones included.',
                ],
                'colCount' => [
                    'type' => Type::nonNull(Type::int()),
                    'description' => 'Columns in the stored grid, hidden ones included.',
                ],
                'columns' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(self::columnType()))),
                    'description' => 'The visible columns (and metadata columns), in order.',
                ],
                'header' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(self::rowType()))),
                    'description' => 'Rows that belong in `<thead>`.',
                ],
                'rows' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(self::rowType()))),
                    'description' => 'Body rows.',
                ],
                'footer' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(self::rowType()))),
                    'description' => 'Rows that belong in `<tfoot>`.',
                ],
                'merges' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(self::mergeType()))),
                    'description' => 'Merged regions, anchored at their top-left cell, in stored-grid coordinates. Already applied as `colspan`/`rowspan` on the cells.',
                ],
            ],
        ]));
    }

    public static function rowType(): Type
    {
        return GqlEntityRegistry::getOrCreate('LegsRow', fn() => new ObjectType([
            'name' => 'LegsRow',
            'description' => 'One row of a Legs table.',
            'fields' => fn() => [
                'index' => [
                    'type' => Type::nonNull(Type::int()),
                    'description' => 'The row’s position in the stored grid.',
                ],
                'class' => [
                    'type' => Type::string(),
                    'description' => 'The CSS class the author gave the row.',
                ],
                'cells' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(self::cellType()))),
                    'description' => 'The row’s cells, without the ones a merge covers.',
                ],
            ],
        ]));
    }

    public static function cellType(): Type
    {
        return GqlEntityRegistry::getOrCreate('LegsCell', fn() => new ObjectType([
            'name' => 'LegsCell',
            'description' => 'One cell of a Legs table.',
            'fields' => fn() => [
                'col' => [
                    'type' => Type::nonNull(Type::int()),
                    'description' => 'The cell’s column in the stored grid.',
                ],
                'html' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'The cell’s content as safe HTML: purified when the site allows HTML in cells, escaped when it does not.',
                ],
                'text' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'The cell’s content as plain text.',
                ],
                'colspan' => [
                    'type' => Type::nonNull(Type::int()),
                ],
                'rowspan' => [
                    'type' => Type::nonNull(Type::int()),
                ],
                'align' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => '`left`, `center` or `right`.',
                ],
                'class' => [
                    'type' => Type::string(),
                    'description' => 'The column’s CSS class.',
                ],
                'label' => [
                    'type' => Type::string(),
                    'description' => 'The header text for this cell’s column, for stacked layouts. Null in header rows.',
                ],
                'sortValue' => [
                    'type' => Type::string(),
                    'description' => 'What the cell sorts as — a number, timestamp or folded text. Null in header rows.',
                ],
                'metadata' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'Whether this cell is in a metadata column: searchable, never shown.',
                    'resolve' => fn(array $cell) => (bool)$cell['hidden'],
                ],
            ],
        ]));
    }

    public static function columnType(): Type
    {
        return GqlEntityRegistry::getOrCreate('LegsColumn', fn() => new ObjectType([
            'name' => 'LegsColumn',
            'description' => 'One column of a Legs table.',
            'fields' => fn() => [
                'index' => [
                    'type' => Type::nonNull(Type::int()),
                    'description' => 'The column’s position in the stored grid.',
                ],
                'label' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'The text of the last header row in this column.',
                ],
                'align' => [
                    'type' => Type::nonNull(Type::string()),
                ],
                'width' => [
                    'type' => Type::string(),
                ],
                'class' => [
                    'type' => Type::string(),
                ],
                'sortable' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'Whether this column can be sorted — the table and the column both have to allow it.',
                ],
                'sortAs' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => '`text`, `number` or `date`, sniffed from the values when the author left it on auto.',
                ],
                'metadata' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'A hidden column kept for search: never show its cells.',
                ],
            ],
        ]));
    }

    public static function mergeType(): Type
    {
        return GqlEntityRegistry::getOrCreate('LegsMerge', fn() => new ObjectType([
            'name' => 'LegsMerge',
            'description' => 'A merged region of a Legs table.',
            'fields' => fn() => [
                'row' => ['type' => Type::nonNull(Type::int())],
                'col' => ['type' => Type::nonNull(Type::int())],
                'rowspan' => ['type' => Type::nonNull(Type::int())],
                'colspan' => ['type' => Type::nonNull(Type::int())],
            ],
        ]));
    }
}
