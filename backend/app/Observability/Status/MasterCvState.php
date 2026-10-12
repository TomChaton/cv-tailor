<?php

namespace App\Observability\Status;

/**
 * What `cv:status` found at the configured master CV path.
 *
 * The states are separate because they send someone somewhere different. A
 * directory is not a permissions problem, and reporting it as one sends them
 * to `chmod` instead of to `MASTER_CV_HOST_PATH`.
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
}
