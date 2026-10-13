<?php

namespace App\Validation;

use App\MasterCv\ParsedMasterCv;
use App\Validation\Checks\DoNotClaim;
use App\Validation\Checks\IdsResolve;
use App\Validation\Checks\IndependentProjectsStayIndependent;
use App\Validation\Checks\ItemsBelongToTheirRole;
use App\Validation\Checks\PromotionIsExplained;
use App\Validation\Checks\RewordingsCarrySupport;
use App\Validation\Checks\RoleModeIsNotDemoted;
use App\Validation\Checks\TechnologyToRole;
use App\Validation\Checks\Versions;
use App\Validation\Checks\WordBudget;

/**
 * Runs every check against one revision's output.
 *
 * It takes a parse and an output and returns findings: no database, no log, no
 * status. That is what lets the same checks be run over a revision that
 * already exists, against a master CV version it was not generated from, to
 * see what a change to the CV or to a check would say about it.
 *
 * **Every check runs on every revision**, whatever produced it. The guarantee
 * cannot depend on where the content came from: a manual edit is words the
 * owner typed, which is no better evidenced than words a model wrote.
 */
class Validator
{
    public function __construct(
        private readonly IdsResolve $idsResolve,
        private readonly RewordingsCarrySupport $support,
        private readonly TechnologyToRole $technologyToRole,
        private readonly Versions $versions,
        private readonly DoNotClaim $doNotClaim,
        private readonly ItemsBelongToTheirRole $containment,
        private readonly IndependentProjectsStayIndependent $independence,
        private readonly RoleModeIsNotDemoted $roleMode,
        private readonly PromotionIsExplained $promotions,
        private readonly WordBudget $wordBudget,
    ) {}

    /**
     * @param  array<string, mixed>  $output
     */
    public function validate(ParsedMasterCv $cv, array $output): ValidationReport
    {
        $context = ValidationContext::for($cv, $output);
        $findings = [];

        foreach ($this->checks() as $check) {
            $findings = [...$findings, ...$check->run($context)];
        }

        return new ValidationReport($findings);
    }

    /**
     * @return list<Check>
     */
    private function checks(): array
    {
        return [
            $this->idsResolve,
            $this->support,
            $this->technologyToRole,
            $this->versions,
            $this->doNotClaim,
            $this->containment,
            $this->independence,
            $this->roleMode,
            $this->promotions,
            $this->wordBudget,
        ];
    }
}
