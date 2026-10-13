<?php

namespace App\Jobs;

use App\Models\Revision;
use App\Rendering\RenderRevision;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The PDF for one validated revision, made in the worker.
 *
 * Queued rather than inline, for the same reason validation is: a conversion
 * is a call to another service, and a revision waiting on it must not be
 * holding up the revision behind it.
 *
 * Running twice is safe. A revision has exactly one PDF, at a path derived
 * from its ID, and a dispatch for one that has already been rendered does
 * nothing at all rather than converting the same document a second time.
 */
class RenderValidatedRevision implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt. A render that fails leaves the revision `validated` with
     * its error recorded, which is something the owner sees and acts on; a
     * converter that is down is not up again seconds later, and three goes at
     * it would only bury the first failure under two more.
     */
    public int $tries = 1;

    public function __construct(public readonly Revision $revision) {}

    public function handle(RenderRevision $render): void
    {
        // The database queue re-reads the model when the job is unserialised;
        // the `sync` connection hands over the instance the caller held. The
        // refresh makes the guard below read the stored status either way.
        $revision = $this->revision->refresh();

        if ($revision->status->isComplete()) {
            // `ready` already has its one PDF, and `failed` has nothing to
            // make one from. Either way the revision is immutable.
            return;
        }

        $render->handle($revision);
    }
}
