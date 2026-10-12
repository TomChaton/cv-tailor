<?php

namespace App\MasterCv;

/**
 * One subsection of the skills index, with the rows under it.
 */
final readonly class SkillGroup
{
    /**
     * @param  list<Skill>  $skills
     */
    public function __construct(
        public string $heading,
        public int $line,
        public array $skills,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'heading' => $this->heading,
            'line' => $this->line,
            'skills' => array_map(static fn (Skill $skill): array => $skill->toArray(), $this->skills),
        ];
    }
}
