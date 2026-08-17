<?php

namespace justinholtweb\legs\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\ElementCollection;
use craft\helpers\Cp;
use craft\helpers\Html;
use justinholtweb\legs\elements\Table;
use justinholtweb\legs\models\InlineTable;
use justinholtweb\legs\models\RenderOptions;
use justinholtweb\legs\models\TableData;
use justinholtweb\legs\Plugin;
use justinholtweb\legs\web\assets\editor\EditorAsset;
use yii\db\Schema;

/**
 * The Legs field.
 *
 * One field type with a mode, rather than two field types, because the question an author is
 * really answering is "is this table shared or is it this entry's own?" — and the answer to that
 * should not change which field they have to go and add.
 *
 * Both modes hand templates something with `.render()`, `.data` and `.options`, so switching a
 * field between them does not rewrite anybody's templates.
 */
class TableField extends Field
{
    public const MODE_INLINE = 'inline';
    public const MODE_REFERENCE = 'reference';

    /** @var string Which kind of table this field holds. */
    public string $mode = self::MODE_INLINE;

    /**
     * @var int How many library tables a reference field may hold. 0 means no limit.
     *
     * One is not merely the default, it changes the value's shape: a field that holds one table
     * gives templates that table, so `entry.spec.render()` reads the way an author expects.
     * Anything else gives an {@see ElementCollection}, because "the first of possibly several"
     * is a different thing to say.
     */
    public int $maxTables = 1;

    /** @var bool Whether authors may change presentation options per entry (inline mode). */
    public bool $showOptions = true;

    /** @var array Render options handed to a new inline table. */
    public array $defaultOptions = [];

    /** @var int Rows a new inline grid starts with. */
    public int $defaultRows = 3;

    /** @var int Columns a new inline grid starts with. */
    public int $defaultCols = 3;

    public static function displayName(): string
    {
        return Craft::t('legs', 'Table');
    }

    public static function icon(): string
    {
        return 'table';
    }

    public static function phpType(): string
    {
        return sprintf('\\%s|\\%s|\\%s|null', InlineTable::class, Table::class, ElementCollection::class);
    }

