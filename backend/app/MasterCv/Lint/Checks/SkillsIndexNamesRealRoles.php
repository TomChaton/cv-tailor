<?php

namespace App\MasterCv\Lint\Checks;

use App\MasterCv\Lint\Check;
use App\MasterCv\Lint\Finding;
use App\MasterCv\Lint\RoleNames;
use App\MasterCv\ParsedMasterCv;

/**
 * A skills-index row's "where used" names a role or project that exists.
 *
 * A row naming nothing in the file is a problem: it is a skill with no
 * evidence behind it, and a tailored CV would be unable to say where it came
 * from. A row that names something real and also something that is not — an
 * employer since renamed, a role since deleted — is a note, because the
 * evidence is still there and the author may well have meant the prose.
 */
class SkillsIndexNamesRealRoles implements Check
{
    public function name(): string
    {
        return 'skills_index_references';
    }

    public function run(ParsedMasterCv $cv): array
    {
        $names = new RoleNames($cv->allRoles());
        $findings = [];

        foreach ($cv->allSkills() as $skill) {
            // A row that says nothing about where it was used is claiming no
            // evidence to check. The list form of the index is full of them.
            if ($skill->whereUsed === null) {
                continue;
            }

            if (! $names->namesAny($skill->whereUsed)) {
                $findings[] = Finding::problem($this->name(), $skill->line, sprintf(
                    '"Where used" for "%s" names no role or project in this file.',
                    $skill->name,
                ));

                continue;
            }

            foreach ($names->unmatched($skill->whereUsed) as $part) {
                $findings[] = Finding::note($this->name(), $skill->line, sprintf(
                    '"%s" is not a role or project in this file.',
                    $part,
                ));
            }
        }

        return $findings;
    }
}
