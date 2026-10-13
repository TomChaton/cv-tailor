<?php

namespace App\Validation;

use App\MasterCv\ParsedMasterCv;
use App\MasterCv\Role;

/**
 * The versions a piece of the master CV records for each technology.
 *
 * Built per role, because a version is a fact about a role and not about the
 * person: one role ran PHP 7.4 and another runs 8.3, and a tailored CV that
 * moves either of those is claiming work that was not done.
 *
 * **A technology the source pins no version to has no version to offer.** The
 * master CV's own rule is that a version absent from the file is not to be
 * added, so an empty set of recorded versions refuses every version rather
 * than accepting any.
 */
final readonly class VersionFacts
{
    /**
     * @param  list<string>  $source  The text a version may be read from.
     */
    private function __construct(private array $source) {}

    /**
     * One role's own material: its tech stack, its context and its own
     * bullets. Nothing from another role, and nothing from the skills index,
     * which states versions for the document rather than for a role.
     */
    public static function forRole(Role $role): self
    {
        $source = [];

        foreach ($role->techStack as $entry) {
            $source = [...$source, ...$entry->terms];
        }

        if ($role->context !== null) {
            $source[] = $role->context;
        }

        foreach ($role->allAchievements() as $achievement) {
            $source[] = $achievement->text;
        }

        return new self($source);
    }

    /**
     * Everything the document records, for output that belongs to no single
     * role.
     */
    public static function forDocument(ParsedMasterCv $cv): self
    {
        $source = [];

        foreach ($cv->allRoles() as $role) {
            $source = [...$source, ...self::forRole($role)->source];
        }

        foreach ($cv->allSkills() as $skill) {
            $source[] = $skill->name;
        }

        return new self($source);
    }

    public function records(Technology $technology, string $version): bool
    {
        return in_array($version, $this->versionsFor($technology), true);
    }

    /**
     * @return list<string>
     */
    public function versionsFor(Technology $technology): array
    {
        $versions = [];

        foreach ($this->source as $text) {
            $versions = [...$versions, ...$technology->versionsIn($text)];
        }

        return array_values(array_unique($versions));
    }
}
