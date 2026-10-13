<?php

namespace App\Rendering;

use App\MasterCv\RoleMode;

/**
 * The finished document: a selection resolved against the master CV, with no
 * IDs left to look up and nothing further to fetch.
 *
 * **It is format-agnostic, and it is the only input to rendering.** Nothing
 * here knows about HTML, pages or paper, so a second output format is a second
 * template rather than a second pipeline. Anything a renderer would have to
 * decide for itself — which wording a rewording put on the page, what order the
 * roles go in, whether a condensed role keeps its prose — is already decided.
 */
final readonly class ResolvedDocument
{
    /**
     * @param  list<ResolvedSkill>  $skills
     * @param  list<ResolvedRole>  $roles
     * @param  list<ResolvedRole>  $independentProjects
     * @param  list<string>  $awards
     * @param  list<string>  $interests
     * @param  int  $rolesOmitted  Roles and projects the master CV version
     *                             holds that this document does not show. Not
     *                             part of the document: it is how a resolver
     *                             that silently drops half a CV is noticed.
     */
    public function __construct(
        public ResolvedHeader $header,
        public ?string $summary,
        public array $skills,
        public array $roles,
        public array $independentProjects,
        public array $awards,
        public array $interests,
        public int $rolesOmitted = 0,
    ) {}

    /**
     * Employment and independent projects together.
     *
     * @return list<ResolvedRole>
     */
    public function allRoles(): array
    {
        return [...$this->roles, ...$this->independentProjects];
    }

    /**
     * Counts for the log line.
     *
     * `roles_included` and `roles_omitted` together account for every role and
     * project in the master CV version; `roles_condensed` says how many of the
     * included ones are shown in less than full detail.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $condensed = 0;
        $items = 0;
        $reworded = 0;

        foreach ($this->allRoles() as $role) {
            if ($role->mode === RoleMode::Condensed) {
                $condensed++;
            }

            foreach ($role->allItems() as $item) {
                $items++;

                if ($item->reworded) {
                    $reworded++;
                }
            }
        }

        return [
            'roles_included' => count($this->allRoles()),
            'roles_condensed' => $condensed,
            'roles_omitted' => $this->rolesOmitted,
            'items' => $items,
            'reworded_items' => $reworded,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'header' => $this->header->toArray(),
            'summary' => $this->summary,
            'skills' => array_map(
                static fn (ResolvedSkill $skill): array => $skill->toArray(),
                $this->skills,
            ),
            'roles' => array_map(
                static fn (ResolvedRole $role): array => $role->toArray(),
                $this->roles,
            ),
            'independent_projects' => array_map(
                static fn (ResolvedRole $role): array => $role->toArray(),
                $this->independentProjects,
            ),
            'awards' => $this->awards,
            'interests' => $this->interests,
        ];
    }
}
