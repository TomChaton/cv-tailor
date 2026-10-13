<?php

namespace App\Rendering;

use App\MasterCv\RoleKind;
use App\MasterCv\RoleMode;

/**
 * One role or project of the finished document.
 *
 * **The employer, the title and the dates come from the parsed master CV**,
 * never from model output, and the roles are already in the order they are to
 * be shown. The model chooses which roles and which of their bullets appear; it
 * chooses nothing about what a role is called or when it ran.
 *
 * `context` is resolved only for a role shown in full. A condensed role is
 * condensed by leaving the prose out, and that decision belongs here rather
 * than in a template, so a second output format makes it the same way.
 */
final readonly class ResolvedRole
{
    /**
     * @param  list<ResolvedItem>  $items
     */
    public function __construct(
        public string $id,
        public RoleKind $kind,
        public RoleMode $mode,
        public string $employer,
        public ?string $title,
        public ?string $dates,
        public ?string $context,
        public array $items,
    ) {}

    /**
     * Every bullet under the role, at any depth.
     *
     * @return list<ResolvedItem>
     */
    public function allItems(): array
    {
        $all = [];

        foreach ($this->items as $item) {
            $all = [...$all, ...$item->withDescendants()];
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
            'kind' => $this->kind->value,
            'mode' => $this->mode->value,
            'employer' => $this->employer,
            'title' => $this->title,
            'dates' => $this->dates,
            'context' => $this->context,
            'items' => array_map(
                static fn (ResolvedItem $item): array => $item->toArray(),
                $this->items,
            ),
        ];
    }
}
