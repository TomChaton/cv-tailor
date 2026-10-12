<?php

namespace App\MasterCv;

use Illuminate\Support\Str;

/**
 * The small amount of Markdown this format actually uses.
 *
 * A full Markdown parser would give an abstract syntax tree of headings,
 * lists and emphasis, and none of the things that matter here: a trailing
 * `{#id}` attribute, a bold label standing in for a block, a table row.
 */
final class Markdown
{
    /**
     * A trailing `{#id}` attribute. Hierarchical, dot-separated.
     */
    public const ID = '/\s*\{#(?<id>[^}\s]+)\}\s*$/u';

    /**
     * `- **Label:** value` — a labelled metadata bullet. The colon sits inside
     * the bold markers, which is what separates it from an achievement that
     * opens with a bold lead-in sentence.
     */
    public const LABELLED_VALUE = '/^\*\*(?<label>[^*]+?):\*\*\s*(?<value>.*)$/u';

    /**
     * A bold label on a line of its own, introducing a block of bullets.
     */
    public const BLOCK_LABEL = '/^\*\*(?<label>[^*]+?)\*\*$/u';

    /**
     * Strip what qualifies a heading or a block label rather than names it, so
     * one spelling covers every way an author decorates it.
     */
    public static function normaliseHeading(string $heading): string
    {
        $heading = Str::before($heading, ' — ');
        $heading = Str::before($heading, ' – ');
        $heading = Str::lower(trim($heading));

        // Repeated: a heading may carry more than one parenthetical.
        while (preg_match('/^(?<kept>.*?)\s*\([^()]*\)\s*$/u', $heading, $matches) === 1) {
            $heading = $matches['kept'];
        }

        $heading = preg_replace('/[\s:;,.]+$/u', '', $heading) ?? $heading;

        return preg_replace('/\s+/u', ' ', $heading) ?? $heading;
    }

    /**
     * @return array{0: string, 1: ?string} The text without its ID attribute,
     *                                      and the ID if it had one.
     */
    public static function splitId(string $text): array
    {
        if (preg_match(self::ID, $text, $matches) !== 1) {
            return [rtrim($text), null];
        }

        return [rtrim((string) preg_replace(self::ID, '', $text)), $matches['id']];
    }

    /**
     * @return ?array{indent: int, text: string}
     */
    public static function bullet(string $line): ?array
    {
        if (preg_match('/^(?<indent>\s*)[-*]\s+(?<text>.*)$/u', $line, $matches) !== 1) {
            return null;
        }

        return [
            'indent' => strlen(str_replace("\t", '    ', $matches['indent'])),
            'text' => trim($matches['text']),
        ];
    }

    public static function heading(string $line, int $level): ?string
    {
        $pattern = '/^'.str_repeat('#', $level).'\s+(?<heading>.*)$/u';

        return preg_match($pattern, $line, $matches) === 1 ? trim($matches['heading']) : null;
    }

    /**
     * The cells of a table row, or null if the line is not one.
     *
     * @return ?list<string>
     */
    public static function tableRow(string $line): ?array
    {
        $line = trim($line);

        if (! str_starts_with($line, '|')) {
            return null;
        }

        $cells = array_map(trim(...), explode('|', trim($line, '|')));

        return array_values($cells);
    }

    /**
     * `|---|---|` — the row that separates a table's header from its body, and
     * so the thing that identifies the row above it as a header.
     */
    public static function isTableSeparator(string $line): bool
    {
        $cells = self::tableRow($line);

        if ($cells === null || $cells === []) {
            return false;
        }

        foreach ($cells as $cell) {
            if (preg_match('/^:?-{1,}:?$/', $cell) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Split a comma- or semicolon-separated list, ignoring separators inside
     * brackets.
     *
     * A tech stack line reads `SQS (incl. DLQ replay), EventBridge`, and
     * splitting on every comma would turn one technology into two.
     *
     * @return list<string>
     */
    public static function splitList(string $value): array
    {
        $parts = [];
        $current = '';
        $depth = 0;

        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if ($character === '(' || $character === '[') {
                $depth++;
            } elseif ($character === ')' || $character === ']') {
                $depth = max(0, $depth - 1);
            }

            if ($depth === 0 && ($character === ',' || $character === ';')) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $parts[] = $current;

        return array_values(array_filter(array_map(trim(...), $parts), static fn (string $part): bool => $part !== ''));
    }

    /**
     * @return list<Line>
     */
    public static function lines(string $content, int $firstNumber = 1): array
    {
        $lines = [];

        foreach (explode("\n", str_replace("\r\n", "\n", $content)) as $offset => $text) {
            $lines[] = new Line($firstNumber + $offset, rtrim($text, "\r"));
        }

        return $lines;
    }
}
