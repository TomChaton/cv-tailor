<?php

namespace App\MasterCv\Lint\Checks;

use App\MasterCv\Lint\Check;
use App\MasterCv\Lint\Finding;
use App\MasterCv\ParsedMasterCv;
use App\MasterCv\Skill;
use App\MasterCv\SkillName;

/**
 * Two skills whose names differ only in how they are written.
 *
 * The index is completed from the tech stacks, so a technology two roles spell
 * differently becomes two skills with two IDs, and a CV then offers the same
 * thing twice. This points that out.
 *
 * **It is a note and never a problem.** Two similar names are sometimes two
 * things, and only the author knows which — so the CV is never failed for it.
 */
class SkillNamesAreDistinct implements Check
{
    public function name(): string
    {
        return 'skill_names';
    }

    public function run(ParsedMasterCv $cv): array
    {
        $findings = [];

        /** @var list<array{skill: Skill, words: list<string>, bare: list<string>}> $seen */
        $seen = [];

        foreach ($cv->allSkills() as $skill) {
            $words = SkillName::tokens($skill->name);
            sort($words);

            if ($words === []) {
                continue;
            }

            $bare = SkillName::withoutVersions($words);

            foreach ($seen as $earlier) {
                if (! self::sameThingTwice($words, $bare, $earlier['words'], $earlier['bare'])) {
                    continue;
                }

                $findings[] = Finding::note($this->name(), $skill->line, sprintf(
                    '"%s" and "%s" on line %d differ only in how they are written.',
                    $skill->name,
                    $earlier['skill']->name,
                    $earlier['skill']->line,
                ));
            }

            $seen[] = ['skill' => $skill, 'words' => $words, 'bare' => $bare];
        }

        return $findings;
    }

    /**
     * Either the two names are the same words — so they differ by case,
     * punctuation or a plural — or one is the other with a version pinned to
     * it.
     *
     * A version only decides this when the other side gives none. Two names
     * that each pin one are two deliberate statements: "PHP 7.x (7.0, 7.4)"
     * and "PHP 8.x (8.3)" are not one skill written twice, and reporting them
     * would make the check noise on any CV spanning a decade.
     *
     * @param  list<string>  $words
     * @param  list<string>  $bare
     * @param  list<string>  $earlier
     * @param  list<string>  $earlierBare
     */
    private static function sameThingTwice(array $words, array $bare, array $earlier, array $earlierBare): bool
    {
        if ($words === $earlier) {
            return true;
        }

        return ($words === $bare && $words === $earlierBare)
            || ($earlier === $earlierBare && $earlier === $bare);
    }
}
