<?php

namespace App\Generation;

use App\MasterCv\Lint\LintReport;
use App\Models\Posting;
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

    /**
     * A posting with no job description is not a posting there is a brief for.
     *
     * The page's own text is not a job description: it carries the navigation,
     * the footer and, on the boards this exists for, a sidebar of other
     * people's adverts. A CV tailored against that is tailored to a job nobody
     * captured, and reads exactly like a good one — so it raises here rather
     * than generating from whatever text the capture happened to hold.
     *
     * The sweep is what makes this unreachable: it takes only postings that
     * carry a description, and this is the guard that keeps the two in step.
     */
    public static function postingHasNoJobDescription(Posting $posting): self
    {
        return new self(sprintf(
            'Posting %d cannot be generated from: it has no job description. '
            .'Run "php artisan cv:status" to see what is waiting for one.',
            $posting->id,
        ));
    }
}
