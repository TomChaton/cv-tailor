<?php

namespace App\Validation\Output;

use App\MasterCv\RoleKind;

/**
 * The model's selection, read into something the checks can ask questions of.
 *
 * Nothing here trusts its input. The structured-output schema constrains what
 * a generated revision comes back with, but a manual edit passes through the
 * same checks and has never met the schema, so every field is read for the
 * shape it should have and ignored when it does not have it. A missing or
 * malformed field is not an opportunity to skip a check: it is a field with
 * nothing in it to validate.
 */
final readonly class Selection
{
    /**
     * @param  list<IdReference>  $keySkills
     * @param  list<SelectedRole>  $roles
     * @param  list<SelectedRole>  $independentProjects
     * @param  list<IdReference>  $evidence  The IDs `jd_analysis` cites.
     * @param  list<IdReference>  $promotions  The IDs `review_notes` says were
     *                                         promoted.
     */
    private function __construct(
        public ?string $headline,
        public ?SelectedSummary $summary,
        public array $keySkills,
        public array $roles,
        public array $independentProjects,
        public array $evidence,
        public array $promotions,
    ) {}

    /**
     * @param  array<string, mixed>  $output
     */
    public static function from(array $output): self
    {
        $headline = $output['headline'] ?? null;

        return new self(
            headline: is_string($headline) ? $headline : null,
            summary: self::summary($output['summary'] ?? null),
            keySkills: self::references($output['key_skills'] ?? null, 'key_skills'),
            roles: self::selections($output['roles'] ?? null, 'roles', RoleKind::Employment),
            independentProjects: self::selections(
                $output['independent_projects'] ?? null,
                'independent_projects',
                RoleKind::Independent,
            ),
            evidence: self::evidence($output['jd_analysis'] ?? null),
            promotions: self::promotions($output['review_notes'] ?? null),
        );
    }

    /**
     * Employment and independent projects together, for the checks that hold
     * both to the same rule.
     *
     * @return list<SelectedRole>
     */
    public function allRoles(): array
    {
        return [...$this->roles, ...$this->independentProjects];
    }

    /**
     * Every item under every role, with the role it was filed under.
     *
     * @return list<array{0: SelectedRole, 1: SelectedItem}>
     */
    public function items(): array
    {
        $items = [];

        foreach ($this->allRoles() as $role) {
            foreach ($role->items as $item) {
                $items[] = [$role, $item];
            }
        }

        return $items;
    }

    /**
     * A list of IDs as references, with the field each sat in.
     *
     * @return list<IdReference>
     */
    public static function references(mixed $ids, string $field): array
    {
        if (! is_array($ids)) {
            return [];
        }

        $references = [];

        foreach ($ids as $index => $id) {
            if (is_string($id)) {
                $references[] = new IdReference($id, $field.'['.$index.']');
            }
        }

        return $references;
    }

    private static function summary(mixed $summary): ?SelectedSummary
    {
        if (! is_array($summary)) {
            return null;
        }

        $text = $summary['text'] ?? null;

        if (! is_string($text)) {
            return null;
        }

        return new SelectedSummary($text, self::references($summary['supports'] ?? null, 'summary.supports'));
    }

    /**
     * @return list<SelectedRole>
     */
    private static function selections(mixed $group, string $field, RoleKind $container): array
    {
        if (! is_array($group)) {
            return [];
        }

        $selections = [];

        foreach ($group as $index => $selection) {
            if (is_array($selection)) {
                $selections[] = SelectedRole::from($selection, $field.'['.$index.']', $container);
            }
        }

        return $selections;
    }

    /**
     * @return list<IdReference>
     */
    private static function evidence(mixed $analysis): array
    {
        if (! is_array($analysis)) {
            return [];
        }

        $references = [];

        foreach ($analysis as $index => $requirement) {
            if (is_array($requirement)) {
                $references = [...$references, ...self::references(
                    $requirement['evidence'] ?? null,
                    'jd_analysis['.$index.'].evidence',
                )];
            }
        }

        return $references;
    }

    /**
     * @return list<IdReference>
     */
    private static function promotions(mixed $notes): array
    {
        $promotions = is_array($notes) ? ($notes['promotions'] ?? null) : null;

        if (! is_array($promotions)) {
            return [];
        }

        $references = [];

        foreach ($promotions as $index => $promotion) {
            $id = is_array($promotion) ? ($promotion['id'] ?? null) : null;

            if (is_string($id)) {
                $references[] = new IdReference($id, 'review_notes.promotions['.$index.'].id');
            }
        }

        return $references;
    }
}
