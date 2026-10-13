<?php

namespace App\Validation;

use App\Models\Revision;
use App\Observability\EventLogger;

/**
 * Validation's log lines, in one place.
 *
 * Event names are part of the interface, so they live here rather than at
 * their call sites and are not renamed casually.
 *
 * **Counts, never findings.** A finding quotes the master CV and the generated
 * output, which is exactly what a log line may not carry. The counts say which
 * check fired and how often, which is what somebody reading the logs needs;
 * the findings themselves are on the revision for the owner to read.
 */
final readonly class ValidationEvents
{
    public function __construct(private EventLogger $log) {}

    public function validated(Revision $revision, ValidationReport $report): void
    {
        $this->log->log('revision.validated', [
            'revision_id' => $revision->id,
            'posting_id' => $revision->posting_id,
            'master_cv_version_id' => $revision->master_cv_version_id,
            'findings' => count($report->findings),
            ...$report->countsBySeverity(),
            'by_check' => $report->countsByCheck(),
        ]);
    }

    /**
     * A warning: the revision was produced and is not fit to send, which is
     * something somebody has to look at.
     */
    public function blocked(Revision $revision, ValidationReport $report): void
    {
        $this->log->warning('revision.validation_blocked', [
            'revision_id' => $revision->id,
            'posting_id' => $revision->posting_id,
            'checks' => $report->blockingChecks(),
        ]);
    }
}
