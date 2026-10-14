<?php

namespace App\Extraction;

use App\Llm\LlmFailure;
use Throwable;

/**
 * What kind of failure stopped an extraction, in one word.
 *
 * A provider failure already carries a class the batch lifecycle reasons about
 * — a bad request, a rate limit, a server error — and that is the useful answer
 * where there is one. Anything else is named by its own type, never by its
 * message: an exception message can quote the page that caused it.
 */
final readonly class ExtractionFailureClass
{
    public static function of(Throwable $failure): string
    {
        if ($failure instanceof LlmFailure) {
            return $failure->kind->value;
        }

        return class_basename($failure);
    }
}
