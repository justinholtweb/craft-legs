<?php

namespace justinholtweb\legs\models;

/**
 * Per-row presentation options. Same serialisation bargain as {@see ColumnOptions}.
 */
class RowOptions
{
    public bool $hidden = false;
    public ?string $class = null;

    public static function fromArray(array $config): self
    {
        $row = new self();
        $row->hidden = (bool)($config['hidden'] ?? false);
        $class = trim((string)($config['class'] ?? ''));
        $row->class = $class !== '' ? $class : null;

        return $row;
    }

    public function toArray(): array
    {
        $array = [];

        if ($this->hidden) {
            $array['hidden'] = true;
        }
        if ($this->class !== null) {
            $array['class'] = $this->class;
        }

        return $array;
    }
}
