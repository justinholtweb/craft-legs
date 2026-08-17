<?php

namespace justinholtweb\legs\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\legs\elements\Table;

/**
 * Two tables, split the way Craft splits `elements` and `elements_sites`.
 *
 * `legs_tables` holds what a table *is* — its handle, where it comes from, how it is presented.
 * `legs_table_content` holds what it *says*, per site, because a table on a Spanish site is the
 * same table with different words in it.
 *
 * The grid lives in one JSON column rather than a row per cell: tables are small, always read
 * whole, and never queried cell-wise, so a cell table would buy nothing and cost a join on every
 * render.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%legs_tables}}', [
            'id' => $this->integer()->notNull(),
            'handle' => $this->string()->notNull(),
            'options' => $this->text(),
            'source' => $this->string(32)->notNull()->defaultValue(Table::SOURCE_MANUAL),
            'sourceConfig' => $this->text(),
            'translationMethod' => $this->string(16)->notNull()->defaultValue(Table::TRANSLATION_NONE),
            // Denormalised purely so the element index can sort by size without decoding every
            // grid on the page. The per-site truth is whatever that site's grid says.
            'rowCount' => $this->integer()->notNull()->defaultValue(0),
            'colCount' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createIndex(null, '{{%legs_tables}}', ['handle'], true);
        $this->createIndex(null, '{{%legs_tables}}', ['source'], false);
        $this->addForeignKey(null, '{{%legs_tables}}', ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);

        $this->createTable('{{%legs_table_content}}', [
            'id' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'caption' => $this->text(),
            'description' => $this->text(),
            'data' => $this->mediumText(),
            'lastRefreshedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]], [[siteId]])',
        ]);

        $this->createIndex(null, '{{%legs_table_content}}', ['siteId'], false);
        $this->addForeignKey(null, '{{%legs_table_content}}', ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%legs_table_content}}', ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', 'CASCADE');

        return true;
    }

    public function safeDown(): bool
    {
        // Delete the elements first: the CASCADE goes the other way, so dropping the tables alone
        // would leave rows in `elements` pointing at an element type that no longer exists.
        $this->delete(CraftTable::ELEMENTS, ['type' => Table::class]);
        $this->dropTableIfExists('{{%legs_table_content}}');
        $this->dropTableIfExists('{{%legs_tables}}');

        return true;
    }
}
