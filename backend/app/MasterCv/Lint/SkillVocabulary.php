<?php

namespace App\MasterCv\Lint;

use App\MasterCv\Skill;

/**
 * The technologies the skills index knows about, and whether it covers a term
 * written in a role's tech stack.
 *
 * The two are written by the same person from opposite directions and never in
 * the same words: the index says "AWS SQS (incl. dead-letter replay)" where a
 * stack says "SQS", and "PHP 7.x (7.0, 7.4)" where a stack says "PHP 7.4
 * (legacy)". So matching is deliberately generous:
 *
 * - a skill contributes its name without any parenthetical, plus each
 *   parenthetical's contents, as separate aliases — that is where a name's
 *   short forms are written down;
 * - a term matches an alias when the term's words are among the alias's, in
 *   any order, which is what makes "SQS" the same technology as "AWS SQS";
 * - failing that, version numbers are dropped from both sides. This check asks
 *   whether the index knows the technology at all. Whether a version is right
 *   for a role is a different question, asked of generated output by a check
 *   of its own.
 *
 * A term in two halves (`MySQL / AWS Aurora`) is covered only when both halves
 * are.
 */
final class SkillVocabulary
{
    /** @var list<list<string>> */
    private array $aliases = [];

    /** @var list<list<string>> */
    private array $versionless = [];

    /**
     * @param  list<Skill>  $skills
     */
    public function __construct(array $skills)
    {
        foreach ($skills as $skill) {
            foreach (self::aliasesFor($skill->name) as $alias) {
                $tokens = self::tokens($alias);

                if ($tokens === []) {
                    continue;
                }

                $this->aliases[] = $tokens;
                $this->versionless[] = self::withoutVersions($tokens);
            }
        }
    }

    public function covers(string $term): bool
    {
        foreach (self::halves($term) as $half) {
            if (! $this->coversOne($half)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function aliasesFor(string $name): array
    {
        preg_match_all('/\(([^()]*)\)/u', $name, $matches);

        $aliases = [
            (string) preg_replace('/\([^()]*\)/u', ' ', $name),
            // The whole row, parentheticals included. One row often lists a
            // family — `Windows (98, 2000, XP, Server 2000/2003)` — and a
            // stack names a member of it, which belongs to no single alias.
            $name,
        ];

        foreach ($matches[1] as $parenthetical) {
            foreach (preg_split('/[,;]/u', $parenthetical) ?: [] as $part) {
                $aliases[] = $part;
            }
        }

        return array_values(array_filter(array_map(trim(...), $aliases), static fn (string $a): bool => $a !== ''));
    }

    /**
     * @return list<string>
     */
    private static function halves(string $term): array
    {
        $term = (string) preg_replace('/\([^()]*\)/u', ' ', $term);
        $halves = preg_split('#\s+/\s+#u', $term) ?: [$term];

        return array_values(array_filter(array_map(trim(...), $halves), static fn (string $h): bool => $h !== ''));
    }

    private function coversOne(string $term): bool
    {
        $tokens = self::tokens($term);

        if ($tokens === []) {
            return true;
        }

        foreach ($this->aliases as $alias) {
            if (self::within($tokens, $alias)) {
                return true;
            }
        }

        $versionless = self::withoutVersions($tokens);

        // A bare version number is not a technology, so there is nothing for
        // the index to be missing.
        if ($versionless === []) {
            return true;
        }

        foreach ($this->versionless as $alias) {
            if (self::within($versionless, $alias)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $alias
     */
    private static function within(array $tokens, array $alias): bool
    {
        foreach ($tokens as $token) {
            if (! in_array($token, $alias, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function tokens(string $text): array
    {
        $text = mb_strtolower(trim($text));

        // Dots, pluses and hashes stay inside a token: `node.js`, `c++` and
        // `c#` are names, not punctuation.
        $text = (string) preg_replace('/[^\p{L}\p{N}.+#]+/u', ' ', $text);

        $tokens = array_filter(
            preg_split('/\s+/u', $text) ?: [],
            static fn (string $token): bool => trim($token, '.') !== '',
        );

        return array_values(array_map(self::singular(...), $tokens));
    }

    /**
     * Both sides are folded the same way, so this only ever makes a plural and
     * its singular the same word: "affiliate network feeds" and "affiliate
     * network feed integration" are the same technology written twice.
     */
    private static function singular(string $token): string
    {
        if (mb_strlen($token) > 3 && str_ends_with($token, 's') && ! str_ends_with($token, 'ss')) {
            return mb_substr($token, 0, -1);
        }

        return $token;
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private static function withoutVersions(array $tokens): array
    {
        return array_values(array_filter(
            $tokens,
            static fn (string $token): bool => preg_match('/^v?\d+(\.(\d+|x))*$/', $token) !== 1,
        ));
    }
}
