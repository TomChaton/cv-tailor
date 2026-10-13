<?php

namespace App\Rendering;

use App\Models\Revision;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Where a revision's PDF lives.
 *
 * **A revision has exactly one PDF.** The path is derived from the revision's
 * ID rather than from a timestamp or a hash, so rendering the same revision
 * again replaces the file instead of leaving a second copy nobody can tell
 * from the first.
 *
 * `pdf_path` stores the path relative to the configured PDF root, never an
 * absolute one: the root is a named volume whose mount point is deployment
 * configuration, and a stored absolute path would be wrong the first time it
 * moved.
 */
final readonly class PdfStore
{
    public function __construct(private Filesystem $disk) {}

    public function pathFor(Revision $revision): string
    {
        return 'revisions/'.$revision->id.'.pdf';
    }

    /**
     * @return string The path to record on the revision.
     */
    public function put(Revision $revision, string $pdf): string
    {
        $path = $this->pathFor($revision);

        $this->disk->put($path, $pdf);

        return $path;
    }
}
