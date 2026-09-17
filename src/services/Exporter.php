<?php

namespace justinholtweb\legs\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use justinholtweb\legs\elements\Table;
use justinholtweb\legs\models\TableData;
use justinholtweb\legs\Plugin;
use RuntimeException;

/**
 * Grids back out again.
 *
 * Export is deliberately lossy in the formats that are lossy — a CSV has nowhere to put a merge
 * or a column width — and lossless in the one that is not: the JSON export is the same document
 * the importer reads back, so a table can be moved between sites whole.
 */
class Exporter extends Component
{
    public function toCsv(TableData $data, string $delimiter = ','): string
    {
        $handle = fopen('php://temp', 'r+');

        foreach ($data->cells as $row) {
            fputcsv($handle, array_map(static fn(string $cell) => trim(strip_tags($cell)), $row), $delimiter, '"', '\\');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** The round-trip format: everything the grid knows, in the shape the importer reads. */
    public function toJson(Table $table): string
    {
        return json_encode([
            'title' => $table->title,
            'handle' => $table->handle,
            'caption' => $table->caption,
            'description' => $table->description,
            'options' => $table->getOptions()->toArray(),
        ] + $table->getData()->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Standalone markup, rendered by the same renderer the front end uses. */
    public function toHtml(Table $table): string
    {
        return (string)Plugin::getInstance()->renderer->render($table);
    }

    /**
     * @throws RuntimeException
     */
    public function toXlsx(Table $table, string $path): void
    {
        if (!Plugin::getInstance()->isPro()) {
            throw new RuntimeException(Craft::t('legs', 'Spreadsheet export is a Pro feature.'));
        }

        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            throw new RuntimeException(Craft::t('legs', 'Spreadsheet export needs PhpSpreadsheet. Run `composer require phpoffice/phpspreadsheet` and try again.'));
        }

        $data = $table->getData();
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $worksheet = $spreadsheet->getActiveSheet();
        $worksheet->setTitle(mb_substr($table->title ?: 'Table', 0, 31));

        foreach ($data->cells as $r => $row) {
            foreach ($row as $c => $cell) {
                // setCellValueExplicit as string: an author's "007" or "1-2" is a label, and a
                // spreadsheet left to guess will turn both into something else.
                $worksheet->setCellValueExplicit(
                    [$c + 1, $r + 1],
                    trim(strip_tags($cell)),
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
                );
            }
        }

        foreach ($data->merges as $merge) {
            $worksheet->mergeCells([
                $merge->col + 1,
                $merge->row + 1,
                $merge->col + $merge->colspan,
                $merge->row + $merge->rowspan,
            ]);
        }

        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
    }

    /**
     * The same spreadsheet as a string, for the two controllers that have to send one.
     *
     * PhpSpreadsheet's writer only writes to a path, so somebody has to do the temp-file dance;
     * doing it here means neither controller has to own a `finally` block.
     *
     * @throws RuntimeException
     */
    public function xlsxContents(Table $table): string
    {
        $path = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . uniqid('legs', true) . '.xlsx';

        try {
            $this->toXlsx($table, $path);

            return (string)file_get_contents($path);
        } finally {
            if (is_file($path)) {
                FileHelper::unlink($path);
            }
        }
    }

    public function filename(Table $table, string $extension): string
    {
        return sprintf('%s.%s', $table->handle ?: 'table', $extension);
    }
}
