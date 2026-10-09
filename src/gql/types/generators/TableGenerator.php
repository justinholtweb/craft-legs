<?php

namespace justinholtweb\legs\gql\types\generators;

use Craft;
use craft\gql\base\Generator;
use craft\gql\base\GeneratorInterface;
use craft\gql\base\ObjectType;
use craft\gql\base\SingleGeneratorInterface;
use craft\gql\GqlEntityRegistry;
use justinholtweb\legs\elements\Table;
use justinholtweb\legs\gql\interfaces\TableInterface;
use justinholtweb\legs\gql\Scope;
use justinholtweb\legs\gql\types\TableType;

/**
 * Generates `LegsTable`, the one table type — and only for a schema that can read a table.
 */
class TableGenerator extends Generator implements GeneratorInterface, SingleGeneratorInterface
{
    public static function generateTypes(mixed $context = null): array
    {
        if (!Scope::canReadAny()) {
            return [];
        }

        $type = static::generateType($context);

        return [$type->name => $type];
    }

    public static function generateType(mixed $context): ObjectType
    {
        $typeName = Table::GQL_TYPE_NAME;

        return GqlEntityRegistry::getOrCreate($typeName, fn() => new TableType([
            'name' => $typeName,
            'fields' => fn() => Craft::$app->getGql()->prepareFieldDefinitions(TableInterface::getFieldDefinitions(), $typeName),
        ]));
    }
}
