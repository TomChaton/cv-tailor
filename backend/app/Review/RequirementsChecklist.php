<?php

namespace App\Review;

use App\Validation\MasterCvIndex;

/**
 * The job's requirements, ordered by what needs attention, with every cited ID
 * resolved to the master CV text behind it.
 *
 * **It is handed an index and never fetches one.** Which parse the evidence is
 * read from is the whole of what makes the checklist honest, and a class that
 * loaded its own would be free to load the current CV beside an old revision.
 * See `ReadRevisionReview`.
 *
 * A requirement with no evidence shows none. `gap` is expected to have none,
 * and that is the honest answer rather than a blank for something to fill.
 */
final readonly class RequirementsChecklist
{
    /**
     * @param  array<string, mixed>  $output
     * @return list<Requirement>
     */
    public function of(array $output, MasterCvIndex $index): array
    {
        $analysis = $output['jd_analysis'] ?? null;

        if (! is_array($analysis)) {
            return [];
        }

        $requirements = [];

        foreach ($analysis as $stored) {
            if (is_array($stored)) {
                $requirements[] = Requirement::from($stored, $this->evidence($stored['evidence'] ?? null, $index));
            }
        }

        // A stable sort, so two requirements that need the same attention stay
        // in the order the model extracted them: that order is its reading of
        // the advert, and reordering it would be the screen's own opinion.
        usort(
            $requirements,
            static fn (Requirement $a, Requirement $b): int => $a->attention() <=> $b->attention(),
        );

        return $requirements;
    }

    /**
     * @return list<Evidence>
     */
    private function evidence(mixed $ids, MasterCvIndex $index): array
    {
        if (! is_array($ids)) {
            return [];
        }

        $evidence = [];

        foreach ($ids as $id) {
            if (is_string($id)) {
                $evidence[] = new Evidence($id, $this->textOf($id, $index));
            }
        }

        return $evidence;
    }

    /**
     * The master CV's own words for an ID, whichever kind of thing it names.
     *
     * A role is named by its heading, because a role's text is the job it was:
     * the employer and the title together are what evidences experience of a
     * kind, and the bullets under it have IDs of their own to be cited by.
     */
    private function textOf(string $id, MasterCvIndex $index): string
    {
        $role = $index->role($id);

        if ($role !== null) {
            return $role->heading;
        }

        return $index->textOf($id) ?? $id;
    }
}
