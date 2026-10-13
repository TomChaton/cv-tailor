<?php

namespace App\MasterCv;

use Illuminate\Filesystem\Filesystem;

/**
 * What is at the configured master CV path.
 *
 * The states are separate because they send someone somewhere different. A
 * directory is not a permissions problem, and reporting it as one sends them
 * to `chmod` instead of to `MASTER_CV_HOST_PATH`.
 *
 * The values are what `cv:status` prints. The review UI reads the same states
 * and takes its own wording from its catalogue, so one answer to "what is
 * there" serves both and the two surfaces cannot disagree.
 */
enum MasterCvState: string
{
    case Readable = 'readable';

    case NotFound = 'not found';

    /**
     * Docker creates an empty directory when a bind mount's source does not
     * exist, so this is what a wrong host path looks like from in here.
     */
    case Directory = 'a directory, not a file';

    case NotRegularFile = 'not a regular file';

    case NotReadable = 'not readable';

    public static function at(Filesystem $files, string $path): self
    {
        if (! $files->exists($path)) {
            return self::NotFound;
        }

        if (! $files->isFile($path)) {
            return $files->isDirectory($path)
                ? self::Directory
                : self::NotRegularFile;
        }

        return $files->isReadable($path)
            ? self::Readable
            : self::NotReadable;
    }
}
