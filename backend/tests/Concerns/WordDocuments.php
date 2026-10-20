<?php

namespace Tests\Concerns;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ZipArchive;

/**
 * Reading a `.docx` back out, for the tests that assert on what the owner's
 * word processor will find in it.
 *
 * It opens the package and reads its parts rather than trusting the writer's
 * own account of them: a template can emit a heading-shaped paragraph that no
 * word processor treats as a heading.
 */
trait WordDocuments
{
    /**
     * The package's parts, in the order the archive holds them.
     *
     * @return array<string, string>
     */
    protected function partsOf(string $docx): array
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'cv-docx-test-');

        file_put_contents($file, $docx);

        $package = new ZipArchive;

        $this->assertTrue($package->open($file) === true, 'The Word file is not a readable package.');

        $parts = [];

        for ($index = 0; $index < $package->numFiles; $index++) {
            $path = (string) $package->getNameIndex($index);
            $parts[$path] = (string) $package->getFromIndex($index);
        }

        $package->close();
        unlink($file);

        return $parts;
    }

    /**
     * Every paragraph of the document part: the style it names, the list depth
     * it sits at, and its text.
     *
     * @return list<array{style: ?string, bullet: ?int, text: string}>
     */
    protected function paragraphsOf(string $docx): array
    {
        $document = new DOMDocument;
        $document->loadXML($this->partsOf($docx)['word/document.xml']);

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $paragraphs = [];

        /** @var DOMNode $paragraph */
        foreach ($xpath->query('//w:body/w:p') ?: [] as $paragraph) {
            $style = $this->string($xpath->evaluate('string(w:pPr/w:pStyle/@w:val)', $paragraph));
            $bullet = $this->string($xpath->evaluate('string(w:pPr/w:numPr/w:ilvl/@w:val)', $paragraph));

            $text = '';

            /** @var DOMNode $run */
            foreach ($xpath->query('.//w:t', $paragraph) ?: [] as $run) {
                $text .= $run->textContent;
            }

            $paragraphs[] = [
                'style' => $style === '' ? null : $style,
                'bullet' => $bullet === '' ? null : (int) $bullet,
                'text' => $text,
            ];
        }

        return $paragraphs;
    }

    /**
     * `DOMXPath::evaluate` answers with whatever the expression's type is, and
     * these ones are strings.
     */
    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    protected function parses(string $xml): bool
    {
        $document = new DOMDocument;

        return $document->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING) !== false;
    }

    /**
     * The blocks of the PDF's HTML, in order, as the Word file's paragraphs
     * are comparable to them.
     *
     * @return list<string>
     */
    protected function contentOf(string $html): array
    {
        $document = new DOMDocument;
        $document->loadHTML(
            '<?xml encoding="utf-8" ?>'.$html,
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        $body = $document->getElementsByTagName('body')->item(0);

        $blocks = [];

        if ($body !== null) {
            $this->blocks($body, $blocks);
        }

        return array_values(array_filter(
            array_map(fn (string $block): string => $this->comparable($block), $blocks),
            fn (string $block): bool => $block !== '',
        ));
    }

    /**
     * A block's letters and digits and nothing else.
     *
     * The two renderings carry the same content under different punctuation: a
     * separator the PDF's stylesheet supplies in CSS is a character of the
     * Word file's own, and neither is part of what the CV claims. What has to
     * match is the content and its order.
     */
    protected function comparable(string $text): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $text);
    }

    /**
     * @param  list<string>  $blocks
     */
    private function blocks(DOMNode $node, array &$blocks): void
    {
        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if (in_array($child->tagName, ['h1', 'h2', 'h3', 'p', 'li'], true)) {
                $blocks[] = $this->ownText($child);
            }

            // Into every element, including a list item: a nested list's own
            // items follow their parent, which is the order the Word file's
            // bullets are in.
            $this->blocks($child, $blocks);
        }
    }

    /**
     * An element's text without the text of a list nested inside it, which is
     * a block of its own.
     */
    private function ownText(DOMElement $element): string
    {
        $text = '';

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && in_array($child->tagName, ['ul', 'ol'], true)) {
                continue;
            }

            $text .= $child->textContent;
        }

        return $text;
    }
}
