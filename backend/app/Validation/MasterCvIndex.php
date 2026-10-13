<?php

namespace App\Validation;

use App\MasterCv\Achievement;
use App\MasterCv\ParsedMasterCv;
use App\MasterCv\Role;
use App\MasterCv\Skill;

/**
 * Every addressable fact in one master CV version, by ID.
 *
 * The checks ask two different questions of an ID and the difference matters:
 * whether the CV holds it at all, and whether the author left it available to
 * be used. Content marked `TODO` is held and unavailable — the author said the
 * fact is unknown — so an output that names it has filled in a blank rather
 * than cited a fact.
 *
 * It also answers which role owns an item, which is what makes a
 * misattribution visible: the IDs are hierarchical, but an ID's prefix is a
 * convention and the parse is the fact, so ownership is read from the parse.
 */
final class MasterCvIndex
{
    /** @var array<string, Role> Employment roles and independent projects, by their own ID. */
    private array $roles = [];

    /** @var array<string, Achievement> */
    private array $achievements = [];

    /** @var array<string, Role> The role an achievement belongs to, by the achievement's ID. */
    private array $owners = [];

    /** @var array<string, Skill> */
    private array $skills = [];

    public function __construct(ParsedMasterCv $cv)
    {
        foreach ($cv->allRoles() as $role) {
            if ($role->id !== null) {
                $this->roles[$role->id] = $role;
            }

            foreach ($role->allAchievements() as $achievement) {
                if ($achievement->id !== null) {
                    $this->achievements[$achievement->id] = $achievement;
                    $this->owners[$achievement->id] = $role;
                }
            }
        }

        foreach ($cv->allSkills() as $skill) {
            if ($skill->id !== '') {
                $this->skills[$skill->id] = $skill;
            }
        }
    }

    /**
     * The master CV holds this ID, whatever state the content is in.
     */
    public function has(string $id): bool
    {
        return isset($this->roles[$id]) || isset($this->achievements[$id]) || isset($this->skills[$id]);
    }

    /**
     * The master CV holds this ID and the content behind it may be used.
     */
    public function resolves(string $id): bool
    {
        if (isset($this->roles[$id])) {
            return true;
        }

        if (isset($this->achievements[$id])) {
            return $this->achievements[$id]->selectable;
        }

        return isset($this->skills[$id]) && $this->skills[$id]->selectable;
    }

    /**
     * The ID is in the CV, and the author has said the fact behind it is
     * unknown.
     */
    public function isUnknown(string $id): bool
    {
        return $this->has($id) && ! $this->resolves($id);
    }

    public function role(string $id): ?Role
    {
        return $this->roles[$id] ?? null;
    }

    public function skill(string $id): ?Skill
    {
        return $this->skills[$id] ?? null;
    }

    /**
     * The role an ID belongs to: the role itself for a role's own ID, the
     * owning role for an item's. A skill belongs to no role — the skills index
     * is a property of the document.
     */
    public function ownerOf(string $id): ?Role
    {
        return $this->roles[$id] ?? $this->owners[$id] ?? null;
    }

    /**
     * The master CV's own words for an item, which is what a verbatim
     * selection puts on the page.
     */
    public function textOf(string $id): ?string
    {
        return $this->achievements[$id]->text ?? $this->skills[$id]->name ?? null;
    }

    /**
     * The name a person would use for a role in a message about it.
     */
    public function nameOf(string $id): string
    {
        return $this->ownerOf($id)->employer ?? $id;
    }
}
