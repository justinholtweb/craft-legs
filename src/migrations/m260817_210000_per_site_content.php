<?php

namespace justinholtweb\legs\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\legs\elements\Table;

/**
 * Moves a table's words out of the global row and into a per-site one.
 *
 * Before this, a table existed once, on the primary site. After it, a table exists on every site
 * and can say something different on each — so the migration has two jobs: move the content, and
 * give every existing table a presence on the sites it has just gained. The second is why it
 * resaves: `elements_sites` rows do not appear on their own, and until they do, a table cannot be
 * found from the site whose page is trying to embed it.
 */
class m260817_210000_per_site_content extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%legs_table_content}}')) {
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
        }

        if (!$this->db->columnExists('{{%legs_tables}}', 'translationMethod')) {
            $this->addColumn('{{%legs_tables}}', 'translationMethod', $this->string(16)->notNull()->defaultValue(Table::TRANSLATION_NONE)->after('sourceConfig'));
        }

        // Move the content across, one row per existing table per site, so a table keeps working
        // everywhere the moment the migration finishes.
        if ($this->db->columnExists('{{%legs_tables}}', 'data')) {
            $siteIds = Craft::$app->getSites()->getAllSiteIds();
            $now = Db::prepareDateForDb(new \DateTime());

            $rows = (new Query())
                ->select(['id', 'caption', 'description', 'data', 'lastRefreshedAt'])
                ->from('{{%legs_tables}}')
                ->all($this->db);

            foreach ($rows as $row) {
                foreach ($siteIds as $siteId) {
                    $this->insert('{{%legs_table_content}}', [
                        'id' => $row['id'],
                        'siteId' => $siteId,
                        'caption' => $row['caption'],
                        'description' => $row['description'],
                        'data' => $row['data'],
                        'lastRefreshedAt' => $row['lastRefreshedAt'],
                        'dateCreated' => $now,
                        'dateUpdated' => $now,
                        'uid' => StringHelper::UUID(),
                    ]);
                }
            }

            $this->dropColumn('{{%legs_tables}}', 'data');
            $this->dropColumn('{{%legs_tables}}', 'caption');
            $this->dropColumn('{{%legs_tables}}', 'description');
            $this->dropColumn('{{%legs_tables}}', 'lastRefreshedAt');
        }

        // Give every table a presence on its newly supported sites. Tables are few, so this is
        // cheap; without it, a table has no `elements_sites` row on the second site and simply
        // cannot be found from there.
        if (Craft::$app->getIsMultiSite()) {
            Craft::$app->getElements()->resaveElements(Table::find()->status(null), true);
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m260817_210000_per_site_content cannot be reverted — per-site content would have to be thrown away.\n";

        return false;
    }
}
