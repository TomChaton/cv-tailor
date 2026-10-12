<?php

namespace App\Revisions;

use App\Models\Revision;
use RuntimeException;

/**
 * Raised when something tries to write to a revision that is already complete.
 *
 * It raises rather than passing quietly: a write that is dropped in silence
 * leaves a caller believing it changed history, and a history nobody can trust
 * is worse than one that is missing.
 */
class CompletedRevisionIsImmutable extends RuntimeException
{
    public static function for(Revision $revision, RevisionStatus $stored): self
    {
        return new self(sprintf(
            'Revision %d is %s and cannot be changed. Create a child revision instead.',
            $revision->id,
            $stored->value,
        ));
    }
}
