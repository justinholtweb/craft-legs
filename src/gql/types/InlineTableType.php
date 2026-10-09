<?php

namespace justinholtweb\legs\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use justinholtweb\legs\models\InlineTable;

/**
 * The value of a Legs field in inline mode: a grid that belongs to the element it is on.
 *
 * No schema component of its own — it is part of the element's content, so whoever may read the
 * element may read it, exactly as with any other field.
 */
class InlineTableType
{
    public const NAME = 'LegsInlineTable';

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::NAME, fn() => new ObjectType([
            'name' => self::NAME,
            'description' => 'A table that belongs to the element it is on.',
            'fields' => fn() => array_merge([
                'caption' => [
                    'name' => 'caption',
                    'type' => Type::string(),
                    'description' => 'The table’s caption.',
                ],
            ], Presentation::fieldDefinitions()),
            'resolveField' => function(InlineTable $source, array $arguments, mixed $context, ResolveInfo $resolveInfo) {
                if ($resolveInfo->fieldName === 'caption') {
                    return $source->caption;
                }

                return Presentation::resolve(
                    $resolveInfo->fieldName,
                    $source->data,
                    $source->options,
                    $arguments,
                    fn(array $overrides) => $source->render($overrides),
                );
            },
        ]));
    }
}
