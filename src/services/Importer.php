<?php

namespace justinholtweb\legs\services;

use Craft;
use craft\base\Component;
use craft\helpers\StringHelper;
use DOMDocument;
use DOMElement;
use DOMXPath;
use justinholtweb\legs\models\CellMerge;
use justinholtweb\legs\models\TableData;
use justinholtweb\legs\Plugin;
use RuntimeException;

/**
 * Everything that turns somebody else's data into a grid.
 *
 * All four importers converge on {@see TableData::fromRows()}, so an imported table is
 * indistinguishable from a hand-typed one the moment it lands — there is no "imported table"
 * code path downstream to keep in step.
 */
class Importer extends Component
{
    public const FORMAT_CSV = 'csv';
    public const FORMAT_JSON = 'json';
    public const FORMAT_HTML = 'html';
    public const FORMAT_XLSX = 'xlsx';

    /**
     * Reads an uploaded file, picking the importer from its extension.
     *
     * @throws RuntimeException
     */
    public function fromFile(string $path, string $filename, int $headerRows = 1): TableData
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv', 'tsv', 'txt' => $this->fromCsv(file_get_contents($path), $headerRows),
            'json' => $this->fromJson(file_get_contents($path), $headerRows),
            'html', 'htm' => $this->fromHtml(file_get_contents($path), $headerRows),
            'xlsx', 'xls', 'ods' => $this->fromSpreadsheet($path, $headerRows),
            default => throw new RuntimeException(Craft::t('legs', 'Legs cannot read “{ext}” files.', ['ext' => $extension])),
        };
    }

    /**
     * CSV, TSV, or anything else delimited — the delimiter is sniffed rather than asked for,
     * because an author pasting from Excel does not know or care which one they have.
     */
    public function fromCsv(string $contents, int $headerRows = 1, ?string $delimiter = null): TableData
    {
        $contents = $this->normalizeText($contents);
        $delimiter ??= $this->sniffDelimiter($contents);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            // fgetcsv reports a blank line as [null]; a table full of those is not a table.
            if ($row === [null]) {
                continue;
            }

            $rows[] = array_map(static fn($cell) => trim((string)$cell), $row);
        }

        fclose($handle);

        return TableData::fromRows($rows, $headerRows);
    }

    /**
     * JSON in either of the two shapes people actually have: a list of lists (a grid), or a list
     * of objects (records), where the keys of the first object become the header row.
     */
    public function fromJson(string $contents, int $headerRows = 1): TableData
    {
        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            throw new RuntimeException(Craft::t('legs', 'That file is not valid JSON.'));
        }

        // A full Legs export round-trips with its options and merges intact.
        if (isset($decoded['cells']) && is_array($decoded['cells'])) {
            return TableData::fromArray($decoded);
        }

        $first = reset($decoded);

        if (is_array($first) && !array_is_list($first)) {
            $columns = array_keys($first);
            $rows = [$columns];

            foreach ($decoded as $record) {
                $row = [];
                foreach ($columns as $column) {
                    $value = is_array($record) ? ($record[$column] ?? '') : '';
                    $row[] = is_scalar($value) ? (string)$value : json_encode($value);
                }
                $rows[] = $row;
            }

            return TableData::fromRows($rows, max(1, $headerRows));
        }

        return TableData::fromRows(array_map(
            static fn($row) => array_map(static fn($cell) => is_scalar($cell) ? (string)$cell : '', (array)$row),
            $decoded,
        ), $headerRows);
    }

    /**
     * Parses the first `<table>` out of a fragment — a page saved from a browser, a paste from
     * Word or Google Docs, or markup an author copied out of a competitor's site.
     *
     * `colspan`/`rowspan` become real merges rather than being flattened, which means the grid
     * has to be laid out into a rectangle first: a spanned cell occupies positions its own row
     * never mentions.
     */
    public function fromHtml(string $contents, int $headerRows = 1): TableData
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $contents, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $table = $xpath->query('//table')->item(0);

        if (!$table instanceof DOMElement) {
            throw new RuntimeException(Craft::t('legs', 'No table found in that markup.'));
        }

        $grid = [];
        $merges = [];
        $headCount = 0;
        $footCount = 0;
        $r = 0;

        foreach ($xpath->query('.//tr', $table) as $tr) {
            /** @var DOMElement $tr */
            $section = strtolower($tr->parentNode?->nodeName ?? '');
            $cells = $xpath->query('./th|./td', $tr);
            $c = 0;

            foreach ($cells as $cell) {
                /** @var DOMElement $cell */
                while (isset($grid[$r][$c])) {
                    $c++;
                }

                $colspan = max(1, (int)($cell->getAttribute('colspan') ?: 1));
                $rowspan = max(1, (int)($cell->getAttribute('rowspan') ?: 1));

                $grid[$r][$c] = $this->cellContents($cell);

                if ($colspan > 1 || $rowspan > 1) {
                    $merges[] = new CellMerge($r, $c, $rowspan, $colspan);

                    // Reserve the covered positions so later cells in this and following rows
                    // land where they visually belong.
                    for ($dr = 0; $dr < $rowspan; $dr++) {
                        for ($dc = 0; $dc < $colspan; $dc++) {
                            if ($dr === 0 && $dc === 0) {
                                continue;
                            }
                            $grid[$r + $dr][$c + $dc] = '';
                        }
                    }
                }

                $c += $colspan;
            }

            if ($section === 'thead') {
                $headCount++;
            } elseif ($section === 'tfoot') {
                $footCount++;
            }

            $r++;
        }

        ksort($grid);
        $rows = [];

        foreach ($grid as $row) {
            ksort($row);
            $rows[] = array_values($row);
        }

        $data = TableData::fromRows($rows, $headCount ?: $headerRows);
        $data->footerRows = $footCount;
        $data->merges = $merges;

        return $data->normalize();
    }

    /**
     * XLSX/XLS/ODS, if the site has asked for it.
     *
     * PhpSpreadsheet is a `suggest`, not a `require`: it is a ~10 MB dependency, and a free Lite
     * install that only ever pastes from Google Sheets should not pay for it. The message names
     * the exact command rather than saying "not supported".
     *
     * @throws RuntimeException
     */
    public function fromSpreadsheet(string $path, int $headerRows = 1): TableData
    {
        if (!Plugin::getInstance()->isPro()) {
            throw new RuntimeException(Craft::t('legs', 'Spreadsheet import is a Pro feature.'));
        }

        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            throw new RuntimeException(Craft::t('legs', 'Spreadsheet import needs PhpSpreadsheet. Run `composer require phpoffice/phpspreadsheet` and try again.'));
        }

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $worksheet = $spreadsheet->getActiveSheet();

        $rows = [];

        foreach ($worksheet->toArray(null, true, true, false) as $row) {
            $rows[] = array_map(static fn($cell) => $cell === null ? '' : (string)$cell, $row);
        }

        return TableData::fromRows($rows, $headerRows);
    }

    /**
     * A paste out of Excel, Numbers or Google Sheets: tab-delimited, with quoted cells when a
     * value contains a tab or a newline of its own.
     */
    public function fromPaste(string $contents, int $headerRows = 1): TableData
    {
        return $this->fromCsv($contents, $headerRows, "\t");
    }

    private function cellContents(DOMElement $cell): string
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->allowHtmlInCells) {
            return trim($cell->textContent);
        }

        $html = '';

        foreach ($cell->childNodes as $child) {
            $html .= $cell->ownerDocument->saveHTML($child);
        }

        $html = trim($html);

        // Word and Docs paste a stack of nested spans carrying inline styles; strip the noise
        // and keep the meaning.
        return \craft\helpers\HtmlPurifier::process($html, Plugin::getInstance()->purifierConfig());
    }

    private function normalizeText(string $contents): string
    {
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        $contents = StringHelper::removeLeft($contents, "\u{FEFF}");

        if (!StringHelper::isUtf8($contents)) {
            $contents = StringHelper::convertToUtf8($contents);
        }

        return $contents;
    }

    /**
     * Picks the delimiter that produces the most consistent row width over the first few lines —
     * a comma that appears inside prose loses to a tab that separates columns.
     */
    private function sniffDelimiter(string $contents): string
    {
        $lines = array_slice(array_filter(explode("\n", $contents), static fn($line) => trim($line) !== ''), 0, 10);

        if (!$lines) {
            return ',';
        }

        $best = ',';
        $bestScore = -1;

        foreach ([',', "\t", ';', '|'] as $delimiter) {
            $counts = array_map(static fn($line) => substr_count($line, $delimiter), $lines);
            $max = max($counts);

            if ($max === 0) {
                continue;
            }

            // Consistency matters more than volume: every line having 4 tabs beats one line
            // having 20 commas.
            $consistent = count(array_unique($counts)) === 1;
            $score = $max * ($consistent ? 10 : 1);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $delimiter;
            }
        }

        return $best;
    }
}
