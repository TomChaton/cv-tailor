<?php

namespace App\Rendering\Word;

use App\Rendering\Html\InlineMarkdown;
use DOMDocument;
use DOMNode;
use DOMText;

/**
 * The inline Markdown a master CV uses, as Word runs.
 *
 * **The Markdown is read once, by the converter the PDF already uses.** Its
 * output is a fragment carrying only `strong`, `em` and `code`, which this
 * walks into runs. A second reading of the format here would be a second thing
 * to keep in step with the convention, and the two would eventually disagree
 * about what a bold lead-in is.
 */
final readonly class InlineRuns
{
    public function __construct(private InlineMarkdown $markdown) {}

    /**
     * Text with no markup in it at all: an employer, a date range, a label.
     *
     * @return list<WordRun>
     */
    public function plain(?string $text, bool $bold = false, bool $italic = false): array
    {
        $text = $this->printable((string) $text);

        return $text === '' ? [] : [new WordRun($text, bold: $bold, italic: $italic)];
    }

    /**
     * One line of a bullet, a summary or a skill.
     *
     * @return list<WordRun>
     */
    public function line(?string $text): array
    {
        return $this->spans($this->markdown->line($text)->toHtml());
    }

    /**
     * A block whose line breaks are meaningful, such as a role's context
     * paragraph: one list of runs per line, to be separated by a break inside
     * one paragraph.
     *
     * @return list<list<WordRun>>
     */
    public function block(?string $text): array
    {
        $html = $this->markdown->block($text)->toHtml();

        if (trim($html) === '') {
            return [];
        }

        $lines = [];

        foreach (explode('<br>', $html) as $line) {
            $spans = $this->spans($line);

            if ($spans !== []) {
                $lines[] = $spans;
            }
        }

        return $lines;
    }

    /**
     * @return list<WordRun>
     */
    private function spans(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $document = new DOMDocument;

        // The fragment is this application's own output and well-formed by
        // construction, so a parse that fails is a bug rather than bad input.
        // It is read as XML all the same: an HTML parse would quietly repair
        // whatever it did not understand, and the Word file would carry the
        // repair.
        $parsed = $document->loadXML('<line>'.$html.'</line>', LIBXML_NOERROR | LIBXML_NOWARNING);

        if (! $parsed || $document->documentElement === null) {
            throw DocxNotWritten::unreadableMarkup();
        }

        return $this->walk($document->documentElement, false, false, false);
    }

    /**
     * @return list<WordRun>
     */
    private function walk(DOMNode $node, bool $bold, bool $italic, bool $code): array
    {
        $runs = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text = $this->printable($child->textContent);

                if ($text !== '') {
                    $runs[] = new WordRun($text, $bold, $italic, $code);
                }

                continue;
            }

            $runs = [...$runs, ...$this->walk(
                $child,
                $bold || $child->nodeName === 'strong',
                $italic || $child->nodeName === 'em',
                $code || $child->nodeName === 'code',
            )];
        }

        return $runs;
    }

    /**
     * XML 1.0 has no way to carry most control characters, and a master CV
     * pasted out of another program can hold one. Dropping them costs a
     * character nobody can see; leaving them in costs the whole document,
     * because a reader refuses the file rather than the character.
     */
    private function printable(string $text): string
    {
        return (string) preg_replace('/[^\P{Cc}\t\n]/u', '', $text);
    }
}
