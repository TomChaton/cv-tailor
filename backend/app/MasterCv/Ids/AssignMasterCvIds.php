<?php

namespace App\MasterCv\Ids;

use App\MasterCv\Achievement;
use App\MasterCv\MasterCvParser;
use App\MasterCv\Role;
use App\MasterCv\RoleKind;
use Illuminate\Support\Str;

/**
 * Writes the IDs a master CV is missing, once.
 *
 * IDs are hand-written and never derived from content: rewording a bullet must
 * not change its identity, or revision history loses track of it. A CV written
 * before this tool existed has none at all, though, and nothing can be
 * generated from it until it does — so this bootstraps them and then has
 * nothing left to do.
 *
 * A role takes a slug of its employer, because an employer's name is its
 * identity in the same way a skill's name is. A bullet takes a number. Nothing
 * about a bullet's wording is in its ID, so no rewording can make the ID look
 * wrong and tempt the author into "fixing" it — which would be the one change
 * that breaks the history.
 *
 * It never writes into the stored CV. Which of the IDs a tool proposes the
 * author adopts is theirs to decide, so the whole document is handed back and
 * they read a diff.
 */
class AssignMasterCvIds
{
    public function __construct(private readonly MasterCvParser $parser) {}

    public function handle(string $content): IdAssignment
    {
        $cv = $this->parser->parse($content);

        /** @var array<string, true> $taken */
        $taken = [];

        foreach ($cv->allSkills() as $skill) {
            $taken[$skill->id] = true;
        }

        foreach ($cv->allRoles() as $role) {
            foreach ([$role->id, ...array_map(
                static fn (Achievement $a): ?string => $a->id,
                $role->allAchievements(),
            )] as $id) {
                if ($id !== null) {
                    $taken[$id] = true;
                }
            }
        }

        /** @var array<int, string> $assignments Line number to the ID to add. */
        $assignments = [];
        $added = ['roles' => 0, 'projects' => 0, 'achievements' => 0, 'sub_bullets' => 0];

        foreach ($cv->allRoles() as $role) {
            $roleId = $role->id;

            if ($roleId === null) {
                $roleId = $this->unique($this->slugFor($role), $taken);
                $assignments[$role->line] = $roleId;
                $taken[$roleId] = true;
                $added[$role->kind === RoleKind::Independent ? 'projects' : 'roles']++;
            }

            $this->assignToBullets($role->achievements, $roleId, $taken, $assignments, $added, 'achievements');
        }

        return new IdAssignment($this->rewrite($content, $assignments), $added);
    }

    /**
     * @param  list<Achievement>  $bullets
     * @param  array<string, true>  $taken
     * @param  array<int, string>  $assignments
     * @param  array<string, int>  $added
     */
    private function assignToBullets(
        array $bullets,
        string $parentId,
        array &$taken,
        array &$assignments,
        array &$added,
        string $countedAs,
    ): void {
        $next = $this->nextNumber($bullets);

        foreach ($bullets as $bullet) {
            $id = $bullet->id;

            if ($id === null) {
                do {
                    $id = $parentId.'.'.$next;
                    $next++;
                } while (isset($taken[$id]));

                $assignments[$bullet->line] = $id;
                $taken[$id] = true;
                $added[$countedAs]++;
            }

            $this->assignToBullets($bullet->children, $id, $taken, $assignments, $added, 'sub_bullets');
        }
    }

    /**
     * The first number free among a set of siblings.
     *
     * Counting on from the highest rather than filling the lowest gap: a
     * number the author deleted stays deleted, so an ID is never reused for
     * something else.
     *
     * @param  list<Achievement>  $bullets
     */
    private function nextNumber(array $bullets): int
    {
        $highest = 0;

        foreach ($bullets as $bullet) {
            $segment = $bullet->id === null ? '' : Str::afterLast($bullet->id, '.');

            if (ctype_digit($segment)) {
                $highest = max($highest, (int) $segment);
            }
        }

        return $highest + 1;
    }

    private function slugFor(Role $role): string
    {
        $employer = (string) preg_replace('/\([^()]*\)/u', ' ', $role->employer);

        foreach ([$employer, $role->heading, 'role'] as $candidate) {
            $slug = Str::slug($candidate);

            if ($slug !== '') {
                return $slug;
            }
        }

        return 'role';
    }

    /**
     * @param  array<string, true>  $taken
     */
    private function unique(string $slug, array $taken): string
    {
        if (! isset($taken[$slug])) {
            return $slug;
        }

        $suffix = 2;

        while (isset($taken[$slug.'-'.$suffix])) {
            $suffix++;
        }

        return $slug.'-'.$suffix;
    }

    /**
     * @param  array<int, string>  $assignments
     */
    private function rewrite(string $content, array $assignments): string
    {
        if ($assignments === []) {
            return $content;
        }

        $lines = explode("\n", $content);

        foreach ($assignments as $number => $id) {
            $index = $number - 1;

            if (! isset($lines[$index])) {
                continue;
            }

            // A trailing carriage return is put back so a CRLF file stays one.
            $carriageReturn = str_ends_with($lines[$index], "\r") ? "\r" : '';

            $lines[$index] = rtrim($lines[$index], " \t\r").' {#'.$id.'}'.$carriageReturn;
        }

        return implode("\n", $lines);
    }
}
