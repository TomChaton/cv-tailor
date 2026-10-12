<?php

namespace App\Revisions;

use App\Models\Revision;
use App\Observability\EventLogger;

/**
 * Holds revisions immutable once they are complete.
 *
 * An observer rather than a `booted()` hook on the model: the guard needs the
 * event logger, observers are resolved from the container, and the briefing
 * keeps models to relationships, casts and scopes.
 *
 * It judges the *stored* status, not the one being written, so a revision may
 * still be moved into `ready` or `failed` — and may never be moved out again.
 *
 * Eloquent fires no model event for a query-builder update, so the guarantee
 * holds only for writes that go through the model. Revisions are written by
 * this application's own actions, and they do.
 */
class RevisionObserver
{
    public function __construct(private readonly EventLogger $log) {}

    public function updating(Revision $revision): void
    {
        // `getOriginal` applies the model's casts, so this is the stored
        // status as an enum rather than the one being written.
        $stored = $revision->getOriginal('status');

        if (! $stored instanceof RevisionStatus || ! $stored->isComplete()) {
            return;
        }

        $this->log->warning('revision.mutation_rejected', [
            'revision_id' => $revision->getKey(),
            'status' => $stored->value,
        ]);

        throw CompletedRevisionIsImmutable::for($revision, $stored);
    }
}
