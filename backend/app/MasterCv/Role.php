<?php

namespace App\MasterCv;

/**
 * One employment role or independent project.
 *
 * The same shape serves both: an independent project is written exactly like a
 * role and differs only in what may be claimed from it, which is what
 * `kind` records.
 */
final readonly class Role
{
    /**
     * @param  array<string, string>  $metadata  Labelled metadata bullets, by
     *                                           label as written. Not
     *                                           selectable.
     * @param  list<TechStackEntry>  $techStack
     * @param  list<Achievement>  $achievements
     * @param  list<array{label: string, line: int}>  $unknownBlocks  Labelled
     *                                                                blocks
     *                                                                whose
     *                                                                label is
     *                                                                not one
     *                                                                this tool
     *                                                                knows.
     */
    public function __construct(
        public ?string $id,
        public RoleKind $kind,
        public string $heading,
        public string $employer,
        public ?string $title,
        public int $line,
        public array $metadata,
        public ?DateRange $dates,
        public RoleMode $defaultMode,
        public ?string $context,
        public array $techStack,
        public array $achievements,
        public array $unknownBlocks,
    ) {}

    /**
     * @return list<Achievement> Every bullet under the role, at any depth.
     */
    public function allAchievements(): array
    {
        $all = [];

        foreach ($this->achievements as $achievement) {
            $all = [...$all, ...$achievement->withDescendants()];
        }

        return $all;
    }

    /**
     * @return list<string> Every technology named in the role's tech stack.
     */
    public function technologies(): array
    {
        $terms = [];

        foreach ($this->techStack as $entry) {
            $terms = [...$terms, ...$entry->terms];
        }

        return $terms;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'heading' => $this->heading,
            'employer' => $this->employer,
            'title' => $this->title,
            'line' => $this->line,
            'metadata' => $this->metadata,
            'dates' => $this->dates?->toArray(),
            'default_mode' => $this->defaultMode->value,
            'context' => $this->context,
            'tech_stack' => array_map(
                static fn (TechStackEntry $entry): array => $entry->toArray(),
                $this->techStack,
            ),
            'achievements' => array_map(
                static fn (Achievement $achievement): array => $achievement->toArray(),
                $this->achievements,
            ),
            'unknown_blocks' => $this->unknownBlocks,
        ];
    }
}
