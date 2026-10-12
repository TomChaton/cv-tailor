<?php

namespace App\Generation;

use App\MasterCv\Lint\LintReport;
use App\Models\Revision;
use RuntimeException;

/**
 * Raised when a revision must not be sent to the model.
 *
 * It raises rather than returning an empty request. A CV whose IDs are missing
 * or ambiguous cannot constrain the output, and generating from it anyway
 * produces a selection nobody can resolve back to a fact.
 */
class GenerationIsRefused extends RuntimeException
{
    public static function masterCvFailedLint(Revision $revision, LintReport $report): self
    {
        return new self(sprintf(
            'Revision %d cannot be generated: its master CV has %d problem(s). '
            .'Run "php artisan cv:lint" to see them.',
            $revision->id,
            count($report->problems()),
        ));
    }
}
