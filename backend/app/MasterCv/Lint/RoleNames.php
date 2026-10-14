<?php

namespace App\MasterCv\Lint;

use App\MasterCv\Markdown;
use App\MasterCv\Role;

/**
 * The names by which a skills-index row may refer to a role or a project.
 *
 * A "where used" cell is prose, written years apart from the role it names:
 * `Harlequin Transit (2021–present)`, `Independent: Fathom Index (indexer)`,
 * `Presented at Harlequin Transit`. So a cell is split on its commas and
 * semicolons, each part is stripped of what qualifies it rather than names it,
 * and a part matches when it and a role's name contain one another.
 *
 * It answers which role a part names, not merely whether one does, because a
 * surface that offers to rewrite the cell has to pre-select exactly what lint
 * already counts as resolved. Two answers to one question would drift.
 */
final class RoleNames
{
    /** @var list<array{role: Role, names: list<string>}> */
    private array $roles = [];

    /**
     * @param  list<Role>  $roles
     */
    public function __construct(array $roles)
    {
        foreach ($roles as $role) {
            $names = [];

            foreach ([$role->employer, $role->heading, $role->id] as $name) {
                $normalised = $name === null ? '' : self::normalise($name);

                if (strlen($normalised) >= 3) {
                    $names[] = $normalised;
                }
            }

            $this->roles[] = ['role' => $role, 'names' => $names];
        }
    }

    /**
     * @return list<string> The parts of the cell that name nothing in the CV.
     */
    public function unmatched(string $whereUsed): array
    {
        $unmatched = [];

        foreach (Markdown::splitList($whereUsed) as $part) {
            if (! $this->matches($part)) {
                $unmatched[] = $part;
            }
        }

        return $unmatched;
    }

    public function namesAny(string $whereUsed): bool
    {
        foreach (Markdown::splitList($whereUsed) as $part) {
            if ($this->matches($part)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The role a part of a cell names, in document order where it could be
     * more than one.
     */
    public function role(string $part): ?Role
    {
        $part = self::normalise($part);

        if (strlen($part) < 3) {
            return null;
        }

        foreach ($this->roles as $candidate) {
            foreach ($candidate['names'] as $name) {
                if (str_contains($part, $name) || str_contains($name, $part)) {
                    return $candidate['role'];
                }
            }
        }

        return null;
    }

    private function matches(string $part): bool
    {
        return $this->role($part) !== null;
    }

    private static function normalise(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = (string) preg_replace('/^independent\s*[:\-–—]\s*/u', '', $name);
        $name = (string) preg_replace('/\([^()]*\)/u', ' ', $name);
        $name = Markdown::normaliseHeading($name);

        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }
}
