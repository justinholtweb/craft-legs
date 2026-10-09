<?php

namespace justinholtweb\legs\gql\types;

use craft\gql\types\elements\Element;
use GraphQL\Type\Definition\ResolveInfo;
use justinholtweb\legs\elements\Table;
use justinholtweb\legs\gql\interfaces\TableInterface;
use justinholtweb\legs\gql\resolvers\TableResolver;

/**
 * The GraphQL object type for library tables.
 */
class TableType extends Element
{
    public function __construct(array $config)
    {
        $config['interfaces'] = [
            TableInterface::getType(),
        ];

        parent::__construct($config);
    }

    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        /** @var Table $source */
        return match ($resolveInfo->fieldName) {
            'grid', 'options', 'html' => Presentation::resolve(
                $resolveInfo->fieldName,
                $source->getData(),
                $source->getOptions(),
                $arguments,
                fn(array $overrides) => $source->render($overrides),
            ),
            'translatable' => $source->getIsTranslatable(),
            // Off the loaded grid, not the denormalised counts, so a translated table reports
            // this site's shape — the same choice the element index makes.
            'rowCount' => $source->getData()->getRowCount(),
            'colCount' => $source->getData()->getColCount(),
            'embedCode' => $source->getEmbedCode(),
            default => parent::resolve($source, $arguments, $context, $resolveInfo),
        };
    }

    protected static function elementResolverClass(): ?string
    {
        return TableResolver::class;
    }
}
