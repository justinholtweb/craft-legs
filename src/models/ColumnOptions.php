<?php

namespace justinholtweb\legs\models;

/**
 * Per-column presentation and sorting options.
 *
 * Serialised without its defaults, so a plain table's JSON stays small and a diff of two
 * tables shows only what an author actually changed.
 */
class ColumnOptions
{
    public const ALIGN_LEFT = 'left';
    public const ALIGN_CENTER = 'center';
    public const ALIGN_RIGHT = 'right';

    public const ALIGNMENTS = [self::ALIGN_LEFT, self::ALIGN_CENTER, self::ALIGN_RIGHT];

    /**
     * How the runtime compares values in this column. `auto` sniffs the column's own values
     * once and settles on number, date or text — a column of "1.10" and "1.9" must not sort
     * as strings, and a column of "10 apples" must not sort as numbers.
     */
    public const SORT_AUTO = 'auto';
    public const SORT_TEXT = 'text';
    public const SORT_NUMBER = 'number';
    public const SORT_DATE = 'date';

    public const SORT_TYPES = [self::SORT_AUTO, self::SORT_TEXT, self::SORT_NUMBER, self::SORT_DATE];

    public string $align = self::ALIGN_LEFT;
    public ?string $width = null;
    public string $sortAs = self::SORT_AUTO;
    public bool $sortable = true;
    public bool $hidden = false;

    /**
     * Whether a hidden column is still rendered, as a visually hidden cell, instead of being
     * left out of the markup altogether.
     *
     * The point is machine-readable metadata: a column of tokens like `cat:technology` that the
     * runtime's search can match on, so a site can drive the search box from a `<select>` and
     * facet precisely rather than hoping a category name does not also occur in a title.
     *
     * Off by default, and only meaningful beside `hidden`, because the two answer different
     * questions: `hidden` alone means "this column is not part of the page", and a column an
     * author hid to keep working notes out of sight must not start appearing in the source.
     */
    public bool $metadata = false;

    public ?string $class = null;

    public static function fromArray(array $config): self
    {
        $column = new self();
        $column->align = in_array($config['align'] ?? null, self::ALIGNMENTS, true)
            ? $config['align']
            : self::ALIGN_LEFT;
        $width = trim((string)($config['width'] ?? ''));
        $column->width = $width !== '' ? $width : null;
        $column->sortAs = in_array($config['sortAs'] ?? null, self::SORT_TYPES, true)
            ? $config['sortAs']
            : self::SORT_AUTO;
        $column->sortable = (bool)($config['sortable'] ?? true);
        $column->hidden = (bool)($config['hidden'] ?? false);
        $column->metadata = (bool)($config['metadata'] ?? false);
        $class = trim((string)($config['class'] ?? ''));
        $column->class = $class !== '' ? $class : null;

        return $column;
    }

    public function toArray(): array
    {
        $array = [];

        if ($this->align !== self::ALIGN_LEFT) {
            $array['align'] = $this->align;
        }
        if ($this->width !== null) {
            $array['width'] = $this->width;
        }
        if ($this->sortAs !== self::SORT_AUTO) {
            $array['sortAs'] = $this->sortAs;
        }
        if (!$this->sortable) {
            $array['sortable'] = false;
        }
        if ($this->hidden) {
            $array['hidden'] = true;
        }
        if ($this->metadata) {
            $array['metadata'] = true;
        }
        if ($this->class !== null) {
            $array['class'] = $this->class;
        }

        return $array;
    }
}
