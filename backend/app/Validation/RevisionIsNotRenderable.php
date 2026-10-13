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
    /**
     * @param  string  $reason  A fixed phrase, for a log line. The message
     *                          names the revision and the checks; a log line
     *                          may carry neither a finding nor a quote from
     *                          one.
     */
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function neverValidated(Revision $revision): self
    {
        return new self(
            sprintf('Revision %d has not been validated.', $revision->id),
            'it has not been validated',
        );
    }

    public static function wrongStatus(Revision $revision): self
    {
        return new self(
            sprintf(
                'Revision %d is %s, which is not a status a PDF is rendered from.',
                $revision->id,
                $revision->status->value,
            ),
            'its status is not one a PDF is rendered from',
        );
    }

    /**
     * @param  list<string>  $checks
     */
    public static function blocked(Revision $revision, array $checks): self
    {
        return new self(
            sprintf(
                'Revision %d has blocking validation findings: %s.',
                $revision->id,
                implode(', ', $checks),
            ),
            'validation blocked it',
        );
    }

    public static function withoutOutput(Revision $revision): self
    {
        return new self(
            sprintf('Revision %d has no output to resolve.', $revision->id),
            'it has no output to resolve',
        );
    }
}
