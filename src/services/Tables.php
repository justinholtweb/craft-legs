<?php

namespace justinholtweb\legs\services;

use Craft;
use craft\base\Component;
use craft\helpers\ElementHelper;
use justinholtweb\legs\elements\db\TableQuery;
use justinholtweb\legs\elements\Table;
use justinholtweb\legs\models\Edition;
use justinholtweb\legs\Plugin;
use Throwable;

/**
 * The library: everything that reads or writes tables as a set rather than one at a time.
 */
class Tables extends Component
{
    /**
     * All three getters take a site, because a table's words are per-site: asking for "the prices
     * table" without saying where always means the site you are currently rendering.
     */
    public function getTableById(int $id, ?int $siteId = null): ?Table
    {
        $table = Craft::$app->getElements()->getElementById($id, Table::class, $siteId);

        return $table instanceof Table ? $table : $this->findAnywhere(Table::find()->id($id), $siteId);
    }

    public function getTableByHandle(string $handle, ?int $siteId = null): ?Table
    {
        // A null site means the current one, which is what an unqualified "the prices table"
        // means on a page that is being rendered.
        /** @var Table|null $table */
        $table = Table::find()->handle($handle)->siteId($siteId)->status(null)->one();

        return $table ?? $this->findAnywhere(Table::find()->handle($handle), $siteId);
    }

    /**
     * Aims a loaded table at a particular site.
     *
     * {@see self::getTableById()} falls back to another site when the requested one has no row
     * yet — a site added after the table was created, whose resave jobs are still queued. That is
     * the right answer for reading and the wrong one for writing: without this, a save would land
     * on whichever site answered and quietly overwrite *that* site's grid. The three assignments
     * are the ones Craft makes when it propagates an element to a site for the first time.
     */
    public function pointAtSite(Table $table, int $siteId): void
    {
        if (!$table->id || $table->siteId === $siteId) {
            return;
        }

        $table->siteId = $siteId;
        $table->siteSettingsId = null;
        $table->isNewForSite = true;
    }

    /**
     * Finds a table that exists, but not on the site that was asked for.
     *
     * This happens for real: add a second site to an install and every table created before it
     * has no row in `elements_sites` for the new one until Craft's resave jobs have run. Without
     * this, every embed on the new site renders nothing and the control panel 404s — a queue
     * backlog should not look like missing content.
     */
    private function findAnywhere(TableQuery $query, ?int $siteId): ?Table
    {
        $preferred = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;

        /** @var Table|null $table */
        $table = $query
            ->siteId('*')
            ->preferSites([$preferred])
            ->unique()
            ->status(null)
            ->one();

        return $table;
    }

    /** @return Table[] */
    public function getAllTables(?int $siteId = null): array
    {
        /** @var Table[] $tables */
        $tables = Table::find()->siteId($siteId)->status(null)->all();

        return $tables;
    }

    public function count(): int
    {
        return (int)Table::find()->status(null)->count();
    }

    /**
     * How many more tables this edition allows, or null for unlimited.
     *
     * Checked before the editor is opened as well as before a save, so an author on Lite is told
     * where the wall is rather than filling in a grid and losing it.
     */
    public function remaining(): ?int
    {
        $max = Edition::maxTables(Plugin::getInstance()->isPro());

        return $max === null ? null : max(0, $max - $this->count());
    }

    public function canCreate(): bool
    {
        $remaining = $this->remaining();

        return $remaining === null || $remaining > 0;
    }

    /**
     * @throws Throwable
     */
    public function saveTable(Table $table, bool $runValidation = true): bool
    {
        if (!$table->id && !$this->canCreate()) {
            $table->addError('title', Craft::t('legs', 'Legs Lite holds up to {max} tables. Upgrade to Pro for unlimited tables.', [
                'max' => Edition::LITE_MAX_TABLES,
            ]));

            return false;
        }

        if (!$table->title) {
            $table->title = Craft::t('legs', 'Untitled table');
        }

        if (!Plugin::getInstance()->isPro()) {
            // Lite cannot render merges, and storing them would mean a table that looks one way
            // in the editor and another on the page.
            $table->getData()->merges = [];
        }

        return Craft::$app->getElements()->saveElement($table, $runValidation);
    }

    /**
     * @throws Throwable
     */
    public function deleteTable(Table $table): bool
    {
        return Craft::$app->getElements()->deleteElement($table);
    }

    /**
     * Copies a table, including its grid, under a handle nothing else is using.
     *
     * @throws Throwable
     */
    public function duplicate(Table $table): ?Table
    {
        /** @var Table $copy */
        $copy = Craft::$app->getElements()->duplicateElement($table, [
            'title' => Craft::t('legs', '{title} copy', ['title' => $table->title]),
            'handle' => null,
        ]);

        return $copy;
    }

    /** Whether a live table other than `$exceptId` is using this handle. */
    public function handleIsTaken(string $handle, ?int $exceptId = null): bool
    {
        $query = Table::find()->handle($handle)->status(null);

        if ($exceptId) {
            $query->id(['not', $exceptId]);
        }

        return $query->exists();
    }

    /** Suggests a handle from a title that nothing has taken yet. */
    public function uniqueHandle(string $base, ?int $exceptId = null): string
    {
        $base = ElementHelper::normalizeSlug($base) ?: 'table';
        $handle = $base;
        $suffix = 1;

        while ($this->handleIsTaken($handle, $exceptId)) {
            $handle = $base . '-' . (++$suffix);
        }

        return $handle;
    }
}
