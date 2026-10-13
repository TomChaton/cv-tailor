<?php

namespace App\Validation\Checks;

use App\Validation\Check;
use App\Validation\Finding;
use App\Validation\FindingMessages;
use App\Validation\ValidationContext;

/**
 * An item shown under a role is that role's own.
 *
 * This closes the misattribution the output schema cannot reach. The ID enums
 * guarantee that an item ID exists and is a bullet; scoping them to one role
 * would need a separate object shape and a separate enum per role, so which
 * role a bullet belongs to is checked here instead.
 *
 * It is the quietest fabrication in the system. A **verbatim** bullet from one
 * employer, selected under another, passes every other check — the ID exists,
 * nothing is reworded so nothing need be cited, and both sides are
 * employment — and the CV then states that an achievement happened somewhere it
 * did not. Nothing about the sentence looks wrong, because every word of it is
 * the author's.
 *
 * **Ownership is read from the parse, not from the ID's prefix.** The two agree
 * on every ID `cv:id` writes, and the parse is the fact: IDs are written by
 * hand, so an author may give a bullet a name of its own — and a prefix
 * comparison would block that correct selection.
 *
 * An ID the master CV does not hold is the ID check's to report, and the IDs a
 * rewording cites are not read here at all: evidence legitimately spans the
 * whole document, which is the one crossing the independence check still holds.
 */
class ItemsBelongToTheirRole implements Check
{
    public function __construct(private readonly FindingMessages $messages) {}

    public function name(): string
    {
        return 'items_belong_to_their_role';
    }

    public function run(ValidationContext $context): array
    {
        $findings = [];

        foreach ($context->selection->allRoles() as $selected) {
            $role = $selected->id === null ? null : $context->index->role($selected->id);

            if ($role === null) {
                continue;
            }

            foreach ($selected->items as $item) {
                if ($item->id === null) {
                    continue;
                }

                // A role is not one of its own items. The schema's item enum
                // holds bullets only, so this is a manual edit's to make.
                if ($context->index->role($item->id) !== null) {
                    $findings[] = Finding::blocking(
                        $this->name(),
                        $item->field.'.id',
                        [$item->id],
                        $this->messages->get($this->name().'.not_an_item', ['id' => $item->id]),
                    );

                    continue;
                }

                $owner = $context->index->ownerOf($item->id);

                if ($owner === null || $owner->id === $role->id) {
                    continue;
                }

                $findings[] = Finding::blocking(
                    $this->name(),
                    $item->field.'.id',
                    [$item->id],
                    $this->messages->get($this->name().'.wrong_role', [
                        'id' => $item->id,
                        'source' => $owner->employer,
                        'role' => $role->employer,
                    ]),
                );
            }
        }

        return $findings;
    }
}
