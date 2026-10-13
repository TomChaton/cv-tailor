<?php

namespace App\MasterCv;

use App\MasterCv\Lint\LintReport;
use RuntimeException;

/**
 * Raised when the master CV cannot be read, or fails lint.
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
     * @param  int|null  $problems  Null when the CV never reached its lint.
     */
    private function __construct(string $message, public readonly ?int $problems = null)
    {
        parent::__construct($message);
    }

    public static function unreadable(string $path): self
    {
        return new self(sprintf('The master CV at %s cannot be read.', $path));
    }

    public static function failedLint(string $path, LintReport $report): self
    {
        $problems = count($report->problems());

        return new self(sprintf(
            'The master CV at %s has %d problem(s). Run "php artisan cv:lint" to see them.',
            $path,
            $problems,
        ), $problems);
    }
}
