<?php

namespace justinholtweb\legs\gql\interfaces;

use Craft;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element;
use craft\gql\types\DateTime;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;
use justinholtweb\legs\gql\types\generators\TableGenerator;
use justinholtweb\legs\gql\types\Presentation;

/**
 * The GraphQL interface for library tables.
 *
 * One implementing type, `LegsTable` — tables have no groups and no field layouts, so there is
 * nothing for a type per context to carry. The interface exists because Craft's element plumbing
 * (relations, `ElementInterface` unions) speaks in interfaces.
 *
 * What a table is, never how it was built: a query-backed table's criteria stay on the server.
 */
class TableInterface extends Element
{
    public static function getTypeGenerator(): string
    {
        return TableGenerator::class;
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        $type = GqlEntityRegistry::createEntity(self::getName(), new InterfaceType([
            'name' => static::getName(),
            'fields' => self::class . '::getFieldDefinitions',
            'description' => 'This is the interface implemented by Legs tables.',
            'resolveType' => self::class . '::resolveElementTypeName',
        ]));

        TableGenerator::generateTypes();

        return $type;
    }

    public static function getName(): string
    {
        return 'LegsTableInterface';
    }

    public static function getFieldDefinitions(): array
    {
        return Craft::$app->getGql()->prepareFieldDefinitions(array_merge(parent::getFieldDefinitions(), [
            'handle' => [
                'name' => 'handle',
                'type' => Type::nonNull(Type::string()),
                'description' => 'The table’s handle — the name its embed tag uses.',
            ],
            'caption' => [
                'name' => 'caption',
                'type' => Type::string(),
                'description' => 'The table’s caption on this site.',
            ],
            'description' => [
                'name' => 'description',
                'type' => Type::string(),
                'description' => 'The table’s description on this site.',
            ],
            'source' => [
                'name' => 'source',
                'type' => Type::nonNull(Type::string()),
                'description' => 'How the table was built: `manual`, `import` or `query`.',
            ],
            'translatable' => [
                'name' => 'translatable',
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'Whether each site has its own grid.',
            ],
            'rowCount' => [
                'name' => 'rowCount',
                'type' => Type::nonNull(Type::int()),
                'description' => 'Rows in this site’s grid.',
            ],
            'colCount' => [
                'name' => 'colCount',
                'type' => Type::nonNull(Type::int()),
                'description' => 'Columns in this site’s grid.',
            ],
            'lastRefreshedAt' => [
                'name' => 'lastRefreshedAt',
                'type' => DateTime::getType(),
                'description' => 'When a query-backed table was last rebuilt.',
            ],
            'embedCode' => [
                'name' => 'embedCode',
                'type' => Type::nonNull(Type::string()),
                'description' => 'The reference tag that embeds this table in rich text.',
            ],
        ], Presentation::fieldDefinitions()), self::getName());
    }
}
