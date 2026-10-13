<?php

namespace App\Rendering\Html;

use App\Rendering\ResolvedDocument;
use Illuminate\Contracts\View\Factory;

/**
 * The HTML of one resolved document.
 *
 * The template is handed the document as an object rather than as an array, so
 * a field renamed in the document model breaks the template instead of
 * silently rendering nothing.
 *
 * Every word the template supplies of its own — the section headings — comes
 * from `lang/en_GB`. The CV's content never does: it arrives from the master CV
 * through the pipeline, and a catalogue is for words the owner reads on a
 * screen.
 */
final readonly class CvHtml
{
    public function __construct(
        private Factory $views,
        private InlineMarkdown $markdown,
    ) {}

    public function for(ResolvedDocument $document): string
    {
        return $this->views->make('cv.document', [
            'document' => $document,
            'markdown' => $this->markdown,
        ])->render();
    }
}
