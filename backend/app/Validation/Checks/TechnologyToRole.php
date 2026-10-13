<?php

namespace App\Validation\Checks;

use App\MasterCv\Role;
use App\Validation\Check;
use App\Validation\Finding;
use App\Validation\FindingMessages;
use App\Validation\ValidationContext;

/**
 * A technology named in a reworded item was used in that role.
 *
 * This is the check that stops the most plausible fabrication there is:
 * moving a technology from the role that used it to the role the job
 * description asks about. Nothing about the sentence looks wrong, and an
 * employer reading it would believe it.
 *
 * The skills index decides what counts as a technology; **the role's own tech
 * stack decides whether this role may claim it**. The two are deliberately
 * different sources: the index is a property of the person and the stack is a
 * property of the role, and the claim being made is about the role.
 *
 * Only reworded items are read. A verbatim item is the author's own sentence
 * about that role, so there is nothing in it that the role does not support.
 */
class TechnologyToRole implements Check
{
    public function __construct(private readonly FindingMessages $messages) {}

    public function name(): string
    {
        return 'technology_to_role';
    }

    public function run(ValidationContext $context): array
    {
        $findings = [];

        foreach ($context->selection->items() as [$selected, $item]) {
            $role = $selected->id === null ? null : $context->index->role($selected->id);

            if ($item->text === null || $role === null) {
                continue;
            }

            foreach ($context->technologies->detect($item->text) as $technology) {
                if ($technology->isCoveredBy($this->stack($role))) {
                    continue;
                }

                $findings[] = Finding::blocking(
                    $this->name(),
                    $item->field.'.text',
                    $item->id === null ? [] : [$item->id],
                    $this->messages->get('technology_to_role.not_in_stack', [
                        'term' => $technology->name,
                        'role' => $role->employer,
                    ]),
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function stack(Role $role): array
    {
        $entries = [];

        foreach ($role->techStack as $entry) {
            $entries = [...$entries, ...$entry->terms];
        }

        return $entries;
    }
}
