<?php

namespace App\MasterCv\Fixes;

use RuntimeException;

/**
 * A guided fix that would not have written exactly one row, and so wrote
 * nothing.
 *
 * The master CV is the document every other guarantee in this system is
 * measured against. A fix that cannot prove what it touched is a fix that does
 * not run.
 */
class WhereUsedNotRewritten extends RuntimeException
{
    public static function noSuchRow(int $line): self
    {
        return new self('Line '.$line.' is not the skills-index row this fix was offered for.');
    }

    public static function moreThanOneRow(): self
    {
        return new self('A guided fix changed more than the row it was offered for.');
    }
}
