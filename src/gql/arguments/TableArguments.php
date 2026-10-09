<?php

namespace justinholtweb\legs\gql\arguments;

use craft\gql\base\ElementArguments;
use GraphQL\Type\Definition\Type;

/**
 * Arguments for `legsTables`, `legsTable` and `legsTableCount`: Craft's element arguments plus
 * the two a table query adds.
 */
class TableArguments extends ElementArguments
{
    public static function getArguments(): array
    {
        return array_merge(parent::getArguments(), [
            'handle' => [
                'name' => 'handle',
                'type' => Type::listOf(Type::string()),
                'description' => 'Narrows the results to tables with the given handle(s).',
            ],
            'source' => [
                'name' => 'source',
                'type' => Type::listOf(Type::string()),
                'description' => 'Narrows the results to tables built a given way: `manual`, `import` or `query`.',
            ],
        ]);
    }
}
