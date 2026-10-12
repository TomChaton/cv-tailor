<?php

namespace App\MasterCv\Lint\Checks;

use App\MasterCv\Lint\Check;
use App\MasterCv\Lint\Finding;
use App\MasterCv\ParsedMasterCv;
use App\MasterCv\Section;

class RecognisedSectionsPresent implements Check
{
    public function name(): string
    {
        return 'sections';
    }

    public function run(ParsedMasterCv $cv): array
    {
        $findings = [];

        foreach (Section::cases() as $section) {
            if ($section->isRequired() && ! $cv->has($section)) {
                $findings[] = Finding::problem(
                    $this->name(),
                    null,
                    sprintf('There is no "%s" section. Add one, under a heading of that name.', $section->label()),
                );
            }
        }

        return $findings;
    }
}
