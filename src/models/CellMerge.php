<?php

namespace justinholtweb\legs\models;

/**
 * A merged region, anchored at its top-left cell.
 *
 * Stored as a list rather than as per-cell colspan/rowspan attributes because a merge is one
 * fact about a rectangle: recording it once means the editor and the renderer cannot disagree
 * about which cells are covered, and clearing it is a single removal.
 */
class CellMerge
{
    public function __construct(
        public int $row = 0,
        public int $col = 0,
        public int $rowspan = 1,
        public int $colspan = 1,
    ) {
    }

    public static function fromArray(array $config): self
    {
        return new self(
            max(0, (int)($config['row'] ?? 0)),
            max(0, (int)($config['col'] ?? 0)),
            max(1, (int)($config['rowspan'] ?? 1)),
            max(1, (int)($config['colspan'] ?? 1)),
        );
    }

    public function toArray(): array
    {
        return [
            'row' => $this->row,
            'col' => $this->col,
            'rowspan' => $this->rowspan,
            'colspan' => $this->colspan,
        ];
    }

    /** Whether this merge covers the given cell without being anchored at it. */
    public function covers(int $row, int $col): bool
    {
        if ($row === $this->row && $col === $this->col) {
            return false;
        }

        return $row >= $this->row
            && $row < $this->row + $this->rowspan
            && $col >= $this->col
            && $col < $this->col + $this->colspan;
    }

    public function isTrivial(): bool
    {
        return $this->rowspan === 1 && $this->colspan === 1;
    }
}
