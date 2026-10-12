<?php

namespace App\MasterCv;

/**
 * The CV's YAML front matter, read far enough to find the presentation policy.
 *
 * This reads the subset the format uses — nested mappings, lists, and scalars
 * — rather than taking a YAML dependency. The front matter is a handful of
 * keys written by one person, and the keys this application cares about are
 * named in `PresentationPolicy`; anything else is carried through untouched
 * rather than rejected, because an author's own bookkeeping keys are none of
 * the parser's business.
 */
final class FrontMatter
{
    /**
     * @return array{0: array<array-key, mixed>, 1: int} The front matter, and
     *                                                   the file line the body
     *                                                   starts on.
     */
    public static function split(string $content): array
    {
        $lines = Markdown::lines($content);

        if (($lines[0] ?? null) === null || trim($lines[0]->text) !== '---') {
            return [[], 1];
        }

        foreach ($lines as $index => $line) {
            if ($index > 0 && trim($line->text) === '---') {
                $yaml = array_slice($lines, 1, $index - 1);

                return [
                    self::parse(implode("\n", array_map(static fn (Line $l): string => $l->text, $yaml))),
                    $line->number + 1,
                ];
            }
        }

        return [[], 1];
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function parse(string $yaml): array
    {
        $lines = [];

        foreach (explode("\n", $yaml) as $text) {
            $trimmed = trim($text);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $lines[] = ['indent' => strlen($text) - strlen(ltrim($text)), 'text' => $trimmed];
        }

        $index = 0;

        return self::block($lines, $index, 0);
    }

    /**
     * @param  list<array{indent: int, text: string}>  $lines
     * @return array<array-key, mixed>
     */
    private static function block(array $lines, int &$index, int $indent): array
    {
        $map = [];
        $list = [];

        while ($index < count($lines)) {
            $line = $lines[$index];

            if ($line['indent'] < $indent) {
                break;
            }

            if ($line['indent'] > $indent) {
                // A deeper line with no key above it: skip rather than guess.
                $index++;

                continue;
            }

            if (str_starts_with($line['text'], '- ')) {
                $list[] = self::scalar(substr($line['text'], 2));
                $index++;

                continue;
            }

            if (preg_match('/^(?<key>[^:]+):\s*(?<value>.*)$/u', $line['text'], $matches) !== 1) {
                $index++;

                continue;
            }

            $key = trim($matches['key']);
            $value = trim($matches['value']);
            $index++;

            if ($value !== '') {
                $map[$key] = self::scalar($value);

                continue;
            }

            $childIndent = $lines[$index]['indent'] ?? $indent;
            $map[$key] = $childIndent > $indent ? self::block($lines, $index, $childIndent) : null;
        }

        return $list !== [] ? $list : $map;
    }

    private static function scalar(string $value): mixed
    {
        $value = trim($value);

        if (preg_match('/^\[(?<items>.*)\]$/u', $value, $matches) === 1) {
            return array_map(self::scalar(...), Markdown::splitList($matches['items']));
        }

        if (preg_match('/^(["\'])(?<quoted>.*)\1$/u', $value, $matches) === 1) {
            return $matches['quoted'];
        }

        return match (true) {
            $value === '' || $value === 'null' || $value === '~' => null,
            $value === 'true' => true,
            $value === 'false' => false,
            preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
            default => $value,
        };
    }
}
