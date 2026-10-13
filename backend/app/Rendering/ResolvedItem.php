<?php

namespace App\Rendering;

/**
 * One bullet of the finished document, with the bullets nested under it.
 *
 * `text` is the words that will be on the page and nothing has to be looked up
 * to find them: the master CV's own sentence for a verbatim selection, the
 * model's for a rewording. `reworded` is kept because the review UI has to be
 * able to show which words are not the author's.
 *
 * The nesting comes from the master CV rather than from the output. A
 * sub-bullet selected beside its parent belongs under it, and which bullet is
 * whose child is a fact of the file.
 */
final readonly class ResolvedItem
{
    /**
     * @param  list<ResolvedItem>  $children
     */
    public function __construct(
        public string $id,
        public string $text,
        public bool $reworded,
        public array $children = [],
    ) {}

    /**
     * This item and every item beneath it.
     *
     * @return list<ResolvedItem>
     */
    public function withDescendants(): array
    {
        $all = [$this];

        foreach ($this->children as $child) {
            $all = [...$all, ...$child->withDescendants()];
        }

        return $all;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
            'reworded' => $this->reworded,
            'children' => array_map(
                static fn (self $child): array => $child->toArray(),
                $this->children,
            ),
        ];
    }
}
