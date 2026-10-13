<?php

namespace App\MasterCv;

/**
 * One line of a role's tech stack: a category and the technologies under it.
 *
 * Inventory, not an achievement. Nothing here is selectable: it says which
 * technologies a role may claim, and it completes the skills index, since a
 * technology named here needs no row of its own. The category is the label on
 * the line, which is the grouping a derived row takes.
 */
final readonly class TechStackEntry
{
    /**
     * @param  list<string>  $terms
     */
    public function __construct(
        public ?string $category,
        public array $terms,
        public int $line,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'terms' => $this->terms,
            'line' => $this->line,
        ];
    }
}
