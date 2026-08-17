<?php

namespace justinholtweb\legs\records;

use craft\db\ActiveRecord;

/**
 * A table's words, for one site.
 *
 * @property int $id
 * @property int $siteId
 * @property string|null $caption
 * @property string|null $description
 * @property string|null $data
 * @property string|null $lastRefreshedAt
 */
class TableContentRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%legs_table_content}}';
    }

    public static function primaryKey(): array
    {
        return ['id', 'siteId'];
    }
}
