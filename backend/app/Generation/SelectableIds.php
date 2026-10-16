<?php

namespace App\Generation;

use App\MasterCv\ContentMarker;
use App\MasterCv\ParsedMasterCv;
use App\MasterCv\RoleKind;

/**
 * Every master CV ID the model may name, by kind, and every headline title it
 * may choose from.
 *
 * The kind-scoped lists become the enums in the output schema, so an ID that
 * is not in the one its field takes cannot come back at all: the provider
 * refuses it rather than a validator catching it afterwards.
 *
 * **The lists are kept apart by kind** because the fields that take them are.
 * A skill ID in `key_skills` and an employment role ID in `roles` are
 * different questions, and one list of every ID would answer neither: it would
 * accept a skill where a role belongs and leave the nonsense for a later step
 * to notice.
 *
 * An enum therefore guarantees that an ID **exists** in the master CV and, for
 * a kind-scoped field, that it is **of the right kind**. It does not guarantee
 * that the ID belongs where it was used: an achievement of one employer's
 * filed under another is a misattribution, and catching that is the
 * validators' work.
 *
 * **`all` is a measurement, not an enum.** The provider compiles the schema
 * and refuses one that is too complex, so the number of IDs a CV offers is a
 * budget; `all` is what `prompt.built` reports as `enum_count` so that budget
 * can be watched. No field in the schema takes it — see `OutputSchema` for why
 * evidence does not.
 *
 * **`TODO` content is left out entirely** rather than offered and flagged. The
 * author has said the fact is unknown, so there is nothing to offer, and
 * anything selectable is eventually selected.
 */
final readonly class SelectableIds
{
    /**
     * @param  list<string>  $all  Every ID below, in the order the CV gives
     *                             them. Counted, never offered as an enum.
     * @param  list<string>  $employmentRoles
     * @param  list<string>  $independentProjects
     * @param  list<string>  $items  Achievements and sub-bullets, of both.
     * @param  list<string>  $skills
     * @param  list<string>  $headlineTitles
     */
    private function __construct(
        public array $all,
        public array $employmentRoles,
        public array $independentProjects,
        public array $items,
        public array $skills,
        public array $headlineTitles,
    ) {}

    public static function from(ParsedMasterCv $cv): self
    {
        $all = [];
        $employmentRoles = [];
        $independentProjects = [];
        $items = [];
        $skills = [];

        foreach ($cv->allRoles() as $role) {
            if ($role->id !== null) {
                $all[] = $role->id;

                if ($role->kind === RoleKind::Employment) {
                    $employmentRoles[] = $role->id;
                } else {
                    $independentProjects[] = $role->id;
                }
            }

            foreach ($role->allAchievements() as $achievement) {
                if ($achievement->selectable && $achievement->id !== null) {
                    $all[] = $achievement->id;
                    $items[] = $achievement->id;
                }
            }
        }

        foreach ($cv->allSkills() as $skill) {
            if ($skill->selectable && $skill->id !== '') {
                $all[] = $skill->id;
                $skills[] = $skill->id;
            }
        }

        return new self(
            all: array_values(array_unique($all)),
            employmentRoles: array_values(array_unique($employmentRoles)),
            independentProjects: array_values(array_unique($independentProjects)),
            items: array_values(array_unique($items)),
            skills: array_values(array_unique($skills)),
            headlineTitles: self::titles($cv),
        );
    }

    /**
     * The things a role mode can be promoted on: an employment role or an
     * independent project, and never a bullet or a skill.
     *
     * @return list<string>
     */
    public function promotable(): array
    {
        return [...$this->employmentRoles, ...$this->independentProjects];
    }

    public function count(): int
    {
        return count($this->all);
    }

    /**
     * The titles on offer.
     *
     * The front matter's list narrows the headline titles section and cannot
     * add to it: a title appearing nowhere in the CV's own list of titles is a
     * claim with nothing behind it. A front matter list that narrows the
     * section to nothing is read as having said nothing at all, which is what
     * an absent list means too.
     *
     * @return list<string>
     */
    private static function titles(ParsedMasterCv $cv): array
    {
        $available = array_values(array_filter(
            $cv->headlineTitles,
            static fn (string $title): bool => ! in_array(
                ContentMarker::Todo,
                ContentMarker::detect($title),
                true,
            ),
        ));

        $chosen = $cv->presentation->headlineTitles;

        if ($chosen === null) {
            return $available;
        }

        $narrowed = array_values(array_intersect($available, $chosen));

        return $narrowed === [] ? $available : $narrowed;
    }
}
