<?php

namespace App\Validation;

use App\Models\Revision;
use RuntimeException;

/**
 * Something asked for a PDF of a revision that is not fit to be one.
 *
 * It raises rather than returning false, because the caller is a pipeline step
 * and a step that quietly does nothing produces a posting that never becomes
 * ready and says nothing about why.
 */
class RevisionIsNotRenderable extends RuntimeException
{
    public static function neverValidated(Revision $revision): self
    {
        return new self(sprintf('Revision %d has not been validated.', $revision->id));
    }

    public static function wrongStatus(Revision $revision): self
    {
        return new self(sprintf(
            'Revision %d is %s, which is not a status a PDF is rendered from.',
            $revision->id,
            $revision->status->value,
        ));
    }

    /**
     * @param  list<string>  $checks
     */
    public static function blocked(Revision $revision, array $checks): self
    {
        return new self(sprintf(
            'Revision %d has blocking validation findings: %s.',
            $revision->id,
            implode(', ', $checks),
        ));
    }
}
