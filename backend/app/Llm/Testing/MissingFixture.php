<?php

namespace App\Llm\Testing;

use RuntimeException;

/**
 * A recorded response was asked for that is not on disk, or is not readable as
 * one.
 */
final class MissingFixture extends RuntimeException
{
    public static function at(string $path): self
    {
        return new self(sprintf('No recorded LLM response at %s.', $path));
    }

    public static function malformed(string $path, string $reason): self
    {
        return new self(sprintf('The recorded LLM response at %s is unusable: %s.', $path, $reason));
    }
}
