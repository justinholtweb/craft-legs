<?php

namespace justinholtweb\legs\models;

use JsonSerializable;

/**
 * How a table is presented: the options an author sets once and every embed of that table
 * inherits.
 *
 * A ref tag carries no parameters, so these are the *only* per-table presentation knobs — which
 * is deliberate. `{legs:prices:render}` in five entries means five tables that cannot drift.
 */
class RenderOptions implements JsonSerializable
{
    public const RESPONSIVE_NONE = 'none';
    public const RESPONSIVE_SCROLL = 'scroll';
    public const RESPONSIVE_STACK = 'stack';

    public const RESPONSIVE_MODES = [self::RESPONSIVE_NONE, self::RESPONSIVE_SCROLL, self::RESPONSIVE_STACK];

    public const CAPTION_TOP = 'top';
    public const CAPTION_BOTTOM = 'bottom';

    /** Whether visitors can sort by clicking column headers. */
    public bool $sortable = true;
    public ?int $defaultSortColumn = null;
    public string $defaultSortDir = 'asc';

    /** Whether a search box filters rows as the visitor types. Pro. */
    public bool $searchable = false;

    /** Whether long tables are paged. Pro. */
    public bool $paginate = false;
    public int $perPage = 25;

    /** What a table does when it is wider than its container. `stack` is Pro. */
    public string $responsive = self::RESPONSIVE_SCROLL;

    public bool $striped = true;
    public bool $hover = true;
    public bool $bordered = true;
    public bool $compact = false;
    public bool $stickyHeader = false;

    public string $captionPosition = self::CAPTION_TOP;

    /** Whether `=SUM(A1:A9)` style cells are evaluated at render. Pro. */
    public bool $formulas = false;

    /** Extra classes put on the wrapper, for sites that style tables themselves. */
    public ?string $class = null;

    public static function fromArray(?array $config): self
    {
        $options = new self();

        if ($config === null) {
            return $options;
        }

        $options->sortable = (bool)($config['sortable'] ?? $options->sortable);
        $column = $config['defaultSortColumn'] ?? null;
        $options->defaultSortColumn = ($column === null || $column === '') ? null : max(0, (int)$column);
        $options->defaultSortDir = ($config['defaultSortDir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $options->searchable = (bool)($config['searchable'] ?? $options->searchable);
        $options->paginate = (bool)($config['paginate'] ?? $options->paginate);
        $options->perPage = max(1, (int)($config['perPage'] ?? $options->perPage));
        $options->responsive = in_array($config['responsive'] ?? null, self::RESPONSIVE_MODES, true)
            ? $config['responsive']
            : $options->responsive;
        $options->striped = (bool)($config['striped'] ?? $options->striped);
        $options->hover = (bool)($config['hover'] ?? $options->hover);
        $options->bordered = (bool)($config['bordered'] ?? $options->bordered);
        $options->compact = (bool)($config['compact'] ?? $options->compact);
        $options->stickyHeader = (bool)($config['stickyHeader'] ?? $options->stickyHeader);
        $options->captionPosition = ($config['captionPosition'] ?? self::CAPTION_TOP) === self::CAPTION_BOTTOM
            ? self::CAPTION_BOTTOM
            : self::CAPTION_TOP;
        $options->formulas = (bool)($config['formulas'] ?? $options->formulas);
        $class = trim((string)($config['class'] ?? ''));
        $options->class = $class !== '' ? $class : null;

        return $options;
    }

    public static function fromJson(?string $json): self
    {
        if ($json === null || trim($json) === '') {
            return new self();
        }

        $decoded = json_decode($json, true);

        return self::fromArray(is_array($decoded) ? $decoded : null);
    }

    public function toArray(): array
    {
        return [
            'sortable' => $this->sortable,
            'defaultSortColumn' => $this->defaultSortColumn,
            'defaultSortDir' => $this->defaultSortDir,
            'searchable' => $this->searchable,
            'paginate' => $this->paginate,
            'perPage' => $this->perPage,
            'responsive' => $this->responsive,
            'striped' => $this->striped,
            'hover' => $this->hover,
            'bordered' => $this->bordered,
            'compact' => $this->compact,
            'stickyHeader' => $this->stickyHeader,
            'captionPosition' => $this->captionPosition,
            'formulas' => $this->formulas,
            'class' => $this->class,
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
     * Reads the option list out of an embed's reference tag.
     *
     * `{legs:prices:render(compact,perPage=10,!striped)}` — a bare name means on, `!name` means
     * off, and `name=value` sets a value. **No spaces**: Craft's reference-tag pattern stops the
     * attribute at the first space or pipe, so a spaced-out list would silently stop being a
     * reference tag at all.
     *
     * Unknown names are dropped rather than passed on, because the alternative is an author
     * typing `sortible` and getting a table that looks right and behaves wrong.
     *
     * @return array<string, mixed>
     */
    public static function parseEmbedOptions(string $list): array
    {
        $known = array_keys((new self())->toArray());
        $options = [];

        foreach (explode(',', $list) as $item) {
            $item = trim($item);

            if ($item === '') {
                continue;
            }

            if (str_contains($item, '=')) {
                [$name, $value] = array_map('trim', explode('=', $item, 2));
            } elseif (str_starts_with($item, '!')) {
                $name = substr($item, 1);
                $value = 'false';
            } else {
                $name = $item;
                $value = 'true';
            }

            $name = lcfirst(trim($name));

            if (!in_array($name, $known, true)) {
                continue;
            }

            $options[$name] = match (strtolower($value)) {
                'true', 'yes', 'on', '1' => true,
                'false', 'no', 'off', '0' => false,
                default => is_numeric($value) ? (int)$value : $value,
            };
        }

        return $options;
    }

    /**
     * The inverse: the shortest embed list that expresses these overrides.
     *
     * @param array<string, mixed> $overrides
     */
    public static function toEmbedOptions(array $overrides): string
    {
        $parts = [];

        foreach ($overrides as $name => $value) {
            $parts[] = match (true) {
                $value === true => $name,
                $value === false => "!$name",
                default => "$name=$value",
            };
        }

        return implode(',', $parts);
    }

    /** Whether anything here needs the front-end runtime at all. */
    public function needsRuntime(): bool
    {
        return $this->sortable || $this->searchable || $this->paginate;
    }
}
