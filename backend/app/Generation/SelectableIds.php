<?php

namespace App\Generation;

use App\MasterCv\ContentMarker;
use App\MasterCv\ParsedMasterCv;

/**
 * Every master CV ID the model may name, and every headline title it may
 * choose from.
 *
 * These become the enums in the output schema, so an ID that is not here
 * cannot come back at all: the provider refuses it rather than a validator
 * catching it afterwards.
 *
 * **One list, not one per field.** The guarantee an enum buys is that an ID
 * exists in the master CV. It is not that the ID belongs where it was used: a
 * real achievement filed under the wrong employer is a misattribution, and
 * catching that is the validators' work, not the schema's.
 *
 * **`TODO` content is left out entirely** rather than offered and flagged. The
 * author has said the fact is unknown, so there is nothing to offer, and
 * anything selectable is eventually selected.
 */
final readonly class SelectableIds
{
    /**
     * @param  list<string>  $ids
     * @param  list<string>  $headlineTitles
     */
    private function __construct(
        public array $ids,
        public array $headlineTitles,
    ) {}

    public static function from(ParsedMasterCv $cv): self
    {
        $ids = [];

        foreach ($cv->allRoles() as $role) {
            if ($role->id !== null) {
                $ids[] = $role->id;
            }

            foreach ($role->allAchievements() as $achievement) {
                if ($achievement->selectable && $achievement->id !== null) {
                    $ids[] = $achievement->id;
                }
            }
        }

        foreach ($cv->allSkills() as $skill) {
            if ($skill->selectable && $skill->id !== '') {
                $ids[] = $skill->id;
            }
        }

        return new self(array_values(array_unique($ids)), self::titles($cv));
    }

    public function count(): int
    {
        return count($this->ids);
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
