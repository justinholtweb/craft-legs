<?php

namespace justinholtweb\legs\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

/**
 * Element query for tables.
 *
 * The `ref` param is what makes `{legs:my-prices:render}` work: Craft's `parseRefs()` calls
 * `->ref()` with the non-numeric part of the tag and then indexes the results on
 * `$element->ref`, so this and `Table::getRef()` have to name the same column.
 *
 * @method \justinholtweb\legs\elements\Table[] all($db = null)
 * @method \justinholtweb\legs\elements\Table|null one($db = null)
 * @method \justinholtweb\legs\elements\Table|null nth(int $n, ?\yii\db\Connection $db = null)
 */
class TableQuery extends ElementQuery
{
    public mixed $handle = null;
    public mixed $source = null;

    protected array $defaultOrderBy = ['elements_sites.title' => SORT_ASC];

    public function handle(mixed $value): static
    {
        $this->handle = $value;
        return $this;
    }

    public function source(mixed $value): static
    {
        $this->source = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $this->joinElementTable('legs_tables');

        // The per-site half. Joined only into the outer query, which already has `elements_sites`
        // by the time `beforePrepare()` runs — the sub-query gains it *after*, so a join added
        // there would reference an alias that does not exist yet.
        $this->query->leftJoin(
            ['legs_content' => '{{%legs_table_content}}'],
            '[[legs_content.id]] = [[subquery.elementsId]] AND [[legs_content.siteId]] = [[elements_sites.siteId]]',
        );

        $this->query->addSelect([
            'legs_tables.handle',
            'legs_tables.options',
            'legs_tables.source',
            'legs_tables.sourceConfig',
            'legs_tables.translationMethod',
            'legs_tables.rowCount',
            'legs_tables.colCount',
            'legs_content.caption',
            'legs_content.description',
            'legs_content.data',
            'legs_content.lastRefreshedAt',
        ]);

        if ($this->handle) {
            $this->subQuery->andWhere(Db::parseParam('legs_tables.handle', $this->handle));
        }

        if ($this->source) {
            $this->subQuery->andWhere(Db::parseParam('legs_tables.source', $this->source));
        }

        if ($this->ref) {
            $this->subQuery->andWhere(Db::parseParam('legs_tables.handle', $this->ref));
        }

        return true;
    }
}
