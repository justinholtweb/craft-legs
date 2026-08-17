<?php

namespace justinholtweb\legs\models;

use JsonSerializable;
use justinholtweb\legs\Plugin;
use Twig\Markup;

/**
 * The value of a Legs field in *inline* mode: a grid that belongs to the element it is on, with
 * no library entry behind it.
 *
 * Deliberately shaped like the Table element from a template's point of view — `.render()`,
 * `.data`, `.options` — so `{{ entry.spec.render() }}` and `{{ craft.legs.render('prices') }}`
 * are the same sentence, and switching a field from inline to reference does not rewrite
 * anybody's templates.
 */
class InlineTable implements JsonSerializable
{
    public function __construct(
        public TableData $data,
        public RenderOptions $options,
        public ?string $caption = null,
    ) {
    }

    public static function fromArray(?array $config): self
    {
        return new self(
            TableData::fromArray($config['data'] ?? null),
            RenderOptions::fromArray($config['options'] ?? null),
            ($config['caption'] ?? null) ?: null,
        );
    }

    public static function fromJson(?string $json): self
    {
        $decoded = $json ? json_decode($json, true) : null;

        return self::fromArray(is_array($decoded) ? $decoded : null);
    }

    public function render(array $overrides = []): Markup
    {
        $options = $overrides
            ? RenderOptions::fromArray(array_merge($this->options->toArray(), $overrides))
            : $this->options;

        return Plugin::getInstance()->renderer->renderData($this->data, $options, [
            'caption' => $this->caption,
        ]);
    }

    public function __toString(): string
    {
        return (string)$this->render();
    }

    public function isEmpty(): bool
    {
        return $this->data->isEmpty();
    }

    public function toArray(): array
    {
        return [
            'data' => $this->data->toArray(),
            'options' => $this->options->toArray(),
            'caption' => $this->caption,
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

    /** Cell text, for the search index. */
    public function getSearchKeywords(): string
    {
        $words = [];

        foreach ($this->data->cells as $row) {
            foreach ($row as $cell) {
                $words[] = strip_tags($cell);
            }
        }

        return implode(' ', $words);
    }
}
