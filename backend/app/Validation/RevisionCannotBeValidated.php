<?php

namespace App\Validation;

use App\Models\Revision;
use RuntimeException;

/**
 * A revision was handed to validation with nothing to validate.
 *
 * It raises rather than recording an empty report. An empty report means
 * "checked and clean", which is the one thing a revision with no output is
 * not.
 */
class RevisionCannotBeValidated extends RuntimeException
{
    public static function withoutOutput(Revision $revision): self
    {
        return new self(sprintf('Revision %d has no output to validate.', $revision->id));
    }
}
