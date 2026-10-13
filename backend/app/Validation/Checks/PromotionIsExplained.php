<?php

namespace App\Validation\Checks;

use App\Generation\RoleModeFindingKind;
use App\Generation\RoleModeReview;
use App\Validation\Check;
use App\Validation\Finding;
use App\Validation\FindingMessages;
use App\Validation\ValidationContext;

/**
 * A role shown in more detail than its default says why in `review_notes`.
 *
 * Advisory, not blocking. The promotion itself is allowed — the model is the
 * one reading the job description, and giving a role more space for it is the
 * judgement it was asked to make. Leaving the owner to work out why is untidy
 * rather than untrue, and nothing in the CV is a false claim because of it.
 */
class PromotionIsExplained implements Check
{
    public function __construct(
        private readonly RoleModeReview $review,
        private readonly FindingMessages $messages,
    ) {}

    public function name(): string
    {
        return 'promotion_explained';
    }

    public function run(ValidationContext $context): array
    {
        $findings = [];

        foreach ($this->review->findings($context->cv, $context->output) as $finding) {
            if ($finding->kind !== RoleModeFindingKind::PromotionWithoutReason) {
                continue;
            }

            $findings[] = Finding::advisory(
                $this->name(),
                'review_notes.promotions',
                [$finding->roleId],
                $this->messages->get($this->name().'.unexplained', [
                    'role' => $context->index->nameOf($finding->roleId),
                    'returned' => $finding->returned->value,
                    'default' => $finding->default->value,
                ]),
            );
        }

        return $findings;
    }
}
