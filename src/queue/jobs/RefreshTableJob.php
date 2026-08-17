<?php

namespace justinholtweb\legs\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\legs\Plugin;

/**
 * Rebuilds one query-backed table.
 *
 * Idempotent and cheap to lose: if the job never runs, the table keeps serving its last good
 * grid rather than an empty one, and the next save schedules another.
 */
class RefreshTableJob extends BaseJob
{
    public ?int $tableId = null;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $table = $this->tableId ? $plugin->tables->getTableById($this->tableId) : null;

        if (!$table) {
            return;
        }

        Craft::$app->getCache()->delete("legs:refresh:$this->tableId");

        $plugin->querySource->refresh($table);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('legs', 'Refreshing a Legs table');
    }
}
