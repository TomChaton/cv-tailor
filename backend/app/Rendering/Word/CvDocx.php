<?php

namespace App\Rendering\Word;

use App\Rendering\DocxWriter;
use App\Rendering\ResolvedDocument;
use Illuminate\Contracts\View\Factory;

/**
 * The Word file of one resolved document.
 *
 * Every part of the package is a template of this application's own, beside
 * the CV's PDF template, so the structure the file opens with is stated here
 * rather than inferred by a library: the document part for the content, the
 * styles part for the headings it names, the numbering part for its bullets.
 *
 * The document is handed to the templates as an object rather than as an
 * array, so a field renamed in the document model breaks the template instead
 * of silently rendering nothing.
 */
final readonly class CvDocx implements DocxWriter
{
    /**
     * Where each part sits in the package, and the template that writes it.
     * The content types go in first: that is the part a reader looks for at
     * the front of the archive.
     */
    private const PARTS = [
        '[Content_Types].xml' => 'cv.word.content-types',
        '_rels/.rels' => 'cv.word.rels',
        'word/_rels/document.xml.rels' => 'cv.word.document-rels',
        'word/styles.xml' => 'cv.word.styles',
        'word/numbering.xml' => 'cv.word.numbering',
        'word/document.xml' => 'cv.word.document',
    ];

    /**
     * The declaration every part carries. It is prepended rather than written
     * into each template, because a template that opened with `<?xml` would be
     * read as PHP wherever short open tags are enabled.
     */
    private const DECLARATION = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";

    public function __construct(
        private Factory $views,
        private InlineRuns $runs,
        private DocxPackage $package,
    ) {}

    public function write(ResolvedDocument $document): string
    {
        $parts = [];

        foreach (self::PARTS as $path => $view) {
            $parts[$path] = self::DECLARATION.trim($this->views->make($view, [
                'document' => $document,
                'runs' => $this->runs,
            ])->render());
        }

        return $this->package->zip($parts);
    }
}
