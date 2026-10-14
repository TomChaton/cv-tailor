<?php

namespace App\MasterCv;

use App\MasterCv\Lint\LintReport;
use RuntimeException;

/**
 * Raised when there is no master CV to use, or the stored one fails lint.
 *
 * It raises rather than returning something empty. A CV whose IDs are missing
 * or ambiguous cannot constrain the model's output, and generating from it
 * anyway is how a tailored CV comes to claim something nothing supports.
 *
 * It carries the count of problems and never the problems themselves, so a
 * caller can report how much is wrong without quoting the CV.
 */
class MasterCvIsNotUsable extends RuntimeException
{
    /**
     * @param  int|null  $problems  Null when there was no CV to lint.
     */
    private function __construct(string $message, public readonly ?int $problems = null)
    {
        parent::__construct($message);
    }

    public static function notStored(): self
    {
        return new self(
            'No master CV is stored. Write one on the Master CV page, or import one with "php artisan cv:import".',
        );
    }

    public static function failedLint(LintReport $report): self
    {
        $problems = count($report->problems());

        return new self(sprintf(
            'The master CV has %d problem(s). Run "php artisan cv:lint" to see them.',
            $problems,
        ), $problems);
    }
}
