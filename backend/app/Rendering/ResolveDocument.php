<?php

namespace App\Rendering;

use App\Generation\SelectableIds;
use App\MasterCv\Achievement;
use App\MasterCv\ParsedMasterCv;
use App\MasterCv\Role;
use App\MasterCv\RoleMode;
use App\Validation\Output\SelectedItem;
use App\Validation\Output\SelectedRole;
use App\Validation\Output\Selection;

/**
 * Resolves one selection against the master CV version it was made from.
 *
 * It takes a parse and an output and returns a document: no database, no log,
 * no status. That is what lets the same resolution be re-run over a revision
 * that already exists.
 *
 * **What the model decides and what the code decides are separated here.** The
 * model chooses which roles appear, which of their bullets appear and in what
 * order, which skills to lead with, and the words of a rewording. Everything
 * else is read from the parse: the header, the employer, the title, the dates,
 * the order the roles go in, the nesting of a sub-bullet under its parent, and
 * the wording of anything the author marked "do not elaborate". A detail the
 * model never saw is a detail it cannot get wrong.
 *
 * It reuses the validators' reading of the output (`Output\Selection`) rather
 * than reading `output_json` a second way. A manual edit has met no schema, so
 * every field is read for the shape it should have and ignored when it does
 * not have it — and an item the master CV version does not hold, or holds and
 * marks unknown, is dropped rather than printed.
 */
final class ResolveDocument
{
    /**
     * @param  array<string, mixed>  $output
     */
    public function resolve(ParsedMasterCv $cv, array $output): ResolvedDocument
    {
        $selection = Selection::from($output);

        $roles = $this->roles($cv->roles, $selection->roles);
        $projects = $this->roles($cv->independentProjects, $selection->independentProjects);

        return new ResolvedDocument(
            header: $this->header($cv, $selection),
            summary: $selection->summary?->text,
            skills: $this->skills($cv, $selection),
            roles: $roles,
            independentProjects: $projects,
            awards: $cv->awards,
            interests: $cv->interests,
            rolesOmitted: max(0, count($cv->allRoles()) - count($roles) - count($projects)),
        );
    }

    private function header(ParsedMasterCv $cv, Selection $selection): ResolvedHeader
    {
        $contact = [];

        foreach ($cv->contact->details() as $label => $value) {
            $contact[] = ['label' => $label, 'value' => $value];
        }

        return new ResolvedHeader(
            name: $cv->contact->name() ?? $this->subject($cv),
            headline: $this->headline($cv, $selection),
            contact: $contact,
        );
    }

    /**
     * The headline the output chose, if the CV offers it.
     *
     * The schema offers the model the CV's own titles as an enum, so a title
     * from nowhere cannot come back. A manual edit has met no schema, and a
     * headline the CV does not offer is a claim with nothing behind it.
     */
    private function headline(ParsedMasterCv $cv, Selection $selection): ?string
    {
        $headline = $selection->headline;

        if ($headline === null) {
            return null;
        }

        return in_array($headline, SelectableIds::from($cv)->headlineTitles, true) ? $headline : null;
    }

    private function subject(ParsedMasterCv $cv): ?string
    {
        $subject = $cv->frontMatter['subject'] ?? null;

        return is_string($subject) && trim($subject) !== '' ? trim($subject) : null;
    }

    /**
     * @return list<ResolvedSkill>
     */
    private function skills(ParsedMasterCv $cv, Selection $selection): array
    {
        $available = [];

        foreach ($cv->allSkills() as $skill) {
            if ($skill->selectable && $skill->id !== '') {
                $available[$skill->id] = $skill;
            }
        }

        $skills = [];
        $seen = [];

        foreach ($selection->keySkills as $reference) {
            $skill = $available[$reference->id] ?? null;

            if ($skill === null || isset($seen[$reference->id])) {
                continue;
            }

            $seen[$reference->id] = true;
            $skills[] = new ResolvedSkill($skill->id, $skill->name);
        }

        return $skills;
    }

    /**
     * The roles to show, in the order to show them.
     *
     * @param  list<Role>  $master
     * @param  list<SelectedRole>  $selected
     * @return list<ResolvedRole>
     */
    private function roles(array $master, array $selected): array
    {
        $byId = [];

        foreach ($selected as $role) {
            if ($role->id !== null && ! isset($byId[$role->id])) {
                $byId[$role->id] = $role;
            }
        }

        $resolved = [];

        foreach ($this->reverseChronological($master) as $role) {
            $selection = $role->id === null ? null : ($byId[$role->id] ?? null);

            // A role the output left out and one it marked omitted are the
            // same decision, so they leave the page the same way.
            if ($role->id === null || $selection === null) {
                continue;
            }

            $mode = $selection->mode ?? $role->defaultMode;

            if ($mode === RoleMode::Omitted) {
                continue;
            }

            $resolved[] = new ResolvedRole(
                id: $role->id,
                kind: $role->kind,
                mode: $mode,
                employer: $role->employer,
                title: $role->title,
                dates: $role->dates?->raw,
                context: $mode === RoleMode::Full ? $role->context : null,
                items: $this->items($role, $selection),
            );
        }

        return $resolved;
    }

