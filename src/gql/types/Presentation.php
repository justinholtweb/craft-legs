<?php

namespace justinholtweb\legs\gql\types;

use GraphQL\Type\Definition\Type;
use justinholtweb\legs\models\Edition;
use justinholtweb\legs\models\RenderOptions;
use justinholtweb\legs\models\TableData;
use justinholtweb\legs\Plugin;
use Twig\Markup;

/**
 * The three fields a library table and an inline field table share — `grid`, `options` and
 * `html` — defined once, so the two GraphQL types answer the same question the same way, just as
 * `.render()`, `.data` and `.options` do in Twig.
 */
class Presentation
{
    /** @return array<string, array<string, mixed>> */
    public static function fieldDefinitions(): array
    {
        return [
            'grid' => [
                'name' => 'grid',
                'type' => Type::nonNull(GridType::getType()),
                'description' => 'The grid, laid out the way the renderer lays it out: header, body and footer rows, visible columns, merges as spans.',
            ],
            'options' => [
                'name' => 'options',
                'type' => Type::nonNull(OptionsType::getType()),
                'description' => 'The presentation options in force on this site’s edition.',
            ],
            'html' => [
                'name' => 'html',
                'type' => Type::nonNull(Type::string()),
                'args' => [
                    'options' => [
                        'name' => 'options',
                        'type' => Type::string(),
                        'description' => 'Per-call presentation overrides in embed syntax, e.g. `compact,perPage=10,!striped`.',
                    ],
                ],
                'description' => 'The table rendered by the site’s table template. Bring Legs’ CSS and runtime yourself if you want them.',
            ],
        ];
    }

    /**
     * @param callable(array<string, mixed>): Markup $render Renders with per-call overrides.
     */
    public static function resolve(string $fieldName, TableData $data, RenderOptions $options, array $arguments, callable $render): mixed
    {
        return match ($fieldName) {
            'grid' => Plugin::getInstance()->renderer->describe($data, $options),
            'options' => Edition::applyTo($options, Plugin::getInstance()->isPro()),
            'html' => (string)$render(RenderOptions::parseEmbedOptions((string)($arguments['options'] ?? ''))),
            default => null,
        };
    }
}
