<?php

namespace justinholtweb\legs\models;

use JsonSerializable;

/**
 * The grid: one rectangular block of cells plus the metadata that describes it.
 *
 * One 2D `cells` array with header/footer *counts* — rather than separate head/body/foot
 * arrays — is the decision everything else rests on. The editor then has exactly one structure
 * to edit and one to send back, "make row 1 a header" is a number change rather than a data
 * migration, and the renderer is the only thing that needs an opinion about `<thead>`.
 *
 * Shared verbatim by the Table element and by inline field values, so importers, the editor,
 * the renderer and the formula engine all speak one format.
 */
class TableData implements JsonSerializable
{
    /** @var list<list<string>> Row-major cell text. Always rectangular after {@see normalize()}. */
    public array $cells = [];

    /** @var list<ColumnOptions> */
    public array $columns = [];

    /** @var list<RowOptions> */
    public array $rows = [];

    public int $headerRows = 1;
    public int $footerRows = 0;

    /** @var list<CellMerge> */
    public array $merges = [];

    public static function fromArray(?array $config): self
    {
        $data = new self();

        if ($config === null) {
            return $data->normalize();
        }

        foreach ($config['cells'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $data->cells[] = array_map(static fn($cell) => is_scalar($cell) ? (string)$cell : '', array_values($row));
        }

        foreach ($config['columns'] ?? [] as $column) {
            $data->columns[] = ColumnOptions::fromArray(is_array($column) ? $column : []);
        }

        foreach ($config['rows'] ?? [] as $row) {
            $data->rows[] = RowOptions::fromArray(is_array($row) ? $row : []);
        }

        foreach ($config['merges'] ?? [] as $merge) {
            if (!is_array($merge)) {
                continue;
            }
            $merge = CellMerge::fromArray($merge);
            if (!$merge->isTrivial()) {
                $data->merges[] = $merge;
            }
        }

        $data->headerRows = max(0, (int)($config['headerRows'] ?? 1));
        $data->footerRows = max(0, (int)($config['footerRows'] ?? 0));

        return $data->normalize();
    }

    public static function fromJson(?string $json): self
    {
        if ($json === null || trim($json) === '') {
            return (new self())->normalize();
        }

        $decoded = json_decode($json, true);

        return self::fromArray(is_array($decoded) ? $decoded : null);
    }

    /**
     * Creates a grid from a plain list of rows — what every importer produces.
     *
     * @param list<list<string>> $rows
     */
    public static function fromRows(array $rows, int $headerRows = 1): self
    {
        $data = new self();
        $data->headerRows = max(0, $headerRows);

        foreach ($rows as $row) {
            $data->cells[] = array_map(static fn($cell) => is_scalar($cell) ? (string)$cell : '', array_values((array)$row));
        }

        return $data->normalize();
    }

    public function toArray(): array
    {
        return [
            'cells' => $this->cells,
            'columns' => array_map(static fn(ColumnOptions $c) => $c->toArray(), $this->columns),
            'rows' => array_map(static fn(RowOptions $r) => $r->toArray(), $this->rows),
            'headerRows' => $this->headerRows,
            'footerRows' => $this->footerRows,
            'merges' => array_map(static fn(CellMerge $m) => $m->toArray(), $this->merges),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Squares the grid up: pads every row to the widest one, matches the column and row
     * metadata to the real dimensions, clamps the header and footer counts so they cannot
     * overlap or run past the end, and drops merges that no longer fit.
     *
     * Called after every construction and every structural edit, so nothing downstream has to
     * defend itself against a ragged grid.
     */
    public function normalize(): self
    {
        $colCount = 0;
        foreach ($this->cells as $row) {
            $colCount = max($colCount, count($row));
        }

        // A table with no cells still has one, so the editor has something to click on.
        if ($colCount === 0) {
            $this->cells = [['']];
            $colCount = 1;
        }

        foreach (array_keys($this->cells) as $r) {
            $row = $this->cells[$r];
            if (count($row) < $colCount) {
                $row = array_pad($row, $colCount, '');
            }
            $this->cells[$r] = array_values($row);
        }

        $rowCount = count($this->cells);

        $this->columns = array_slice($this->columns, 0, $colCount);
        while (count($this->columns) < $colCount) {
            $this->columns[] = new ColumnOptions();
        }

        $this->rows = array_slice($this->rows, 0, $rowCount);
        while (count($this->rows) < $rowCount) {
            $this->rows[] = new RowOptions();
        }

        $this->headerRows = min(max(0, $this->headerRows), $rowCount);
        $this->footerRows = min(max(0, $this->footerRows), $rowCount - $this->headerRows);

        $this->merges = array_values(array_filter(
            $this->merges,
            fn(CellMerge $merge) => !$merge->isTrivial()
                && $merge->row < $rowCount
                && $merge->col < $colCount
                && $merge->row + $merge->rowspan <= $rowCount
                && $merge->col + $merge->colspan <= $colCount,
        ));

        return $this;
    }

    public function getRowCount(): int
    {
        return count($this->cells);
    }

    public function getColCount(): int
    {
        return count($this->cells[0] ?? []);
    }

    public function isEmpty(): bool
    {
        foreach ($this->cells as $row) {
            foreach ($row as $cell) {
                if (trim($cell) !== '') {
                    return false;
                }
            }
        }

        return true;
    }

    public function cell(int $row, int $col): string
    {
        return $this->cells[$row][$col] ?? '';
    }

    public function setCell(int $row, int $col, string $value): void
    {
        if (isset($this->cells[$row][$col])) {
            $this->cells[$row][$col] = $value;
        }
    }

    /** @return list<int> Row indexes belonging to `<thead>`. */
    public function headerRowIndexes(): array
    {
        return $this->headerRows > 0 ? range(0, $this->headerRows - 1) : [];
    }

    /** @return list<int> Row indexes belonging to `<tbody>`. */
    public function bodyRowIndexes(): array
    {
        $rowCount = $this->getRowCount();
        $length = $rowCount - $this->headerRows - $this->footerRows;

        return $length > 0 ? range($this->headerRows, $this->headerRows + $length - 1) : [];
    }

    /** @return list<int> Row indexes belonging to `<tfoot>`. */
    public function footerRowIndexes(): array
    {
        $rowCount = $this->getRowCount();

        return $this->footerRows > 0 ? range($rowCount - $this->footerRows, $rowCount - 1) : [];
    }

    public function mergeAt(int $row, int $col): ?CellMerge
    {
        foreach ($this->merges as $merge) {
            if ($merge->row === $row && $merge->col === $col) {
                return $merge;
            }
        }

        return null;
    }

    public function isCovered(int $row, int $col): bool
    {
        foreach ($this->merges as $merge) {
            if ($merge->covers($row, $col)) {
                return true;
            }
        }

        return false;
    }

    /** The label a header cell gives a column, used for responsive stacking and for accessibility. */
    public function columnLabel(int $col): string
    {
        if ($this->headerRows === 0) {
            return '';
        }

        return trim(strip_tags($this->cell($this->headerRows - 1, $col)));
    }

    public function insertRow(int $index, bool $copyOptions = true): self
    {
        $index = max(0, min($index, $this->getRowCount()));
        array_splice($this->cells, $index, 0, [array_fill(0, $this->getColCount(), '')]);
        $reference = $copyOptions ? ($this->rows[$index] ?? null) : null;
        array_splice($this->rows, $index, 0, [$reference ? RowOptions::fromArray($reference->toArray()) : new RowOptions()]);
        $this->shiftMerges($index, 1, true);

        return $this->normalize();
    }

    public function deleteRow(int $index): self
    {
        if (!isset($this->cells[$index]) || $this->getRowCount() === 1) {
            return $this;
        }

        array_splice($this->cells, $index, 1);
        array_splice($this->rows, $index, 1);
        $this->dropMergesSpanning($index, true);
        $this->shiftMerges($index, -1, true);

        if ($index < $this->headerRows) {
            $this->headerRows--;
        }

        return $this->normalize();
    }

    public function insertColumn(int $index): self
    {
        $index = max(0, min($index, $this->getColCount()));

        foreach (array_keys($this->cells) as $r) {
            array_splice($this->cells[$r], $index, 0, ['']);
        }

        array_splice($this->columns, $index, 0, [new ColumnOptions()]);
        $this->shiftMerges($index, 1, false);

        return $this->normalize();
    }

    public function deleteColumn(int $index): self
    {
        if ($index < 0 || $index >= $this->getColCount() || $this->getColCount() === 1) {
            return $this;
        }

        foreach (array_keys($this->cells) as $r) {
            array_splice($this->cells[$r], $index, 1);
        }

        array_splice($this->columns, $index, 1);
        $this->dropMergesSpanning($index, false);
        $this->shiftMerges($index, -1, false);

        return $this->normalize();
    }

    public function moveRow(int $from, int $to): self
    {
        if (!isset($this->cells[$from]) || !isset($this->cells[$to]) || $from === $to) {
            return $this;
        }

        $cells = array_splice($this->cells, $from, 1);
        array_splice($this->cells, $to, 0, $cells);
        $options = array_splice($this->rows, $from, 1);
        array_splice($this->rows, $to, 0, $options);

        // Moving a row through a merged region would leave the merge describing cells that are
        // no longer beneath it, and there is no sane guess at what the author meant.
        $this->merges = [];

        return $this->normalize();
    }

    public function moveColumn(int $from, int $to): self
    {
        $colCount = $this->getColCount();
        if ($from < 0 || $to < 0 || $from >= $colCount || $to >= $colCount || $from === $to) {
            return $this;
        }

        foreach (array_keys($this->cells) as $r) {
            $cell = array_splice($this->cells[$r], $from, 1);
            array_splice($this->cells[$r], $to, 0, $cell);
        }

        $options = array_splice($this->columns, $from, 1);
        array_splice($this->columns, $to, 0, $options);
        $this->merges = [];

        return $this->normalize();
    }

    /** Transposes the grid — rows become columns. Merges and per-axis options do not survive. */
    public function transpose(): self
    {
        $transposed = [];

        foreach ($this->cells as $r => $row) {
            foreach ($row as $c => $cell) {
                $transposed[$c][$r] = $cell;
            }
        }

        $this->cells = array_map('array_values', array_values($transposed));
        $this->columns = [];
        $this->rows = [];
        $this->merges = [];

        return $this->normalize();
    }

    private function shiftMerges(int $index, int $delta, bool $vertical): void
    {
        foreach ($this->merges as $merge) {
            if ($vertical && $merge->row >= $index) {
                $merge->row += $delta;
            } elseif (!$vertical && $merge->col >= $index) {
                $merge->col += $delta;
            }
        }
    }

    private function dropMergesSpanning(int $index, bool $vertical): void
    {
        $this->merges = array_values(array_filter($this->merges, function(CellMerge $merge) use ($index, $vertical) {
            if ($vertical) {
                return $index < $merge->row || $index >= $merge->row + $merge->rowspan;
            }

            return $index < $merge->col || $index >= $merge->col + $merge->colspan;
        }));
    }
}
