<?php

namespace App\Validation;

/**
 * One technology the skills index names, with the forms it is written in.
 *
 * The index and a role's tech stack are written by the same person from
 * opposite directions and never in the same words: the index says
 * "Slim Framework (Slim 4)" where a stack says "Slim 4". So a technology
 * carries aliases, and any of them stands for it.
 *
 * **A parenthetical becomes an alias only when it pins a version.** That is
 * what a second name for the same thing looks like — "Slim Framework (Slim
 * 4)". A parenthetical listing aspects — "AWS CloudWatch (logs, metrics,
 * alarms)" — names parts of one technology, and taking "logs" as an alias
 * would make the word "logs" in a reworded sentence a technology claim. One
 * reads as a name and the other does not, and the version is what tells them
 * apart.
 */
final readonly class Technology
{
    /**
     * A version as these documents write one: `8`, `8.3`, `7.x`, `22.04`,
     * `2000/2003`, `v2`.
     */
    public const VERSION = '\d+(?:[.\/](?:\d+|x))*';

    /**
     * @param  list<string>  $aliases  Every spelling that stands for this
     *                                 technology, including its name.
     */
    public function __construct(
        public string $name,
        public array $aliases,
    ) {}

    /**
     * The technology is named somewhere in this text.
     */
    public function occursIn(string $text): bool
    {
        foreach ($this->aliases as $alias) {
            if (preg_match(self::pattern($alias), $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The versions this text pins to this technology.
     *
     * Only versions the text puts immediately after the technology's name
     * count, through the separators these documents use: `PHP 8.x (8.3)`,
     * `Elasticsearch 7 to 8`, `MySQL (5.0, 5.6, 5.7)`. A version further away
     * than that belongs to something else on the line, and treating it as this
     * technology's would let a validator pass a version the CV records for a
     * different tool.
     *
     * @return list<string>
     */
    public function versionsIn(string $text): array
    {
        $versions = [];

        foreach ($this->aliases as $alias) {
            // Byte offsets, and `substr` counts bytes, so the two agree.
            if (((int) preg_match_all(self::pattern($alias), $text, $matches, PREG_OFFSET_CAPTURE)) < 1) {
                continue;
            }

            /** @var list<array{0: string, 1: int}> $occurrences */
            $occurrences = $matches[0];

            foreach ($occurrences as [$match, $offset]) {
                $versions = [
                    ...$versions,
                    ...self::versionRun(substr($text, $offset + strlen($match))),
                ];
            }
        }

        return array_values(array_unique($versions));
    }

    /**
     * One of these spellings appears inside one of the given entries.
     *
     * An entry is matched as a set of words rather than as a string, because a
     * stack writes "Slim 4 (dispatch API)" where the index writes "Slim". The
     * entries are one role's tech stack — a handful of lines the author wrote
     * about that role — so a generous reading of a single entry stays narrow.
     * The same generosity over the whole skills index would not: a few hundred
     * rows share enough words between them that almost anything is found
     * somewhere, which is why the linter's vocabulary is not reused here.
     *
     * @param  list<string>  $entries
     */
    public function isCoveredBy(array $entries): bool
    {
        $sets = array_map(self::words(...), $entries);

        foreach ($this->aliases as $alias) {
            $alias = self::words($alias);

            if ($alias === []) {
                continue;
            }

            foreach ($sets as $entry) {
                if (array_diff($alias, $entry) === []) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A name with no version on the end, which is what the index and a stack
     * agree on. The versions themselves are a separate question, asked by a
     * check of its own.
     */
    public static function withoutVersion(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name)) ?: [];

        while ($words !== [] && self::isVersion((string) end($words))) {
            array_pop($words);
        }

        return implode(' ', $words);
    }

    public static function isVersion(string $word): bool
    {
        return preg_match('/^v?'.self::VERSION.'$/u', $word) === 1;
    }

    /**
     * @return non-empty-string
     */
    private static function pattern(string $alias): string
    {
        // Not `\b`: a name may end in punctuation that a word boundary reads
        // as the boundary itself, so `C++` would match inside `C++11`.
        return '/(?<![\p{L}\p{N}_])'.preg_quote($alias, '/').'(?![\p{L}\p{N}_])/iu';
    }

    /**
     * The run of versions a text opens with, through the separators a list of
     * versions is written with.
     *
     * @return list<string>
     */
    private static function versionRun(string $text): array
    {
        $versions = [];
        $separator = '(?:\s*[(),\/–—-]\s*|\s+)(?:(?:and|to|or|v)\s+)?';

        while (preg_match('/^'.$separator.'(?<version>'.self::VERSION.')/iu', $text, $matches) === 1) {
            $versions[] = $matches['version'];
            $text = substr($text, strlen($matches[0]));
        }

        return $versions;
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $text = mb_strtolower($text);

        // Dots, pluses and hashes stay inside a word: `node.js`, `c++` and
        // `c#` are names, not punctuation.
        $text = (string) preg_replace('/[^\p{L}\p{N}.+#]+/u', ' ', $text);

        return array_values(array_filter(
            preg_split('/\s+/u', trim($text)) ?: [],
            static fn (string $word): bool => trim($word, '.') !== '',
        ));
    }
}
