<?php

namespace App\MasterCv\Lint\Checks;

use App\MasterCv\Achievement;
use App\MasterCv\Lint\Check;
use App\MasterCv\Lint\Finding;
use App\MasterCv\ParsedMasterCv;
use App\MasterCv\Role;
use App\MasterCv\RoleKind;

/**
 * Every selectable unit has an ID, the IDs are unique, and each is well formed.
 *
 * The IDs are what the model's output schema is built from, so an invented ID
 * is impossible rather than merely detectable. A CV missing one cannot be
 * generated from at all.
 */
class IdsArePresentAndUnique implements Check
{
    /**
     * Lower case, numbers and hyphens within a level; dots between levels.
     */
    private const WELL_FORMED = '/^[a-z0-9]+(-[a-z0-9]+)*(\.[a-z0-9]+(-[a-z0-9]+)*)*$/';

    public function name(): string
    {
        return 'ids';
    }

    public function run(ParsedMasterCv $cv): array
    {
        $findings = [];

        /** @var array<string, int> $seen ID to the line it was first seen on. */
        $seen = [];

        foreach ($cv->allRoles() as $role) {
            $kind = $role->kind === RoleKind::Independent ? 'independent project' : 'role';

            $findings = [
                ...$findings,
                ...$this->unit($role->id, $role->line, $kind, null, $seen),
            ];

            foreach ($role->achievements as $achievement) {
                $findings = [...$findings, ...$this->achievement($achievement, $role, $role->id, $seen)];
            }
        }

        foreach ($cv->allSkills() as $skill) {
            if ($skill->id === '') {
                $findings[] = Finding::problem(
                    $this->name(),
                    $skill->line,
                    'This skill\'s name has no letters or numbers in it, so no ID can be derived from it.',
                );

                continue;
            }

            if (isset($seen[$skill->id])) {
                $findings[] = Finding::problem($this->name(), $skill->line, sprintf(
                    'The ID "%s" is already used on line %d. A skill\'s ID comes from its name, so two skills cannot share one.',
                    $skill->id,
                    $seen[$skill->id],
                ));

                continue;
            }

            $seen[$skill->id] = $skill->line;
        }

        return $findings;
    }

    /**
     * @param  array<string, int>  $seen
     * @return list<Finding>
     */
    private function achievement(Achievement $achievement, Role $role, ?string $parentId, array &$seen): array
    {
        $kind = $parentId === $role->id ? 'achievement' : 'sub-bullet';

        $findings = $this->unit($achievement->id, $achievement->line, $kind, $parentId, $seen);

        foreach ($achievement->children as $child) {
            $findings = [...$findings, ...$this->achievement($child, $role, $achievement->id, $seen)];
        }

        return $findings;
    }

    /**
     * @param  array<string, int>  $seen
     * @return list<Finding>
     */
    private function unit(?string $id, int $line, string $kind, ?string $parentId, array &$seen): array
    {
        if ($id === null) {
            return [Finding::problem($this->name(), $line, sprintf(
                'This %s has no ID. Run "php artisan cv:id" to add the ones that are missing.',
                $kind,
            ))];
        }

        $findings = [];

        if (preg_match(self::WELL_FORMED, $id) !== 1) {
            $findings[] = Finding::problem($this->name(), $line, sprintf(
                'The ID "%s" is not well formed. Use lower-case letters, numbers and hyphens, with a dot between levels.',
                $id,
            ));
        }

        if (isset($seen[$id])) {
            $findings[] = Finding::problem($this->name(), $line, sprintf(
                'The ID "%s" is already used on line %d. IDs have to be unique across the whole file.',
                $id,
                $seen[$id],
            ));
        } else {
            $seen[$id] = $line;
        }

        if ($parentId !== null && ! $this->isChildOf($id, $parentId)) {
            $findings[] = Finding::problem($this->name(), $line, sprintf(
                'The ID "%s" sits under "%s", so it should be "%s" followed by a dot and one more level.',
                $id,
                $parentId,
                $parentId,
            ));
        }

        return $findings;
    }

    private function isChildOf(string $id, string $parentId): bool
    {
        if (! str_starts_with($id, $parentId.'.')) {
            return false;
        }

        return ! str_contains(substr($id, strlen($parentId) + 1), '.');
    }
}
