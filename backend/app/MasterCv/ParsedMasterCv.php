<?php

namespace App\MasterCv;

/**
 * The master CV as three kinds of content: rules, facts and identity.
 *
 * The file mixes them; nothing downstream should have to. `withoutIdentity()`
 * is the only material that may reach the model, and the contact section is
 * reachable in exactly one place so that leaking it is a deliberate act rather
 * than an oversight.
 */
final readonly class ParsedMasterCv
{
    /**
     * @param  array<array-key, mixed>  $frontMatter
     * @param  list<string>  $headlineTitles
     * @param  list<string>  $profileFacts
     * @param  list<SkillGroup>  $skillGroups
     * @param  list<Role>  $roles
     * @param  list<Role>  $independentProjects
     * @param  list<string>  $awards
     * @param  list<string>  $interests
     * @param  list<Section>  $sectionsPresent
     */
    public function __construct(
        public array $frontMatter,
        public PresentationPolicy $presentation,
        public RuleBlock $agentRules,
        public RuleBlock $doNotClaim,
        public Contact $contact,
        public array $headlineTitles,
        public array $profileFacts,
        public array $skillGroups,
        public array $roles,
        public array $independentProjects,
        public array $awards,
        public array $interests,
        public array $sectionsPresent,
    ) {}

    public function has(Section $section): bool
    {
        return in_array($section, $this->sectionsPresent, true);
    }

    /**
     * @return list<Role> Employment and independent projects together, for the
     *                    checks that care about every entry.
     */
    public function allRoles(): array
    {
        return [...$this->roles, ...$this->independentProjects];
    }

    /**
     * @return list<Skill>
     */
    public function allSkills(): array
    {
        $skills = [];

        foreach ($this->skillGroups as $group) {
            $skills = [...$skills, ...$group->skills];
        }

        return $skills;
    }

    /**
     * Counts per kind, for the parse log event. A parser regression that
     * silently drops half the file shows up here and nowhere else.
     *
     * Written and derived skills are counted apart. A single total would hide
     * the symptom that matters: a derivation producing a wildly different
     * number from one parse to the next, which a total absorbs because every
     * row the index loses the stacks give back.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $achievements = 0;
        $subBullets = 0;

        foreach ($this->allRoles() as $role) {
            foreach ($role->achievements as $achievement) {
                $achievements++;
                $subBullets += count($achievement->withDescendants()) - 1;
            }
        }

        $written = 0;
        $derived = 0;

        foreach ($this->allSkills() as $skill) {
            $skill->derived ? $derived++ : $written++;
        }

        return [
            'roles' => count($this->roles),
            'projects' => count($this->independentProjects),
            'achievements' => $achievements,
            'sub_bullets' => $subBullets,
            'skills_written' => $written,
            'skills_derived' => $derived,
        ];
    }

    /**
     * A stable hash of the parsed structure.
     *
     * The parse rather than the file is hashed, so what identifies a version
     * is what was actually used rather than the bytes it was read from. Two
     * CVs that parse to the same thing are one version; a change the parser
     * can see — including a line number, since lint and the review UI address
     * facts by line — is a new one. Erring towards a new version costs a row
     * and never misattributes a revision.
     */
    public function hash(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'front_matter' => $this->frontMatter,
            'contact' => $this->contact->toArray(),
            ...$this->withoutIdentity(),
        ];
    }

    /**
     * Everything except identity: the only material that may be sent to the
     * model.
     *
     * The front matter goes with the contact section rather than with the
     * facts. It is document bookkeeping — who the CV is for, when it was last
     * touched — and the model has no use for any of it; only the presentation
     * policy derived from it is needed.
     *
     * @return array<string, mixed>
     */
    public function withoutIdentity(): array
    {
        return [
            'presentation' => $this->presentation->toArray(),
            'agent_rules' => $this->agentRules->toArray(),
            'do_not_claim' => $this->doNotClaim->toArray(),
            'headline_titles' => $this->headlineTitles,
            'profile_facts' => $this->profileFacts,
            'skills_index' => array_map(
                static fn (SkillGroup $group): array => $group->toArray(),
                $this->skillGroups,
            ),
            'roles' => array_map(static fn (Role $role): array => $role->toArray(), $this->roles),
            'independent_projects' => array_map(
                static fn (Role $role): array => $role->toArray(),
                $this->independentProjects,
            ),
            'awards' => $this->awards,
            'interests' => $this->interests,
            'sections_present' => array_map(
                static fn (Section $section): string => $section->value,
                $this->sectionsPresent,
            ),
        ];
    }
}
