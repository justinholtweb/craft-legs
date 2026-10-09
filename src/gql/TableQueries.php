<?php

namespace justinholtweb\legs\gql;

use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;
use justinholtweb\legs\gql\arguments\TableArguments;
use justinholtweb\legs\gql\interfaces\TableInterface;
use justinholtweb\legs\gql\resolvers\TableResolver;

/**
 * The GraphQL queries Legs registers, prefixed `legs` so they cannot collide with anybody
 * else's `tables`.
 */
class TableQueries
{
    /** @return array<string, array<string, mixed>> */
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !Scope::canReadAny()) {
            return [];
        }

        return [
            'legsTables' => [
                'type' => Type::listOf(TableInterface::getType()),
                'args' => TableArguments::getArguments(),
                'resolve' => TableResolver::class . '::resolve',
                'description' => 'This query is used to query for Legs tables.',
                'complexity' => GqlHelper::relatedArgumentComplexity(),
            ],
            'legsTableCount' => [
                'type' => Type::nonNull(Type::int()),
                'args' => TableArguments::getArguments(),
                'resolve' => TableResolver::class . '::resolveCount',
                'description' => 'This query is used to return the number of Legs tables.',
                'complexity' => GqlHelper::relatedArgumentComplexity(),
            ],
            'legsTable' => [
                'type' => TableInterface::getType(),
                'args' => TableArguments::getArguments(),
                'resolve' => TableResolver::class . '::resolveOne',
                'description' => 'This query is used to query for a single Legs table, usually by `handle`.',
                'complexity' => GqlHelper::relatedArgumentComplexity(),
            ],
        ];
    }
}
