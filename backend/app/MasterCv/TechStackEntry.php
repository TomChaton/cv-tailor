<?php

namespace App\MasterCv;

/**
 * One line of a role's tech stack: a category and the technologies under it.
 *
 * Inventory, not an achievement. Nothing here is selectable; it exists to
 * build the vocabulary the technology-to-role check is made of, and so that
 * lint can tell when the stacks and the skills index have drifted apart.
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
