<?php

namespace App\MasterCv;

/**
 * A technology's name read as a set of words.
 *
 * The skills index and the tech stacks are written by the same person from
 * opposite directions and never in the same words, so recognising that two
 * spellings name one technology is done on words rather than on strings.
 *
 * The folding is deliberately crude — lower case, punctuation to spaces, a
 * trailing "s" dropped — and both sides of every comparison are folded the
 * same way, so the only thing it can do is make two spellings of one word
 * agree.
 */
final class SkillName
{
    /**
     * @return list<string>
     */
    public static function tokens(string $text): array
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
     * @param  list<string>  $tokens
     * @return list<string>
     */
    public static function withoutVersions(array $tokens): array
    {
        return array_values(array_filter(
            $tokens,
            static fn (string $token): bool => ! self::isVersion($token),
        ));
    }

    public static function isVersion(string $token): bool
    {
        return preg_match('/^v?\d+(\.(\d+|x))*$/', $token) === 1;
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
}
