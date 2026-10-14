<?php

namespace App\MasterCv;

use RuntimeException;

/**
 * Raised when an import will not go ahead.
 *
 * The two reasons send whoever ran it somewhere different: a file that is not
 * there is a path to correct, and a CV that is already stored is a decision to
 * take. Flattening them into one message would answer neither question.
 */
class MasterCvImportRefused extends RuntimeException
{
    public static function unreadable(string $path): self
    {
        return new self(sprintf('%s cannot be read.', $path));
    }

    /**
     * The stored CV may be the edited one, and an import that replaced it
     * without being asked twice would throw away the only copy of it.
     */
    public static function alreadyStored(): self
    {
        return new self('A master CV is already stored. Pass --force to replace it.');
    }
}
