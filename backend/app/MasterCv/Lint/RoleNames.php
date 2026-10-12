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
 */
final class RoleNames
{
    /** @var list<string> */
    private array $names = [];

    /**
     * @param  list<Role>  $roles
     */
    public function __construct(array $roles)
    {
        foreach ($roles as $role) {
            foreach ([$role->employer, $role->heading, $role->id] as $name) {
                $normalised = $name === null ? '' : self::normalise($name);

                if (strlen($normalised) >= 3) {
                    $this->names[] = $normalised;
                }
            }
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

    private function matches(string $part): bool
    {
        $part = self::normalise($part);

        if (strlen($part) < 3) {
            return false;
        }

        foreach ($this->names as $name) {
            if (str_contains($part, $name) || str_contains($name, $part)) {
                return true;
            }
        }

        return false;
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
