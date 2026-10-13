<?php

namespace App\Validation\Checks;

use App\Validation\Check;
use App\Validation\Finding;
use App\Validation\FindingMessages;
use App\Validation\Output\IdReference;
use App\Validation\ValidationContext;

/**
 * Every ID the output names is in the master CV version it was generated
 * from, and names content that may be used.
 *
 * The output schema's enums make an invented ID impossible for a generated
 * revision, so this check earns its place twice over: a manual edit never met
 * the schema, and a revision generated from one master CV version can be
 * re-read against another, where an ID the author has since reworded no longer
 * resolves.
 *
 * Content marked `TODO` fails as surely as a missing ID. The author has said
 * the fact is unknown, so an output that names it has filled in a blank.
 *
 * **It owns the IDs that name what is shown.** The IDs a rewording cites are
 * checked by the rewording check, which has more to say about them, so the two
 * between them cover every ID in the output exactly once: a finding repeated
 * in two vocabularies tells the owner nothing twice.
 */
class IdsResolve implements Check
{
    public function __construct(private readonly FindingMessages $messages) {}

    public function name(): string
    {
        return 'ids_resolve';
    }

    public function run(ValidationContext $context): array
    {
        $findings = [];

        foreach ($this->references($context) as $reference) {
            if ($context->index->isUnknown($reference->id)) {
                $findings[] = Finding::blocking(
                    $this->name(),
                    $reference->field,
                    [$reference->id],
                    $this->messages->get('ids_resolve.unknown', ['id' => $reference->id]),
                );

                continue;
            }

            if (! $context->index->resolves($reference->id)) {
                $findings[] = Finding::blocking(
                    $this->name(),
                    $reference->field,
                    [$reference->id],
                    $this->messages->get('ids_resolve.missing', ['id' => $reference->id]),
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<IdReference>
     */
    private function references(ValidationContext $context): array
    {
        $selection = $context->selection;
        $references = [...$selection->keySkills, ...$selection->evidence, ...$selection->promotions];

        foreach ($selection->allRoles() as $role) {
            if ($role->id !== null) {
                $references[] = new IdReference($role->id, $role->field.'.id');
            }

            foreach ($role->items as $item) {
                if ($item->id !== null) {
                    $references[] = new IdReference($item->id, $item->field.'.id');
                }
            }
        }

        return $references;
    }
}
