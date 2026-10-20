<?php

namespace App\Rendering\Word;

use RuntimeException;
use Throwable;

/**
 * The Word file could not be assembled.
 *
 * The message says what step failed and never what the document said: a CV
 * body has no business in a log or in an exception that reaches one.
 */
class DocxNotWritten extends RuntimeException
{
    public static function noTemporaryFile(?Throwable $previous = null): self
    {
        return new self('No temporary file could be created for the Word package.', previous: $previous);
    }

    public static function packageNotOpened(): self
    {
        return new self('The Word package could not be opened for writing.');
    }

    public static function partNotAdded(string $part): self
    {
        return new self('The Word package rejected the part '.$part.'.');
    }

    public static function packageNotClosed(): self
    {
        return new self('The Word package could not be closed.');
    }

    public static function packageNotRead(?Throwable $previous = null): self
    {
        return new self('The finished Word package could not be read back.', previous: $previous);
    }

    public static function unreadableMarkup(): self
    {
        return new self('A line of the document could not be read as inline markup.');
    }
}
