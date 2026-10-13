<?php

namespace App\Rendering;

use RuntimeException;
use Throwable;

/**
 * The converter did not return a PDF.
 *
 * The reason is a short phrase for a log line and never the converter's
 * response body: a body can quote the document it was given, and a CV body has
 * no business in a log.
 */
class PdfConversionFailed extends RuntimeException
{
    private function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct('The PDF converter failed: '.$reason.'.', previous: $previous);
    }

    public static function status(int $status): self
    {
        return new self('it answered '.$status);
    }

    public static function empty(): self
    {
        return new self('it answered with nothing');
    }

    public static function unreachable(Throwable $previous): self
    {
        return new self('it could not be reached', $previous);
    }
}
