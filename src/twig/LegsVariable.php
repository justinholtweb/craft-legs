<?php

namespace justinholtweb\legs\twig;

use justinholtweb\legs\elements\db\TableQuery;
use justinholtweb\legs\elements\Table;
use justinholtweb\legs\Plugin;
use Twig\Markup;

/**
 * `craft.legs` — the template API.
 */
class LegsVariable
{
    /** `craft.legs.table('prices')` — by handle or by id. */
    public function table(string|int|null $handle): ?Table
    {
        if ($handle === null || $handle === '') {
            return null;
        }

        $tables = Plugin::getInstance()->tables;

        return is_numeric($handle)
            ? $tables->getTableById((int)$handle)
            : $tables->getTableByHandle((string)$handle);
    }

    /**
     * `{{ craft.legs.render('prices') }}` — the short way to put a table on a page.
     *
     * Returns an empty string rather than throwing when the handle is wrong: a missing table
     * should cost you a table, not the page.
     */
    public function render(string|int|null $handle, array $options = []): Markup
    {
        $table = $this->table($handle);

        return $table ? $table->render($options) : new Markup('', 'UTF-8');
    }

    /**
     * `craft.legs.tables()` — a normal element query, for listing or filtering.
     *
     * Only one spelling on purpose: a `tables()` method beside a `getTables()` getter is a trap
     * in Twig, which resolves the bare method first.
     */
    public function tables(array $criteria = []): TableQuery
    {
        /** @var TableQuery $query */
        $query = Table::find();

        if ($criteria) {
            \Craft::configure($query, $criteria);
        }

        return $query;
    }

    /** The tag to paste into a rich-text field. */
    public function embedCode(string|int|null $handle): string
    {
        return $this->table($handle)?->getEmbedCode() ?? '';
    }
}
