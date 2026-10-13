<?php

namespace Tests\Concerns;

use App\MasterCv\Skill;

/**
 * A selection of the fictional master CV that passes every check, and the
 * means to break exactly one thing about it.
 *
 * Every failing case in the validation tests starts here and changes one
 * field, so what a check is reacting to is visible in the test rather than
 * buried in a fixture. Nothing here derives from a real CV.
 */
trait SelectionFixtures
{
    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    protected function selection(array $changes = []): array
    {
        return [...$this->cleanSelection(), ...$changes];
    }

    /**
     * @return array<string, mixed>
     */
    protected function cleanSelection(): array
    {
        return [
            'jd_analysis' => [[
                'requirement' => 'Modern PHP',
                'priority' => 'must',
                'status' => 'met',
                'evidence' => ['harlequin-transit.6'],
                'note' => 'Led the upgrade of a business-critical platform.',
            ]],
            'headline' => 'Senior Backend Engineer',
            'summary' => [
                'text' => 'Senior backend engineer who has spent sixteen years on PHP and relational '
                    .'database systems, most recently leading a dispatch platform.',
                'supports' => ['harlequin-transit', 'harlequin-transit.4'],
            ],
            'key_skills' => [
                Skill::idFor('PHP 8.x (8.3)'),
                Skill::idFor('PostgreSQL (13, 15)'),
                Skill::idFor('Elasticsearch (7, 8)'),
            ],
            'roles' => [
                $this->role('harlequin-transit', 'full', [
                    $this->verbatim('harlequin-transit.4'),
                    $this->reworded(
                        'harlequin-transit.5.1',
                        'Moved stop and route search from Elasticsearch 7 to 8 with no downtime.',
                        ['harlequin-transit.5.1'],
                    ),
                ]),
                $this->role('northgate-tickets', 'full', [$this->verbatim('northgate-tickets.1')]),
                $this->role('pelham-civic-data', 'condensed', [$this->verbatim('pelham-civic-data.1')]),
                $this->role('larkspur-media', 'omitted', []),
            ],
            'independent_projects' => [
                $this->role('fathom-index', 'full', [$this->verbatim('fathom-index.1')]),
                $this->role('quayside-charts', 'full', [$this->verbatim('quayside-charts.1')]),
            ],
            'review_notes' => [
                'gaps' => [],
                'judgement_calls' => [],
                'promotions' => [],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    protected function role(string $id, string $mode, array $items): array
    {
        return ['id' => $id, 'mode' => $mode, 'items' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    protected function verbatim(string $id): array
    {
        return ['id' => $id, 'text' => null, 'supports' => []];
    }

    /**
     * @param  list<string>  $supports
     * @return array<string, mixed>
     */
    protected function reworded(string $id, string $text, array $supports): array
    {
        return ['id' => $id, 'text' => $text, 'supports' => $supports];
    }

    /**
     * The clean selection with one role's items replaced.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    protected function selectionWithItems(string $roleId, array $items, string $key = 'roles'): array
    {
        $group = $this->group($key);

        foreach ($group as $index => $role) {
            if (($role['id'] ?? null) === $roleId) {
                $group[$index]['items'] = $items;
            }
        }

        return $this->selection([$key => $group]);
    }

    /**
     * The clean selection with one role's mode changed.
     *
     * @return array<string, mixed>
     */
    protected function selectionWithMode(string $roleId, string $mode, string $key = 'roles'): array
    {
        $group = $this->group($key);

        foreach ($group as $index => $role) {
            if (($role['id'] ?? null) === $roleId) {
                $group[$index]['mode'] = $mode;
            }
        }

        return $this->selection([$key => $group]);
    }

    /**
     * The clean selection with one entry of a container replaced outright, for
     * the cases that care which slot it is in.
     *
     * @param  array<string, mixed>  $role
     * @return array<string, mixed>
     */
    protected function selectionWithRoleAt(int $index, array $role, string $key = 'roles'): array
    {
        $group = $this->group($key);
        $group[$index] = $role;

        return $this->selection([$key => $group]);
    }

    /**
     * @param  list<string>  $supports
     * @return array<string, mixed>
     */
    protected function selectionWithSummary(
        string $text,
        array $supports = ['harlequin-transit', 'harlequin-transit.4'],
    ): array {
        return $this->selection(['summary' => ['text' => $text, 'supports' => $supports]]);
    }

    /**
     * @param  list<array<string, mixed>>  $promotions
     * @param  list<string>  $gaps
     * @return array<string, mixed>
     */
    protected function reviewNotes(array $promotions = [], array $gaps = []): array
    {
        return ['gaps' => $gaps, 'judgement_calls' => [], 'promotions' => $promotions];
    }

    /**
     * @param  list<string>  $evidence
     * @return array<string, mixed>
     */
    protected function requirement(string $requirement, string $status, array $evidence): array
    {
        return [
            'requirement' => $requirement,
            'priority' => 'must',
            'status' => $status,
            'evidence' => $evidence,
            'note' => 'One sentence for the owner.',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function group(string $key): array
    {
        /** @var list<array<string, mixed>> $group */
        $group = $this->cleanSelection()[$key];

        return $group;
    }
}
