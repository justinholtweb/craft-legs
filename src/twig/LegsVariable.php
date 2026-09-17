<?php

namespace justinholtweb\legs\twig;

use craft\helpers\UrlHelper;
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

    // Export
    // -------------------------------------------------------------------------
    //
    // The serialisers, straight: a template that asks for a table's CSV has already decided the
    // visitor may have it, so these are not gated on `downloadable` the way the route is. They
    // return a string, not a download — set the headers yourself, or link to
    // {@see self::exportUrl()} and let Legs send the file.

    /** `{{ craft.legs.csv('prices') }}` — the grid as CSV, or an empty string. */
    public function csv(string|int|null $handle, string $delimiter = ','): string
    {
        $table = $this->table($handle);

        return $table ? Plugin::getInstance()->exporter->toCsv($table->getData(), $delimiter) : '';
    }

    /** The round-trip format: options, merges and column settings as well as the cells. */
    public function json(string|int|null $handle): string
    {
        $table = $this->table($handle);

        return $table ? Plugin::getInstance()->exporter->toJson($table) : '';
    }

    /** The rendered table as a standalone HTML string. */
    public function html(string|int|null $handle): string
    {
        $table = $this->table($handle);

        return $table ? Plugin::getInstance()->exporter->toHtml($table) : '';
    }

    /**
     * `{{ craft.legs.exportUrl('prices', 'csv') }}` — the download link for a table.
     *
     * Returns null rather than a dead link when the table is missing, when its author has not
     * ticked `downloadable`, or when the site has turned the route off, so a template can write
     * `{% if url %}` and get a button that only appears when it would work.
     */
    public function exportUrl(string|int|null $handle, string $format = 'csv'): ?string
    {
        $table = $this->table($handle);

        if (!$table || !$table->getIsDownloadable()) {
            return null;
        }

        $path = Plugin::getInstance()->getSettings()->normalizedExportPath();

        if ($path === null) {
            return null;
        }

        return UrlHelper::siteUrl(sprintf('%s/%s/%s', $path, $table->handle, $format));
    }
}
