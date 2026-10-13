<?php

namespace App\Generation;

use App\Models\Revision;
use RuntimeException;

/**
 * Raised when a response cannot be stored as a selection.
 *
 * Structured outputs make malformed JSON unlikely rather than impossible: a
 * response that reaches the token ceiling comes back cut off mid-document. The
 * message carries no part of the output, because an exception is reported with
 * its whole chain and model output is not a thing to log.
 */
class GenerationOutputIsUnusable extends RuntimeException
{
    public static function notAnObject(Revision $revision): self
    {
        return new self(sprintf(
            'The response for revision %d is not a JSON object. A truncated response is the '
            .'usual cause; see the "generation.unusable" log line for its stop reason.',
            $revision->id,
        ));
    }
}
