<?php

namespace App\MasterCv;

/**
 * The technologies a hand-written skills index already knows about.
 *
 * It answers one question: does the index have a row for the technology this
 * tech-stack line names? A yes means nothing is derived for it and the
 * author's own row stands.
 *
 * The index and the stacks are never written in the same words — the index
 * says "AWS SQS (incl. dead-letter replay)" where a stack says "SQS", and
 * "PHP 7.x (7.0, 7.4)" where a stack says "PHP 7.4 (legacy)" — so matching is
 * deliberately generous:
 *
 * - a skill contributes its name without any parenthetical, plus each
 *   parenthetical's contents, as separate aliases — that is where a name's
 *   short forms are written down;
 * - a term matches an alias when the term's words are among the alias's, in
 *   any order, which is what makes "SQS" the same technology as "AWS SQS";
 * - failing that, version numbers are dropped from both sides. The question is
 *   whether the index knows the technology at all. Whether a version is right
 *   for a role is a different question, asked of generated output by a check
 *   of its own.
 *
 * A term in two halves (`MySQL / AWS Aurora`) is known only when both halves
 * are.
 *
 * **The generosity is calibrated for this question and must not be carried
 * into a validator.** Matching is subset-based, so across a few hundred rows
 * the union of words is large enough that a single-word term is found readily,
 * including a word that appears in a row for a negative reason. Erring towards
 * "already known" costs a derived row nobody needed; erring the same way when
 * asking whether a reworded bullet may claim a technology would cost the
 * guarantee.
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
                $tokens = SkillName::tokens($alias);

                if ($tokens === []) {
                    continue;
                }

                $this->aliases[] = $tokens;
                $this->versionless[] = SkillName::withoutVersions($tokens);
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
        $tokens = SkillName::tokens($term);

        if ($tokens === []) {
            return true;
        }

        foreach ($this->aliases as $alias) {
            if (self::within($tokens, $alias)) {
                return true;
            }
        }

        $versionless = SkillName::withoutVersions($tokens);

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
}
