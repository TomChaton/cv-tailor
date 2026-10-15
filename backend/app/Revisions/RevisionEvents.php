<?php

namespace App\Revisions;

use App\Models\Revision;
use App\Observability\EventLogger;

/**
 * The revisions' own log lines, in one place.
 *
 * Event names are part of the interface, so they live here rather than at
 * their call sites and are not renamed casually.
 *
 * Nothing here carries CV content, prompt text or model output. Identifiers,
 * counts and outcomes only: a log line that would be embarrassing in a public
 * bug report is a bug.
 */
final readonly class RevisionEvents
{
    public function __construct(private EventLogger $log) {}

    /**
     * A revision nobody is waiting for any more.
     *
     * `from` is the status it left, as `posting.discarded` carries the
     * posting's. One line per revision, written where the status is written,
     * so there is a single place that says it however the decision arrived.
     */
    public function abandoned(Revision $revision, RevisionStatus $from): void
    {
        $this->log->log('revision.abandoned', [
            'revision_id' => $revision->id,
            'posting_id' => $revision->posting_id,
            'from' => $from->value,
        ]);
    }
}
