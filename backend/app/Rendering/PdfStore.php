<?php

namespace App\Rendering;

use App\Models\Revision;

/**
 * Where a revision's PDF lives: the file the owner sends.
 */
final readonly class PdfStore extends RevisionFileStore
{
    protected function extension(): string
    {
        return 'pdf';
    }

    protected function recordedPath(Revision $revision): ?string
    {
        return $revision->pdf_path;
    }
}
