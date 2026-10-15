<?php

namespace App\Review;

use App\Generation\RequirementPriority;
use App\Generation\RequirementStatus;

/**
 * One of the job's requirements, as the model read it, with the master CV text
 * behind it.
 *
 * Nothing here trusts its input. A generated revision met the output schema,
 * but a manual edit never did, so a priority or a status that is not one of the
 * values the schema names is read as the one that asks for the most attention:
 * a requirement nobody can classify must not sort below a requirement that is
 * met.
 */
final readonly class Requirement
{
    /**
     * @param  list<Evidence>  $evidence
     */
    private function __construct(
        public string $requirement,
        public RequirementPriority $priority,
        public RequirementStatus $status,
        public ?string $note,
        public array $evidence,
    ) {}

    /**
     * @param  array<array-key, mixed>  $stored
     * @param  list<Evidence>  $evidence
     */
    public static function from(array $stored, array $evidence): self
    {
        $requirement = $stored['requirement'] ?? null;
        $priority = $stored['priority'] ?? null;
        $status = $stored['status'] ?? null;
        $note = $stored['note'] ?? null;

        return new self(
            requirement: is_string($requirement) ? $requirement : '',
            priority: (is_string($priority) ? RequirementPriority::tryFrom($priority) : null)
                ?? RequirementPriority::Must,
            status: (is_string($status) ? RequirementStatus::tryFrom($status) : null)
                ?? RequirementStatus::Gap,
            note: is_string($note) && $note !== '' ? $note : null,
            evidence: $evidence,
        );
    }

    /**
     * Where this requirement sorts: `must` before `nice`, and within each,
     * `gap` before `partial` before `met`. A gap against a must-have is the
     * single most useful thing on the screen.
     *
     * @return array{int, int}
     */
    public function attention(): array
    {
        return [$this->priority->attention(), $this->status->attention()];
    }
}
