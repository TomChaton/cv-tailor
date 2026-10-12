<?php

namespace App\MasterCv\Lint;

enum FindingLevel: string
{
    /**
     * The CV cannot be generated from until this is fixed.
     */
    case Problem = 'problem';

    /**
     * Worth knowing and not wrong. A note never fails a lint: a format that
     * rejects what its author invented is a format its author stops
     * maintaining.
     */
    case Note = 'note';
}
