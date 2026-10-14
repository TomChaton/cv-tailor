<?php

namespace App\MasterCv\Fixes;

use App\MasterCv\Lint\RoleNames;
use App\MasterCv\Markdown;
use App\MasterCv\ParsedMasterCv;
use App\MasterCv\Role;

/**
 * What a skills-index row's "Where used" cell is allowed to say.
 *
 * The set is the roles and independent projects the document already holds, so
 * a person choosing from it cannot write a name that is not there. That is
 * what makes this safe to write into the master CV with no model and no
 * review: every answer on offer is a fact the file already states.
 *
 * A choice's text is the text the cell will carry, so what was picked and what
 * was written are one thing rather than two that can drift.
 */
final readonly class WhereUsedChoices
{
    /**
     * @param  array<string, string>  $choices  Key to the text that names it.
     */
    private function __construct(
        private array $choices,
        private RoleNames $names,
    ) {}

    public static function of(ParsedMasterCv $cv): self
    {
        $choices = [];

        foreach ($cv->allRoles() as $role) {
            // Two entries that key the same are one choice: the second offers
            // nothing the first has not already offered.
            $choices[self::keyFor($role)] ??= $role->whereUsed();
        }

        return new self($choices, new RoleNames($cv->allRoles()));
    }

    /**
     * Every role and independent project, in document order.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->choices;
    }

    /**
     * The choices a cell already names.
     *
     * Resolution is lint's own, so what is pre-selected is exactly what lint
     * counts as resolved. The parts that resolve to nothing are what the
     * problem is about, and they are simply absent.
     *
     * @return list<string>
     */
    public function selected(?string $whereUsed): array
    {
        if ($whereUsed === null) {
            return [];
        }

        $selected = [];

        foreach (Markdown::splitList($whereUsed) as $part) {
            $role = $this->names->role($part);

            if ($role !== null) {
                $selected[] = self::keyFor($role);
            }
        }

        return $this->inOrder($selected);
    }

    /**
     * The cell these choices write.
     *
     * A key that names no choice is dropped rather than written: the set on
     * offer is the whole of what may be said, and nothing else reaches the
     * document.
     *
     * @param  list<string>  $keys
     */
    public function cell(array $keys): string
    {
        $texts = [];

        foreach ($this->inOrder($keys) as $key) {
            $text = $this->choices[$key];

            if (! in_array($text, $texts, true)) {
                $texts[] = $text;
            }
        }

        return implode(', ', $texts);
    }

    /**
     * The given keys, deduplicated and in the order the choices are offered
     * in, which is the order the document itself is in.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function inOrder(array $keys): array
    {
        return array_values(array_filter(
            array_keys($this->choices),
            static fn (string $key): bool => in_array($key, $keys, true),
        ));
    }

    /**
     * A role's ID where it has one, and its heading where it does not.
     *
     * A CV missing an ID has a problem of its own, and a fix for one row has
     * no business refusing to work until that one is dealt with.
     */
    private static function keyFor(Role $role): string
    {
        return $role->id ?? $role->heading;
    }
}
