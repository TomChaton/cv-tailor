<?php

namespace App\MasterCv\Lint;

use App\MasterCv\Lint\Checks\BlockLabelsAreRecognised;
use App\MasterCv\Lint\Checks\IdsArePresentAndUnique;
use App\MasterCv\Lint\Checks\RecognisedSectionsPresent;
use App\MasterCv\Lint\Checks\SkillsIndexNamesRealRoles;
use App\MasterCv\Lint\Checks\TechStackIsInSkillsIndex;
use App\MasterCv\ParsedMasterCv;

/**
 * Decides whether a master CV is fit to generate from.
 *
 * Every generation run refuses a CV that fails: the IDs are what constrain the
 * model's output, and a CV whose facts cannot be addressed cannot be selected
 * from safely.
 */
class MasterCvLinter
{
    public function __construct(
        private readonly RecognisedSectionsPresent $sections,
        private readonly IdsArePresentAndUnique $ids,
        private readonly SkillsIndexNamesRealRoles $skillsIndex,
        private readonly TechStackIsInSkillsIndex $techStack,
        private readonly BlockLabelsAreRecognised $blockLabels,
    ) {}

    public function lint(ParsedMasterCv $cv): LintReport
    {
        $findings = [];

        foreach ($this->checks() as $check) {
            $findings = [...$findings, ...$check->run($cv)];
        }

        return new LintReport($findings);
    }

    /**
     * @return list<Check>
     */
    private function checks(): array
    {
        return [
            $this->sections,
            $this->ids,
            $this->skillsIndex,
            $this->techStack,
            $this->blockLabels,
        ];
    }
}
