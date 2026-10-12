<?php

namespace App\MasterCv;

/**
 * A selectable bullet under a role or an independent project, with any
 * sub-bullets nested under it.
 *
 * Sub-bullets are selectable in their own right, so a parent can be taken
 * whole or narrowed to some of its children. `blockLabel` records which
 * labelled block the bullet sat under, because that label is what a bullet
 * means: the same sentence under "Tech stack" would be inventory rather than
 * an achievement.
 */
final readonly class Achievement
{
    /**
     * @param  list<ContentMarker>  $markers
     * @param  list<Achievement>  $children
     */
    public function __construct(
        public ?string $id,
        public string $text,
        public int $line,
        public string $blockLabel,
        public bool $selectable,
        public bool $verbatimOnly,
        public array $markers,
        public array $children,
    ) {}

    /**
     * @return list<Achievement> This bullet and every bullet beneath it.
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
            'line' => $this->line,
            'block' => $this->blockLabel,
            'selectable' => $this->selectable,
            'verbatim_only' => $this->verbatimOnly,
            'markers' => ContentMarker::values($this->markers),
            'children' => array_map(
                static fn (Achievement $child): array => $child->toArray(),
                $this->children,
            ),
        ];
    }
}
