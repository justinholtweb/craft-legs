<?php

namespace justinholtweb\legs\twig;

use justinholtweb\legs\elements\Table;
use justinholtweb\legs\models\InlineTable;
use justinholtweb\legs\Plugin;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The `legs` filter and function.
 *
 * `{{ 'prices'|legs }}`, `{{ entry.spec|legs }}`, `{{ legs('prices', { paginate: true }) }}` —
 * all three land on the same renderer, so a template author never has to know whether they are
 * holding a handle, an element or an inline grid.
 */
class Extension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('legs', [$this, 'render'], ['is_safe' => ['html']]),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('legs', [$this, 'render'], ['is_safe' => ['html']]),
        ];
    }

    public function render(mixed $value, array $options = []): Markup
    {
        if ($value instanceof Table || $value instanceof InlineTable) {
            return $value->render($options);
        }

        if (is_string($value) || is_int($value)) {
            $table = is_numeric($value)
                ? Plugin::getInstance()->tables->getTableById((int)$value)
                : Plugin::getInstance()->tables->getTableByHandle((string)$value);

            if ($table) {
                return $table->render($options);
            }
        }

        return new Markup('', 'UTF-8');
    }
}
