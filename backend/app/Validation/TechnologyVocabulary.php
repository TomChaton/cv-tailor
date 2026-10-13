<?php

namespace App\Validation;

use App\MasterCv\Markdown;
use App\MasterCv\Skill;

/**
 * The technologies a tailored CV is allowed to name, taken from the skills
 * index.
 *
 * The index is the one place in the master CV that lists technologies as
 * technologies, so it is what decides whether a word in a reworded sentence is
 * a technology claim at all. Whether *this role* may claim it is a separate
 * question, answered by that role's own tech stack.
 *
 * The index decides, and it decides for everything in it: a practice the
 * author filed among their skills is held to the same rule as a database. The
 * index is the author's own statement of what they have worked with, and a
 * validator that second-guessed which rows count would be inventing a
 * taxonomy the author never wrote.
 *
 * One row can name several technologies — `Linux: Debian 12, Ubuntu 22.04,
 * CentOS 7` is four — so a row is split before it is read.
 */
final class TechnologyVocabulary
{
    /** @var list<Technology> */
    private array $technologies = [];

    /**
     * @param  list<Skill>  $skills
     */
    public function __construct(array $skills)
    {
        $byName = [];

        foreach ($skills as $skill) {
            foreach (self::namesIn($skill->name) as $name) {
                $aliases = self::aliasesFor($name);
                $canonical = Technology::withoutVersion(self::withoutParentheticals($name));

                if ($canonical === '' || $aliases === []) {
                    continue;
                }

                $byName[$canonical] = array_values(array_unique([
                    ...($byName[$canonical] ?? []),
                    ...$aliases,
                ]));
            }
        }

        foreach ($byName as $name => $aliases) {
            $this->technologies[] = new Technology((string) $name, $aliases);
        }
    }

    /**
     * @return list<Technology>
     */
    public function all(): array
    {
        return $this->technologies;
    }

    /**
     * The technologies this text names.
     *
     * @return list<Technology>
     */
    public function detect(string $text): array
    {
        return array_values(array_filter(
            $this->technologies,
            static fn (Technology $technology): bool => $technology->occursIn($text),
        ));
    }

    /**
     * The separate technologies one index row names.
     *
     * @return list<string>
     */
    private static function namesIn(string $row): array
    {
        $names = [];

        foreach (Markdown::splitList($row) as $part) {
            // `Linux: Debian 12` names the family and the member. A space
            // after the colon is required, so a name that contains one
            // (`JSON:API`) stays whole.
            if (preg_match('/^(?<family>[^:]{1,40}):\s+(?<member>.+)$/u', $part, $matches) === 1) {
                $names[] = trim($matches['family']);
                $names[] = trim($matches['member']);

                continue;
            }

            $names[] = $part;
        }

        return array_values(array_filter($names, static fn (string $name): bool => $name !== ''));
    }

    /**
     * @return list<string>
     */
    private static function aliasesFor(string $name): array
    {
        $aliases = [Technology::withoutVersion(self::withoutParentheticals($name))];

        preg_match_all('/\(([^()]*)\)/u', $name, $matches);

        /** @var list<string> $parentheticals */
        $parentheticals = $matches[1];

        foreach ($parentheticals as $parenthetical) {
            foreach (Markdown::splitList($parenthetical) as $part) {
                // Only a parenthetical that pins a version is another name for
                // the same technology. One that lists aspects names its parts.
                if (! self::pinsAVersion($part)) {
                    continue;
                }

                $aliases[] = Technology::withoutVersion($part);
            }
        }

        return array_values(array_filter(
            array_unique(array_map(trim(...), $aliases)),
            // A single character names nothing, and a bare version is not a
            // technology.
            static fn (string $alias): bool => mb_strlen($alias) > 1,
        ));
    }

    private static function pinsAVersion(string $part): bool
    {
        foreach (preg_split('/\s+/u', trim($part)) ?: [] as $word) {
            if (Technology::isVersion($word)) {
                return true;
            }
        }

        return false;
    }

    private static function withoutParentheticals(string $name): string
    {
        return trim((string) preg_replace('/\([^()]*\)/u', ' ', $name));
    }
}
