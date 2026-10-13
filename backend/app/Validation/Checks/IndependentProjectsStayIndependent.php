<?php

namespace App\Validation\Checks;

use App\MasterCv\RoleKind;
use App\Validation\Check;
use App\Validation\Finding;
use App\Validation\FindingMessages;
use App\Validation\Output\SelectedRole;
use App\Validation\ValidationContext;

/**
 * Independent work is not presented as employment, and employment is not
 * presented as independent work.
 *
 * An independent project and a role are written identically in the master CV
 * and differ only in what may be claimed from them. The CV's own rules say so:
 * technologies used only in an independent project are personal work, never
 * commercial experience at an employer. A project's bullet filed under an
 * employer claims a client paid for it.
 *
 * The output schema puts the two in containers with shapes of their own, so a
 * generated revision cannot name a project where a role belongs. This check is
 * what covers the rest: the items inside a container, the IDs a rewording
 * cites, and a manual edit, which has met no schema at all.
 */
class IndependentProjectsStayIndependent implements Check
{
    public function __construct(private readonly FindingMessages $messages) {}

    public function name(): string
    {
        return 'independent_projects_stay_independent';
    }

    public function run(ValidationContext $context): array
    {
        $findings = [];

        foreach ($context->selection->allRoles() as $selected) {
            $container = $this->container($context, $selected);

            if ($container !== null) {
                // The container is the wrong kind, so every item under it is
                // too. Saying that once is enough.
                $findings[] = $container;

                continue;
            }

            foreach ($selected->items as $item) {
                if ($item->id !== null) {
                    $findings = [...$findings, ...$this->misfiled(
                        $context,
                        $selected,
                        $item->id,
                        $item->field.'.id',
                    )];
                }

                foreach ($item->supports as $support) {
                    $findings = [...$findings, ...$this->misfiled(
                        $context,
                        $selected,
                        $support->id,
                        $support->field,
                    )];
                }
            }
        }

        return $findings;
    }

    /**
     * The container's own ID names something of the container's kind.
     */
    private function container(ValidationContext $context, SelectedRole $selected): ?Finding
    {
        if ($selected->id === null) {
            return null;
        }

        $role = $context->index->role($selected->id);

        // An ID that names no role at all is the ID check's to report.
        if ($role === null || $role->kind === $selected->container) {
            return null;
        }

        $key = $selected->container === RoleKind::Employment ? 'not_an_employer' : 'not_a_project';

        return Finding::blocking(
            $this->name(),
            $selected->field.'.id',
            [$selected->id],
            $this->messages->get($this->name().'.'.$key, ['id' => $selected->id]),
        );
    }

    /**
     * @return list<Finding>
     */
    private function misfiled(
        ValidationContext $context,
        SelectedRole $selected,
        string $id,
        string $field,
    ): array {
        $owner = $context->index->ownerOf($id);

        // A skill belongs to no role: the skills index is a property of the
        // document rather than of an employer.
        if ($owner === null || $owner->kind === $selected->container) {
            return [];
        }

        $key = $selected->container === RoleKind::Employment ? 'under_employer' : 'under_project';

        return [Finding::blocking(
            $this->name(),
            $field,
            [$id],
            $this->messages->get($this->name().'.'.$key, ['id' => $id, 'source' => $owner->employer]),
        )];
    }
}
