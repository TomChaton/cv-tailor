<?php

namespace App\Validation\Checks;

use App\Generation\RoleModeFinding;
use App\Generation\RoleModeFindingKind;
use App\Generation\RoleModeReview;
use App\Validation\Check;
use App\Validation\Finding;
use App\Validation\FindingMessages;
use App\Validation\ValidationContext;

/**
 * No role is shown in less detail than the presentation policy set for it.
 *
 * Code computes each role's default mode from the policy's two cutoff years.
 * The model may show more of a role and say why; showing less is overriding a
 * decision the owner's policy made, and a CV that quietly drops a role the
 * owner wanted shown is wrong in a way the owner would have to notice for
 * themselves.
 *
 * Blocking, where an unexplained promotion is advisory: a demotion is a
 * decision taken out of the owner's hands, and a missing explanation is only
 * untidy.
 */
class RoleModeIsNotDemoted implements Check
{
    public function __construct(
        private readonly RoleModeReview $review,
        private readonly FindingMessages $messages,
    ) {}

    public function name(): string
    {
        return 'role_mode_not_demoted';
    }

    public function run(ValidationContext $context): array
    {
        $findings = [];

        foreach ($this->review->findings($context->cv, $context->output) as $finding) {
            if ($finding->kind !== RoleModeFindingKind::Demotion) {
                continue;
            }

            $findings[] = Finding::blocking(
                $this->name(),
                null,
                [$finding->roleId],
                $this->message($context, $finding),
            );
        }

        return $findings;
    }

    private function message(ValidationContext $context, RoleModeFinding $finding): string
    {
        return $this->messages->get($this->name().'.demoted', [
            'role' => $context->index->nameOf($finding->roleId),
            'returned' => $finding->returned->value,
            'default' => $finding->default->value,
        ]);
    }
}