    /**
     * Most recent first, from the parsed dates, whatever order the output gave.
     *
     * @param  list<Role>  $roles
     * @return list<Role>
     */
    private function reverseChronological(array $roles): array
    {
        // PHP's sort is stable, so roles the CV dates identically — or does not
        // date at all — keep the order their author wrote them in.
        usort($roles, fn (Role $a, Role $b): int => $this->chronology($b) <=> $this->chronology($a));

        return $roles;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function chronology(Role $role): array
    {
        $dates = $role->dates;

        if ($dates === null) {
            return [PHP_INT_MIN, PHP_INT_MIN];
        }

        return [
            // A role still running is the most recent there is.
            $dates->current ? PHP_INT_MAX : ($dates->endYear ?? $dates->startYear ?? PHP_INT_MIN),
            $dates->startYear ?? PHP_INT_MIN,
        ];
    }

    /**
     * @return list<ResolvedItem>
     */
    private function items(Role $role, SelectedRole $selection): array
    {
        [$own, $parents] = $this->catalogue($role->achievements);

        /** @var list<array{id: string, text: string, reworded: bool}> $chosen */
        $chosen = [];
        $seen = [];

        foreach ($selection->items as $item) {
            $id = $item->id;
            $achievement = $id === null ? null : ($own[$id] ?? null);

            // An item this role does not own is dropped rather than shown
            // under it, and so is one the author marked unknown. Validation
            // blocks a revision that asks for either; a renderer that printed
            // it anyway would be a second route onto the page.
            if ($id === null || $achievement === null || ! $achievement->selectable || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            $chosen[] = [
                'id' => $id,
                'text' => $this->text($achievement, $item),
                'reworded' => $item->text !== null && ! $achievement->verbatimOnly,
            ];
        }

        $attachment = [];

        foreach ($chosen as $node) {
            $attachment[$node['id']] = $this->nearestChosen($node['id'], $parents, $seen);
        }

        return $this->tree($chosen, $attachment, null);
    }

    /**
     * The words that go on the page.
     *
     * "Do not elaborate" is the author saying the detail is theirs to give or
     * withhold, so a rewording of it is discarded in favour of their own
     * sentence. The parser sets the flag; this is where it is honoured, which
     * is the last point before the words reach a page.
     */
    private function text(Achievement $achievement, SelectedItem $item): string
    {
        if ($item->text === null || $achievement->verbatimOnly) {
            return $achievement->text;
        }

        return $item->text;
    }

    /**
     * The nearest ancestor of an item that was also chosen, so a sub-bullet
     * selected without its parent becomes a bullet of its own.
     *
     * @param  array<string, ?string>  $parents
     * @param  array<string, true>  $chosen
     */
    private function nearestChosen(string $id, array $parents, array $chosen): ?string
    {
        $parent = $parents[$id] ?? null;

        while ($parent !== null && ! isset($chosen[$parent])) {
            $parent = $parents[$parent] ?? null;
        }

        return $parent;
    }

    /**
     * @param  list<array{id: string, text: string, reworded: bool}>  $chosen
     * @param  array<string, ?string>  $attachment
     * @return list<ResolvedItem>
     */
    private function tree(array $chosen, array $attachment, ?string $under): array
    {
        $items = [];

        foreach ($chosen as $node) {
            if (($attachment[$node['id']] ?? null) !== $under) {
                continue;
            }

            $items[] = new ResolvedItem(
                id: $node['id'],
                text: $node['text'],
                reworded: $node['reworded'],
                children: $this->tree($chosen, $attachment, $node['id']),
            );
        }

        return $items;
    }

    /**
     * A role's own bullets by ID, and which bullet each one sits under.
     *
     * @param  list<Achievement>  $achievements
     * @return array{0: array<string, Achievement>, 1: array<string, ?string>}
     */
    private function catalogue(array $achievements, ?string $parent = null): array
    {
        $own = [];
        $parents = [];

        foreach ($achievements as $achievement) {
            $id = $achievement->id;

            if ($id !== null) {
                $own[$id] = $achievement;
                $parents[$id] = $parent;
            }

            // A bullet with no ID cannot be selected, but a child of it can, so
            // the chain skips past it rather than stopping there.
            [$childOwn, $childParents] = $this->catalogue($achievement->children, $id ?? $parent);

            $own = [...$own, ...$childOwn];
            $parents = [...$parents, ...$childParents];
        }

        return [$own, $parents];
    }
}
