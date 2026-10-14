<?php

namespace App\Extraction;

use App\Models\Posting;
use RuntimeException;

/**
 * The model answered, and the answer cannot be read as a report about a page.
 *
 * It raises rather than storing nothing, so the queue tries again and
 * `posting.extraction_failed` says what happened. The message names the posting
 * and never quotes the answer.
 */
final class ExtractionOutputIsUnusable extends RuntimeException
{
    public static function notAnObject(Posting $posting): self
    {
        return new self(sprintf(
            'Extraction for posting %d did not answer with a JSON object.',
            $posting->id,
        ));
    }
}
