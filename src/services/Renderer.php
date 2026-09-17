<?php

namespace justinholtweb\legs\services;

use Craft;
use craft\base\Component;
use craft\helpers\Html;
use craft\helpers\HtmlPurifier;
use craft\web\View;
use justinholtweb\legs\elements\Table;
use justinholtweb\legs\models\ColumnOptions;
use justinholtweb\legs\models\Edition;
use justinholtweb\legs\models\RenderOptions;
use justinholtweb\legs\models\TableData;
use justinholtweb\legs\Plugin;
use Twig\Markup;

/**
 * Turns a grid into markup.
 *
 * The template is deliberately dumb: everything that needs a decision — which rows are headers,
 * which cells a merge swallows, what a column sorts as, what a stacked cell is labelled — is
 * settled here, so a site that overrides `_legs/table.twig` is overriding presentation and
 * cannot accidentally break correctness.
 */
class Renderer extends Component
{
    /** Where a site puts its own copy of the template. */
    public const SITE_TEMPLATE = '_legs/table';

    private int $counter = 0;

    public function render(Table $table, array $overrides = []): Markup
    {
        $options = $this->resolveOptions($table->getOptions(), $overrides);

        return $this->renderData($table->getData(), $options, [
            'table' => $table,
            'caption' => $table->caption,
            'handle' => $table->handle,
        ]);
    }

    /**
     * Renders a grid that has no element behind it — an inline field value.
     */
    public function renderData(TableData $data, RenderOptions $options, array $context = []): Markup
    {
        $plugin = Plugin::getInstance();
        $options = Edition::applyTo($options, $plugin->isPro());
        $data = $data->normalize();

        if ($options->formulas) {
            $data = $plugin->formulas->apply($data);
        }

        $id = $context['id'] ?? sprintf('legs-%s', $context['handle'] ?? ++$this->counter);

        $variables = array_merge([
            'table' => null,
            'caption' => null,
            'handle' => null,
        ], $context, [
            'id' => Html::id($id),
            'data' => $data,
            'options' => $options,
            'head' => $this->rowsFor($data, $data->headerRowIndexes(), $options, true),
            'body' => $this->rowsFor($data, $data->bodyRowIndexes(), $options, false),
            'foot' => $this->rowsFor($data, $data->footerRowIndexes(), $options, false),
            'columns' => $this->columnsFor($data, $options),
            'texts' => $this->texts(),
        ]);

        $this->registerAssets($options);

        return new Markup($this->renderTemplate($variables), Craft::$app->charset);
    }

