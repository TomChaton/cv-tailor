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
 */
class MasterCvIsNotUsable extends RuntimeException
{
    public static function unreadable(string $path): self
    {
        return new self(sprintf('The master CV at %s cannot be read.', $path));
    }

    public static function failedLint(string $path, LintReport $report): self
    {
        return new self(sprintf(
            'The master CV at %s has %d problem(s). Run "php artisan cv:lint" to see them.',
            $path,
            count($report->problems()),
        ));
    }
}
