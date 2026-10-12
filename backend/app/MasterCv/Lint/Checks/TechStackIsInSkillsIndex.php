<?php

namespace App\MasterCv\Lint\Checks;

use App\MasterCv\Lint\Check;
use App\MasterCv\Lint\Finding;
use App\MasterCv\Lint\SkillVocabulary;
use App\MasterCv\ParsedMasterCv;

/**
 * Every technology in a role's tech stack appears in the skills index.
 *
 * The index and the stacks state the same facts from opposite directions and
 * will drift. This check is what stops that: a technology in a stack and
 * missing from the index is one the tailored CV can mention but never trace,
 * and the technology-to-role check is built from the index, so the gap would
 * show up later as a validation failure nobody could explain.
 */
class TechStackIsInSkillsIndex implements Check
{
    public function name(): string
    {
        return 'tech_stack_coverage';
    }

    public function run(ParsedMasterCv $cv): array
    {
        $vocabulary = new SkillVocabulary($cv->allSkills());
        $findings = [];

        foreach ($cv->allRoles() as $role) {
            foreach ($role->techStack as $entry) {
                foreach ($entry->terms as $term) {
                    if ($vocabulary->covers($term)) {
                        continue;
                    }

                    $findings[] = Finding::problem($this->name(), $entry->line, sprintf(
                        '"%s" is in this tech stack but not in the skills index. Add it there, with where it was used.',
                        $term,
                    ));
                }
            }
        }

        return $findings;
    }
}