    /**
     * Site overrides win, and are looked for in site template mode so a site can `include` its
     * own partials from inside them.
     */
    private function renderTemplate(array $variables): string
    {
        $view = Craft::$app->getView();
        $oldMode = $view->getTemplateMode();

        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

            if ($view->doesTemplateExist(self::SITE_TEMPLATE)) {
                return $view->renderTemplate(self::SITE_TEMPLATE, $variables);
            }

            $view->setTemplateMode(View::TEMPLATE_MODE_CP);

            return $view->renderTemplate('legs/_render/table', $variables);
        } finally {
            $view->setTemplateMode($oldMode);
        }
    }

    private function resolveOptions(RenderOptions $options, array $overrides): RenderOptions
    {
        if (!$overrides) {
            return $options;
        }

        return RenderOptions::fromArray(array_merge($options->toArray(), $overrides));
    }

    /**
     * @return list<array> One entry per column: what the runtime and the stack layout need.
     */
    private function columnsFor(TableData $data, RenderOptions $options): array
    {
        $columns = [];

        foreach ($data->columns as $index => $column) {
            $columns[] = [
                'index' => $index,
                'align' => $column->align,
                'width' => $column->width,
                'hidden' => $column->hidden,
                // A hidden column that still reaches the DOM, for search to match on.
                'metadata' => $column->hidden && $column->metadata,
                'class' => $column->class,
                'sortable' => $options->sortable && $column->sortable,
                'sortAs' => $column->sortAs === ColumnOptions::SORT_AUTO
                    ? $this->sniffType($data, $index)
                    : $column->sortAs,
                'label' => $data->columnLabel($index),
            ];
        }

        return $columns;
    }

    /**
     * @param list<int> $indexes
     * @return list<array>
     */
    private function rowsFor(TableData $data, array $indexes, RenderOptions $options, bool $isHeader): array
    {
        $columns = $this->columnsFor($data, $options);
        $rows = [];

        foreach ($indexes as $r) {
            $rowOptions = $data->rows[$r] ?? null;

            if ($rowOptions?->hidden) {
                continue;
            }

            $cells = [];

            for ($c = 0, $colCount = $data->getColCount(); $c < $colCount; $c++) {
                $metadata = $columns[$c]['metadata'] ?? false;

                if ((($columns[$c]['hidden'] ?? false) && !$metadata) || $data->isCovered($r, $c)) {
                    continue;
                }

                $merge = $data->mergeAt($r, $c);
                $raw = $data->cell($r, $c);

                $cells[] = [
                    'html' => $this->cellHtml($raw),
                    'text' => trim(strip_tags($raw)),
                    'col' => $c,
                    // Rendered, but never shown: the template hides it and the runtime reads it.
                    'hidden' => $metadata,
                    'colspan' => $merge?->colspan ?? 1,
                    'rowspan' => $merge?->rowspan ?? 1,
                    'align' => $columns[$c]['align'] ?? ColumnOptions::ALIGN_LEFT,
                    'class' => $columns[$c]['class'] ?? null,
                    'label' => $isHeader ? null : ($columns[$c]['label'] ?? ''),
                    // Precomputed so the browser never has to guess what "1.10" or "£1,200" means.
                    'sortValue' => $isHeader ? null : $this->sortValue($raw, $columns[$c]['sortAs'] ?? ColumnOptions::SORT_TEXT),
                ];
            }

            $rows[] = [
                'index' => $r,
                'class' => $rowOptions?->class,
                'cells' => $cells,
            ];
        }

        return $rows;
    }

    /**
     * Cell markup, purified when HTML is allowed and escaped when it is not.
     *
     * Purifying on the way *out* as well as on the way in costs little and means a table
     * imported before the setting was turned off cannot start emitting markup when it is.
     */
    private function cellHtml(string $raw): string
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->allowHtmlInCells) {
            return nl2br(Html::encode($raw));
        }

        if ($raw === '' || !str_contains($raw, '<')) {
            return nl2br(Html::encode($raw));
        }

        return HtmlPurifier::process($raw, Plugin::getInstance()->purifierConfig());
    }

    /**
     * Decides what a column of values really is, by looking at the values rather than at the
     * header. A column of "1.10" and "1.9" must not sort as strings; "10 apples" must not sort
     * as numbers.
     */
    private function sniffType(TableData $data, int $col): string
    {
        $numbers = 0;
        $dates = 0;
        $seen = 0;

        foreach ($data->bodyRowIndexes() as $r) {
            $value = trim(strip_tags($data->cell($r, $col)));

            if ($value === '') {
                continue;
            }

            $seen++;

            if ($this->toNumber($value) !== null) {
                $numbers++;
            } elseif ($this->toTimestamp($value) !== null) {
                $dates++;
            }

            if ($seen >= 50) {
                break;
            }
        }

        if ($seen === 0) {
            return ColumnOptions::SORT_TEXT;
        }

        if ($numbers === $seen) {
            return ColumnOptions::SORT_NUMBER;
        }

        if ($dates === $seen) {
            return ColumnOptions::SORT_DATE;
        }

        return ColumnOptions::SORT_TEXT;
    }

    private function sortValue(string $raw, string $sortAs): ?string
    {
        $value = trim(strip_tags($raw));

        if ($value === '') {
            return null;
        }

        return match ($sortAs) {
            ColumnOptions::SORT_NUMBER => ($number = $this->toNumber($value)) !== null ? (string)$number : null,
            ColumnOptions::SORT_DATE => ($timestamp = $this->toTimestamp($value)) !== null ? (string)$timestamp : null,
            default => null,
        };
    }

    /**
     * Reads the number out of a formatted cell — currency symbols, thousands separators,
     * trailing percent signs, and parenthesised negatives all count.
     *
     * A written date is *not* a number, and has to be refused here rather than merely ordered
     * after the date test: stripping the decoration out of "May 19, 2026" leaves "19,2026",
     * which reads as a perfectly plausible 19.2026 and sorts a date column by day of month.
     */
    private function toNumber(string $value): ?float
    {
        $value = trim($value);

        if ($this->looksLikeDate($value)) {
            return null;
        }

        $negative = (bool)preg_match('/^\((.*)\)$/', $value, $matches);

        if ($negative) {
            $value = $matches[1];
        }

        $cleaned = preg_replace('/[^\d,.\-+]/u', '', $value);

        if ($cleaned === '' || !preg_match('/\d/', $cleaned)) {
            return null;
        }

        // 1.234,56 (European) vs 1,234.56 — whichever separator comes last is the decimal one.
        $lastComma = strrpos($cleaned, ',');
        $lastDot = strrpos($cleaned, '.');

        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $cleaned = str_replace('.', '', $cleaned);
            $cleaned = str_replace(',', '.', $cleaned);
        } else {
            $cleaned = str_replace(',', '', $cleaned);
        }

        if (!is_numeric($cleaned)) {
            return null;
        }

        return (float)$cleaned * ($negative ? -1 : 1);
    }

    /**
     * Whether a value is date-shaped: a month name, or three separated numbers.
     *
     * Deliberately narrow — it only has to catch what `toNumber()` would otherwise mangle, so
     * "3/4" stays a number and "1.234.567" stays whatever it was.
     */
    private function looksLikeDate(string $value): bool
    {
        $months = 'jan(uary)?|feb(ruary)?|mar(ch)?|apr(il)?|may|jun(e)?|jul(y)?|aug(ust)?'
            . '|sep(t)?(ember)?|oct(ober)?|nov(ember)?|dec(ember)?';

        return (bool)preg_match('/\b(' . $months . ')\b/i', $value)
            || (bool)preg_match('/\d{1,4}[\/.-]\d{1,2}[\/.-]\d{1,4}/', $value);
    }

    private function toTimestamp(string $value): ?int
    {
        // `strtotime` says yes to far too much — "1" is a time, "March" is a date. Require
        // something that at least looks like a written date first.
        if (!preg_match('/\d{4}|\d{1,2}[\/.-]\d{1,2}/', $value)) {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    private function registerAssets(RenderOptions $options): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $view = Craft::$app->getView();
        $assetManager = Craft::$app->getAssetManager();
        $path = Craft::getAlias('@justinholtweb/legs/web/assets/table/dist');

        if ($settings->registerCss) {
            $view->registerCssFile($assetManager->getPublishedUrl($path, true, 'legs.css'));
        }

        if ($settings->registerJs && $options->needsRuntime()) {
            $view->registerJsFile($assetManager->getPublishedUrl($path, true, 'legs.js'), [
                'type' => 'module',
            ]);
        }
    }

    /** Strings the runtime injects, translated server-side so the JS carries no dictionary. */
    private function texts(): array
    {
        return [
            'search' => Craft::t('legs', 'Search this table'),
            'searchPlaceholder' => Craft::t('legs', 'Search…'),
            'noResults' => Craft::t('legs', 'No matching rows'),
            'showing' => Craft::t('legs', 'Showing {from}–{to} of {total}'),
            'previous' => Craft::t('legs', 'Previous'),
            'next' => Craft::t('legs', 'Next'),
            'page' => Craft::t('legs', 'Page {page} of {pages}'),
            'sortBy' => Craft::t('legs', 'Sort by {column}'),
        ];
    }
}
