<?php

namespace justinholtweb\legs\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $handle
 * @property string|null $caption
 * @property string|null $description
 * @property string|null $data
 * @property string|null $options
 * @property string $source
 * @property string|null $sourceConfig
 * @property int $rowCount
 * @property int $colCount
 * @property string|null $lastRefreshedAt
 */
class TableRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%legs_tables}}';
    }
}
