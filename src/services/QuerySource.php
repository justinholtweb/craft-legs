<?php

namespace justinholtweb\legs\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\events\ElementEvent;
use craft\helpers\ElementHelper;
use craft\services\Elements;
use DateTime;
use justinholtweb\legs\elements\Table;
use justinholtweb\legs\models\TableData;
use justinholtweb\legs\Plugin;
use justinholtweb\legs\queue\jobs\RefreshTableJob;
use Throwable;
use yii\base\Event;

/**
 * Tables built from an element query — the thing TablePress cannot do, because WordPress has no
 * equivalent of one.
 *
 * The grid is *materialised*, not resolved at render: a table on a busy page must not run an
 * element query per request, and a query table must render identically to a hand-typed one
 * (same cache behaviour, same export, same embed). Freshness is kept by rebuilding on a
 * debounce when a matching element is saved, which is cheap and — unlike a render-time query —
 * happens once per edit rather than once per visitor.
 */
class QuerySource extends Component
{
    public const TYPE_ATTRIBUTE = 'attribute';
    public const TYPE_FIELD = 'field';
    public const TYPE_TEMPLATE = 'template';

    /** How long a save waits for its neighbours before a rebuild is queued. */
    private const DEBOUNCE_SECONDS = 30;

    public function register(): void
    {
        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $event) {
            $this->onElementChanged($event->element);
        });

        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, function(ElementEvent $event) {
            $this->onElementChanged($event->element);
        });
    }

    /**
     * Rebuilds a table from its query and saves it.
     *
     * @throws Throwable
     */
    public function refresh(Table $table): bool
    {
        if ($table->source !== Table::SOURCE_QUERY) {
            return false;
        }

        $config = $table->getSourceConfig() ?? [];
        $plugin = Plugin::getInstance();

        // A translated query table is a different query per site — the entries in a Spanish
        // section are not the entries in an English one — so each site is built and saved on its
        // own. A shared one is built once from the site it is being refreshed in and copied
        // everywhere by the save itself.
        if (!$table->getIsTranslatable()) {
            $table->setData($this->build($config, $table->siteId));
            $table->lastRefreshedAt = new DateTime();

            return $plugin->tables->saveTable($table, false);
        }

        $saved = true;

        foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
            $siteTable = $plugin->tables->getTableById($table->id, $siteId);

            if (!$siteTable) {
                continue;
            }

            $siteTable->setData($this->build($config, $siteId));
            $siteTable->lastRefreshedAt = new DateTime();
            $saved = $plugin->tables->saveTable($siteTable, false) && $saved;
        }

        return $saved;
    }

    /**
     * Runs the configured query and lays the results out as a grid.
     */
    public function build(array $config, ?int $siteId = null): TableData
    {
        $elementType = $config['elementType'] ?? Entry::class;

        if (!is_subclass_of($elementType, ElementInterface::class)) {
            return TableData::fromArray(null);
        }

        $columns = array_values(array_filter($config['columns'] ?? [], static fn($column) => is_array($column) && ($column['value'] ?? '') !== ''));

        if (!$columns) {
            return TableData::fromArray(null);
        }

        $query = Craft::$app->getElements()->createElementQuery($elementType);
        Craft::configure($query, $this->criteria($config));

        // Set last, so a stored `siteId` in the criteria cannot quietly make every site's copy
        // of a translated table show the same site's content.
        if ($siteId !== null) {
            $query->siteId($siteId);
        }

        $rows = [];
        $includeHeader = (bool)($config['includeHeader'] ?? true);

        if ($includeHeader) {
            $rows[] = array_map(static fn(array $column) => (string)($column['label'] ?? $column['value']), $columns);
        }

        foreach ($query->all() as $element) {
            $row = [];

            foreach ($columns as $column) {
                $row[] = $this->columnValue($element, $column);
            }

            $rows[] = $row;
        }

        return TableData::fromRows($rows, $includeHeader ? 1 : 0);
    }

    /**
     * Only criteria a table has any business setting.
     *
     * An allow-list rather than a pass-through: `Craft::configure()` will happily set any public
     * property on the query, and a stored config is not a place to accept arbitrary ones.
     */
    private function criteria(array $config): array
    {
        $criteria = is_array($config['criteria'] ?? null) ? $config['criteria'] : [];
        $allowed = [
            'section', 'sectionId', 'type', 'typeId', 'group', 'groupId', 'volume', 'volumeId',
            'kind', 'authorId', 'status', 'search', 'relatedTo', 'level', 'descendantOf',
            'ancestorOf', 'orderBy', 'limit', 'offset', 'site', 'siteId', 'slug', 'id',
            'postDate', 'expiryDate', 'title', 'uri', 'hasDescendants', 'editable',
        ];

        $criteria = array_intersect_key($criteria, array_flip($allowed));

        // A query table with no limit is a table that can grow to 40,000 rows without anyone
        // deciding it should. Cap it, loudly, in the CP rather than silently here.
        $criteria['limit'] = min(5000, max(1, (int)($criteria['limit'] ?? 500)));

        return $criteria;
    }

    private function columnValue(ElementInterface $element, array $column): string
    {
        $value = $column['value'] ?? '';

        try {
            return match ($column['type'] ?? self::TYPE_ATTRIBUTE) {
                // Craft's object templates call the subject `object`; `element` is passed as
                // well because that is what anyone writing one of these will type first.
                self::TYPE_TEMPLATE => (string)Craft::$app->getView()->renderObjectTemplate($value, $element, ['element' => $element]),
                self::TYPE_FIELD => $this->stringify($element->getFieldValue($value)),
                default => $this->stringify($element->$value ?? ''),
            };
        } catch (Throwable $e) {
            Craft::warning(sprintf('Legs could not read “%s” from element %s: %s', $value, $element->id, $e->getMessage()), Plugin::LOG_CATEGORY);

            return '';
        }
    }

    private function stringify(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        if ($value instanceof DateTime) {
            return Craft::$app->getFormatter()->asDate($value, 'medium');
        }

        if ($value instanceof ElementInterface) {
            return (string)$value->getUiLabel();
        }

        if (is_iterable($value) && !is_string($value)) {
            $parts = [];
            foreach ($value as $item) {
                $parts[] = $item instanceof ElementInterface ? $item->getUiLabel() : (is_scalar($item) ? (string)$item : '');
            }

            return implode(', ', array_filter($parts));
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * Queues a rebuild of every query table that could contain this element.
     *
     * Deliberately coarse: matching on element type alone rather than trying to work out whether
     * *this* element would have matched *that* criteria. Getting that wrong in the strict
     * direction means a table that quietly stops updating, which is far worse than an occasional
     * unnecessary rebuild of a 500-row query.
     */
    private function onElementChanged(ElementInterface $element): void
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->isPro() || !$plugin->getSettings()->refreshOnElementSave) {
            return;
        }

        if ($element instanceof Table || ElementHelper::isDraftOrRevision($element)) {
            return;
        }

        /** @var Table[] $tables */
        $tables = Table::find()->source(Table::SOURCE_QUERY)->status(null)->all();

        foreach ($tables as $table) {
            $config = $table->getSourceConfig() ?? [];
            $elementType = $config['elementType'] ?? Entry::class;

            if (!$element instanceof $elementType) {
                continue;
            }

            $this->scheduleRefresh($table);
        }
    }

    /**
     * One rebuild per burst of edits: a resave of 400 entries must not queue 400 jobs.
     */
    private function scheduleRefresh(Table $table): void
    {
        $cache = Craft::$app->getCache();
        $key = "legs:refresh:$table->id";

        if ($cache->get($key)) {
            return;
        }

        $cache->set($key, true, self::DEBOUNCE_SECONDS * 2);

        Craft::$app->getQueue()->delay(self::DEBOUNCE_SECONDS)->push(new RefreshTableJob([
            'tableId' => $table->id,
        ]));
    }
}
