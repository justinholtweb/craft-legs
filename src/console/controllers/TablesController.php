<?php

namespace justinholtweb\legs\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\legs\elements\Table;
use justinholtweb\legs\Plugin;
use Throwable;
use yii\console\ExitCode;

/**
 * Tables from the command line — `php craft legs/tables/…`.
 *
 * The refresh action is the one that matters operationally: a site that would rather rebuild its
 * query tables on a schedule than on every element save can turn `refreshOnElementSave` off and
 * run `php craft legs/tables/refresh` from cron.
 */
class TablesController extends Controller
{
    /** @var string|null Only act on this table. */
    public ?string $handle = null;

    /** @var string Format for `export`: csv, json or html. */
    public string $format = 'csv';

    public function options($actionID): array
    {
        return match ($actionID) {
            'refresh' => array_merge(parent::options($actionID), ['handle']),
            'export' => array_merge(parent::options($actionID), ['format']),
            default => parent::options($actionID),
        };
    }

    /**
     * Lists every table in the library.
     */
    public function actionIndex(): int
    {
        $tables = Plugin::getInstance()->tables->getAllTables();

        if (!$tables) {
            $this->stdout("No tables yet.\n");

            return ExitCode::OK;
        }

        foreach ($tables as $table) {
            $this->stdout(sprintf(
                "%-30s %-12s %s\n",
                $table->handle,
                sprintf('%d × %d', $table->rowCount, $table->colCount),
                $table->title,
            ));
        }

        $this->stdout(sprintf("\n%d %s\n", count($tables), count($tables) === 1 ? 'table' : 'tables'));

        return ExitCode::OK;
    }

    /**
     * Rebuilds query-backed tables from their queries.
     */
    public function actionRefresh(): int
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->isPro()) {
            $this->stderr("Tables built from an element query are a Pro feature.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $query = Table::find()->source(Table::SOURCE_QUERY)->status(null);

        if ($this->handle) {
            $query->handle($this->handle);
        }

        /** @var Table[] $tables */
        $tables = $query->all();

        if (!$tables) {
            $this->stdout("Nothing to refresh.\n");

            return ExitCode::OK;
        }

        $failed = 0;

        foreach ($tables as $table) {
            $this->stdout("Refreshing $table->handle … ");

            try {
                if ($plugin->querySource->refresh($table)) {
                    $this->stdout(sprintf("%d rows\n", $table->getData()->getRowCount()), Console::FG_GREEN);
                } else {
                    $failed++;
                    $this->stdout("failed\n", Console::FG_RED);
                }
            } catch (Throwable $e) {
                $failed++;
                $this->stdout("failed: {$e->getMessage()}\n", Console::FG_RED);
            }
        }

        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Prints a table in a portable format.
     *
     * @param string $handle The table to export.
     */
    public function actionExport(string $handle): int
    {
        $plugin = Plugin::getInstance();
        $table = $plugin->tables->getTableByHandle($handle);

        if (!$table) {
            $this->stderr("No table with the handle “$handle”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $this->stdout(match ($this->format) {
            'json' => $plugin->exporter->toJson($table),
            'html' => $plugin->exporter->toHtml($table),
            default => $plugin->exporter->toCsv($table->getData()),
        });

        return ExitCode::OK;
    }

    /**
     * Replaces a table's grid from a file.
     *
     * @param string $handle The table to overwrite.
     * @param string $path The file to read.
     */
    public function actionImport(string $handle, string $path): int
    {
        $plugin = Plugin::getInstance();
        $table = $plugin->tables->getTableByHandle($handle);

        if (!$table) {
            $this->stderr("No table with the handle “$handle”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        if (!is_file($path)) {
            $this->stderr("No file at $path.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        try {
            $data = $plugin->importer->fromFile($path, basename($path));
        } catch (Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $table->setData($data);
        $table->source = Table::SOURCE_IMPORT;

        if (!$plugin->tables->saveTable($table)) {
            $this->stderr(implode("\n", $table->getErrorSummary(true)) . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(sprintf("Imported %d rows into %s.\n", $data->getRowCount(), $handle), Console::FG_GREEN);

        return ExitCode::OK;
    }
}
