<?php

namespace justinholtweb\legs\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * A table's presentation options, as the renderer would use them — after the edition's
 * downgrade, so a Lite site never advertises search or pagination it will not serve.
 */
class OptionsType
{
    public const NAME = 'LegsTableOptions';

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::NAME, fn() => new ObjectType([
            'name' => self::NAME,
            'description' => 'How a Legs table is presented and what its runtime does.',
            'fields' => fn() => [
                'sortable' => ['type' => Type::nonNull(Type::boolean())],
                'defaultSortColumn' => ['type' => Type::int(), 'description' => 'Column index the table starts sorted by, if any.'],
                'defaultSortDir' => ['type' => Type::nonNull(Type::string()), 'description' => '`asc` or `desc`.'],
                'searchable' => ['type' => Type::nonNull(Type::boolean())],
                'paginate' => ['type' => Type::nonNull(Type::boolean())],
                'perPage' => ['type' => Type::nonNull(Type::int())],
                'responsive' => ['type' => Type::nonNull(Type::string()), 'description' => '`none`, `scroll` or `stack`.'],
                'striped' => ['type' => Type::nonNull(Type::boolean())],
                'hover' => ['type' => Type::nonNull(Type::boolean())],
                'bordered' => ['type' => Type::nonNull(Type::boolean())],
                'compact' => ['type' => Type::nonNull(Type::boolean())],
                'stickyHeader' => ['type' => Type::nonNull(Type::boolean())],
                'captionPosition' => ['type' => Type::nonNull(Type::string()), 'description' => '`top` or `bottom`.'],
                'formulas' => ['type' => Type::nonNull(Type::boolean()), 'description' => 'Whether formulas are evaluated. Grid cells already carry the results.'],
                'class' => ['type' => Type::string()],
                'downloadable' => ['type' => Type::nonNull(Type::boolean()), 'description' => 'Whether visitors may download the table from the front-end export route.'],
            ],
        ]));
    }
}
