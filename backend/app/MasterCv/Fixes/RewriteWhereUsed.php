<?php

namespace App\MasterCv\Fixes;

use App\MasterCv\Markdown;
use App\MasterCv\Skill;

/**
 * Writes one skills-index row's "Where used" cell and nothing else.
 *
 * This is the only thing in the system that edits the master CV on its
 * author's behalf, so it is held to the narrowest promise it can be held to:
 * the row it was offered for, its second column, and no other character in the
 * document. The promise is **proved before the result is used** — the rewrite
 * is compared to the source line by line — rather than argued from the
 * construction, because the construction is what a later change alters.
 *
 * The row is found by line and confirmed by name. A line that no longer
 * carries the row it carried when the fix was offered is a refusal, not a
 * guess: the alternative is writing a skill's evidence onto a different skill.
 */
class RewriteWhereUsed
{
    /**
     * @throws WhereUsedNotRewritten
     */
    public function handle(string $markdown, Skill $skill, string $cell): string
    {
        // Split on newlines alone, so a document written with CRLF keeps its
        // endings and the only line that changes is still the only line that
        // changes.
        $lines = explode("\n", $markdown);
        $index = $skill->line - 1;

        if (! array_key_exists($index, $lines)) {
            throw WhereUsedNotRewritten::noSuchRow($skill->line);
        }

        $raw = $lines[$index];
        $ending = str_ends_with($raw, "\r") ? "\r" : '';
        $text = $ending === '' ? $raw : substr($raw, 0, -1);

        $rewritten = $this->tableRow($text, $skill, $cell)
            ?? $this->listRow($text, $skill, $cell)
            ?? throw WhereUsedNotRewritten::noSuchRow($skill->line);

        $lines[$index] = $rewritten.$ending;

        $result = implode("\n", $lines);

        $this->proveOneRowChanged($markdown, $result, $index);

        return $result;
    }

    /**
     * `| Skill | Where used |` — the second cell replaced, everything that
     * shapes the row left as the author typed it.
     */
    private function tableRow(string $text, Skill $skill, string $cell): ?string
    {
        $cells = Markdown::tableRow($text);

        if ($cells === null || count($cells) !== 2 || trim($cells[0]) !== $skill->name) {
            return null;
        }

        if (preg_match('/^(?<before>\s*\|[^|]*\|)(?<used>[^|]*)(?<after>\|?\s*)$/u', $text, $matches) !== 1) {
            return null;
        }

        $rewritten = $matches['before'].($cell === '' ? ' ' : ' '.$cell.' ').$matches['after'];

        // A row the author left unclosed stays unclosed, and an empty cell at
        // the end of one is a space nobody typed.
        return trim($matches['after']) === '' ? rtrim($rewritten) : $rewritten;
    }

    /**
     * `- Skill — where used` — the list form, whose second column is whatever
     * follows the dash. Choosing nothing takes the dash with it, because a row
     * that trails off into nothing is not what the author would have written.
     */
    private function listRow(string $text, Skill $skill, string $cell): ?string
    {
        if (preg_match('/^(?<marker>\s*[-*]\s+)(?<body>.*)$/u', $text, $matches) !== 1) {
            return null;
        }

        $separator = ' — ';
        $name = $matches['body'];

        foreach ([' — ', ' – '] as $candidate) {
            if (str_contains($name, $candidate)) {
                [$name, $separator] = [explode($candidate, $name, 2)[0], $candidate];

                break;
            }
        }

        if (trim($name) !== $skill->name) {
            return null;
        }

        $name = rtrim($name);

        return $cell === '' ? $matches['marker'].$name : $matches['marker'].$name.$separator.$cell;
    }

    /**
     * @throws WhereUsedNotRewritten
     */
    private function proveOneRowChanged(string $source, string $result, int $index): void
    {
        $before = explode("\n", $source);
        $after = explode("\n", $result);

        if (count($before) !== count($after)) {
            throw WhereUsedNotRewritten::moreThanOneRow();
        }

        foreach ($before as $position => $line) {
            if ($position !== $index && $line !== $after[$position]) {
                throw WhereUsedNotRewritten::moreThanOneRow();
            }
        }
    }
}
