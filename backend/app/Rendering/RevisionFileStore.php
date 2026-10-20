<?php

namespace App\Rendering;

use App\Models\Revision;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Where a revision's rendered files live.
 *
 * **A revision has exactly one file of each kind.** The path is derived from
 * the revision's ID rather than from a timestamp or a hash, so rendering the
 * same revision again replaces the file instead of leaving a second copy
 * nobody can tell from the first.
 *
 * The stored path is relative to the configured root, never an absolute one:
 * the root is a named volume whose mount point is deployment configuration,
 * and a stored absolute path would be wrong the first time it moved.
 *
 * One base for both renderings rather than a store apiece. The two differ in
 * an extension and in the column that records them, and two copies of the rest
 * would eventually file the PDF and the Word file in different places.
 */
abstract readonly class RevisionFileStore
{
    public function __construct(protected Filesystem $disk) {}

    public function pathFor(Revision $revision): string
    {
        return 'revisions/'.$revision->id.'.'.$this->extension();
    }

    /**
     * The file, or null where there is none to read.
     *
     * The recorded path is the system's own statement that the file exists, so
     * a reader decides whether to offer one from the column rather than by
     * asking the disk about every revision it lists. This answers with null
     * all the same: the disk's root is a named volume, and a volume that has
     * been replaced is a missing file rather than a broken caller.
     */
    public function read(Revision $revision): ?string
    {
        $path = $this->recordedPath($revision);

        if ($path === null || ! $this->disk->exists($path)) {
            return null;
        }

        return $this->disk->get($path);
    }

    /**
     * @return string The path to record on the revision.
     */
    public function put(Revision $revision, string $contents): string
    {
        $path = $this->pathFor($revision);

        $this->disk->put($path, $contents);

        return $path;
    }

    abstract protected function extension(): string;

    /**
     * The column that says this kind of file was written.
     */
    abstract protected function recordedPath(Revision $revision): ?string;
}
