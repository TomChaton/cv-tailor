<?php

namespace App\Rendering;

use App\Generation\SelectableIds;
use App\MasterCv\ParsedMasterCv;
use App\MasterCv\Role;

/**
 * The whole master CV as a selection, selecting nothing and rewording nothing.
 *
 * Every role at the mode the presentation policy gives it, every selectable
 * bullet of each, verbatim, and the CV's first headline title. It is the
 * question "what does the template do with my CV?" asked in the only shape the
 * pipeline understands, so the answer comes back through the same resolver,
 * the same checks and the same template a generated revision does.
 *
 * It is not a stand-in for a model. There is no summary, because the master CV
 * has none, and nothing here is free text: every word of the result is the
 * author's own.
 */
final class VerbatimSelection
{
    /**
     * @return array<string, mixed>
     */
    public static function of(ParsedMasterCv $cv): array
    {
        $ids = SelectableIds::from($cv);

        return [
            'jd_analysis' => [],
            'headline' => $ids->headlineTitles[0] ?? null,
            'summary' => null,
            'key_skills' => $ids->skills,
            'roles' => self::group($cv->roles),
            'independent_projects' => self::group($cv->independentProjects),
            'review_notes' => ['gaps' => [], 'judgement_calls' => [], 'promotions' => []],
        ];
    }

    /**
     * @param  list<Role>  $roles
     * @return list<array<string, mixed>>
     */
    private static function group(array $roles): array
    {
        $group = [];

        foreach ($roles as $role) {
            if ($role->id === null) {
                continue;
            }

            $items = [];

            foreach ($role->allAchievements() as $achievement) {
                if ($achievement->selectable && $achievement->id !== null) {
                    $items[] = ['id' => $achievement->id, 'text' => null, 'supports' => []];
                }
            }

            $group[] = [
                'id' => $role->id,
                // The policy's own default, so nothing here promotes a role the
                // author's settings had condensed or dropped.
                'mode' => $role->defaultMode->value,
                'items' => $items,
            ];
        }

        return $group;
    }
}