    public static function dbType(): string
    {
        return Schema::TYPE_TEXT;
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('legs/_field/settings', [
            'field' => $this,
            'options' => RenderOptions::fromArray($this->defaultOptions),
        ]);
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['mode'], 'in', 'range' => [self::MODE_INLINE, self::MODE_REFERENCE]],
            [['defaultRows', 'defaultCols'], 'integer', 'min' => 1, 'max' => 50],
            [['maxTables'], 'integer', 'min' => 0],
            [['showOptions'], 'boolean'],
            [['defaultOptions'], 'safe'],
        ]);
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($this->mode === self::MODE_REFERENCE) {
            // Resolved in the element's own site, so an entry translated into Spanish points at
            // the Spanish version of the table it references.
            return $this->normalizeReference($value, $element?->siteId);
        }

        if ($value instanceof InlineTable) {
            return $value;
        }

        if (is_string($value)) {
            return InlineTable::fromJson($value);
        }

        if (is_array($value)) {
            // The editor posts its grid as a JSON string beside the option fields; everything
            // else (project config, a migration, a seeded fixture) hands over a decoded array.
            if (isset($value['json'])) {
                return new InlineTable(
                    TableData::fromJson($value['json']),
                    isset($value['options'])
                        ? RenderOptions::fromArray($value['options'])
                        : $this->newOptions(),
                    ($value['caption'] ?? null) ?: null,
                );
            }

            return InlineTable::fromArray($value);
        }

        return new InlineTable(TableData::fromArray(null), $this->newOptions());
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($this->mode === self::MODE_REFERENCE) {
            if ($value instanceof Table) {
                return $this->holdsOne() ? $value->id : [$value->id];
            }

            if ($value instanceof ElementCollection) {
                $ids = $value->map(fn(Table $table) => $table->id)->all();

                return $this->holdsOne() ? ($ids[0] ?? null) : array_values($ids);
            }

            return $this->holdsOne() ? null : [];
        }

        return $value instanceof InlineTable ? $value->toArray() : null;
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element = null, bool $inline = false): string
    {
        if ($this->mode === self::MODE_REFERENCE) {
            return $this->referenceInputHtml($value);
        }

        return $this->inlineInputHtml($value);
    }

    public function getElementValidationRules(): array
    {
        return [];
    }

    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        if ($value instanceof InlineTable) {
            return $value->isEmpty();
        }

        if ($value instanceof ElementCollection) {
            return $value->isEmpty();
        }

        return $value === null;
    }

    public function searchKeywords(mixed $value, ElementInterface $element): string
    {
        if ($value instanceof InlineTable) {
            return $value->getSearchKeywords();
        }

        if ($value instanceof Table) {
            return (string)$value->title;
        }

        if ($value instanceof ElementCollection) {
            return $value->map(fn(Table $table) => (string)$table->title)->join(' ');
        }

        return '';
    }

    /**
     * What the element index shows: a size, not a wall of cell text.
     */
    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        if ($value instanceof Table) {
            return Html::encode((string)$value->title);
        }

        if ($value instanceof ElementCollection && $value->isNotEmpty()) {
            return Html::encode($value->map(fn(Table $table) => (string)$table->title)->join(', '));
        }

        if ($value instanceof InlineTable && !$value->isEmpty()) {
            return Craft::t('legs', '{rows} × {cols}', [
                'rows' => $value->data->getRowCount(),
                'cols' => $value->data->getColCount(),
            ]);
        }

        return '';
    }

    /**
     * @return Table|ElementCollection<int, Table>|null
     */
    private function normalizeReference(mixed $value, ?int $siteId = null): mixed
    {
        $tables = $this->resolveTables($value, $siteId);

        if ($this->holdsOne()) {
            return $tables[0] ?? null;
        }

        return ElementCollection::make($tables);
    }

    /**
     * @return list<Table> The referenced tables, in the order they were chosen, with anything
     * that no longer exists quietly dropped — a deleted table should cost you that table, not
     * the page.
     */
    private function resolveTables(mixed $value, ?int $siteId = null): array
    {
        if ($value instanceof Table) {
            return [$value];
        }

        if ($value instanceof ElementCollection) {
            return $value->all();
        }

        // An element select posts an array of ids, even when it only allows one.
        $refs = is_array($value) ? $value : [$value];
        $tables = [];

        foreach ($refs as $ref) {
            if ($ref instanceof Table) {
                $tables[] = $ref;
                continue;
            }

            if ($ref === null || $ref === '' || $ref === false) {
                continue;
            }

            $table = is_numeric($ref)
                ? Plugin::getInstance()->tables->getTableById((int)$ref, $siteId)
                : Plugin::getInstance()->tables->getTableByHandle((string)$ref, $siteId);

            if ($table) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    private function holdsOne(): bool
    {
        return $this->maxTables === 1;
    }

    private function referenceInputHtml(mixed $value): string
    {
        $tables = $this->resolveTables($value);

        return Cp::elementSelectHtml([
            'id' => $this->getInputId(),
            'name' => $this->handle,
            'elementType' => Table::class,
            'elements' => $tables,
            'limit' => $this->maxTables ?: null,
            'single' => $this->holdsOne(),
            'sortable' => !$this->holdsOne(),
            'selectionLabel' => $this->holdsOne()
                ? Craft::t('legs', 'Choose a table')
                : Craft::t('legs', 'Add a table'),
            'sources' => null,
            'criteria' => [],
        ]);
    }

    private function inlineInputHtml(mixed $value): string
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(EditorAsset::class);

        $inline = $value instanceof InlineTable
            ? $value
            : new InlineTable(TableData::fromArray(null), $this->newOptions());

        if ($inline->data->isEmpty() && $inline->data->getRowCount() <= 1) {
            $inline = new InlineTable($this->starterGrid(), $inline->options, $inline->caption);
        }

        return $view->renderTemplate('legs/_field/input', [
            'field' => $this,
            'id' => $this->getInputId(),
            'name' => $this->handle,
            'namespacedId' => $view->namespaceInputId($this->getInputId()),
            'value' => $inline,
            'isPro' => Plugin::getInstance()->isPro(),
        ]);
    }

    private function newOptions(): RenderOptions
    {
        return $this->defaultOptions
            ? RenderOptions::fromArray($this->defaultOptions)
            : Plugin::getInstance()->getSettings()->getDefaultRenderOptions();
    }

    private function starterGrid(): TableData
    {
        $rows = [];

        for ($r = 0; $r < max(1, $this->defaultRows); $r++) {
            $rows[] = array_fill(0, max(1, $this->defaultCols), '');
        }

        return TableData::fromRows($rows, 1);
    }
}
