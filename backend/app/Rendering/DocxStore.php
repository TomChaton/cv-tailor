<?php

namespace App\Rendering;

use App\Models\Revision;

/**
 * Where a revision's Word file lives: the file the owner edits.
 *
 * Beside the PDF, on the same disk. The two are one revision's renderings of
 * one document, and a second volume for the convenience copy would be a second
 * thing to back up and to mount.
 */
final readonly class DocxStore extends RevisionFileStore
{
    protected function extension(): string
    {
        return 'docx';
    }

    protected function recordedPath(Revision $revision): ?string
    {
        return $revision->docx_path;
    }
}
