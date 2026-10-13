<?php

namespace App\Validation\Output;

/**
 * One item the output chose to show under a role or a project.
 *
 * `text` is null in the normal case, meaning the master CV's own wording is
 * used. A non-null `text` is a rewording, and a rewording is the riskiest
 * thing the model produces: it is the only place where words that are not the
 * author's reach the page.
 */
final readonly class SelectedItem
{
    /**
     * @param  list<IdReference>  $supports
     */
    public function __construct(
        public ?string $id,
        public string $field,
        public ?string $text,
        public array $supports,
    ) {}

    public function isRewording(): bool
    {
        return $this->text !== null;
    }

    /**
     * @param  array<array-key, mixed>  $item
     */
    public static function from(array $item, string $field): self
    {
        $id = $item['id'] ?? null;
        $text = $item['text'] ?? null;

        return new self(
            id: is_string($id) ? $id : null,
            field: $field,
            text: is_string($text) ? $text : null,
            supports: Selection::references($item['supports'] ?? null, $field.'.supports'),
        );
    }
}
