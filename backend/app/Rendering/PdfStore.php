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
     * A revision's PDF, or null where there is no file to read.
     *
     * `pdf_path` is the system's own statement that a PDF exists, so a reader
     * decides whether to offer one from the column rather than by asking the
     * disk about every revision it lists. This answers with null all the same:
     * the disk's root is a named volume, and a volume that has been replaced
     * is a missing file rather than a broken caller.
     */
    public function read(Revision $revision): ?string
    {
        $path = $revision->pdf_path;

        if ($path === null || ! $this->disk->exists($path)) {
            return null;
        }

        return $this->disk->get($path);
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
