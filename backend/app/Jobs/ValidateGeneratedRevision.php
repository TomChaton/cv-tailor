<?php

namespace App\Jobs;

use App\Models\Revision;
use App\Validation\RenderGuard;
use App\Validation\ValidateRevision;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Validation, run for one revision, and the decision of what happens next.
 *
 * Queued rather than inline. A revision arriving from a collected batch is
 * already being handled in the worker, and validating it inside that job would
 * make one slow revision hold up every other result the batch carried.
 *
 * The decision is the render guard's, not this job's: the guard is the one
 * place that says whether a revision may become a PDF, and a second reading of
 * the findings here would be a second place for that answer to be wrong. A
 * revision the guard refuses stays `validated` and waits for the owner —
 * nothing is dispatched, and `revision.validation_blocked` is already in the
 * log to say why.
 *
 * Running twice is safe. The checks are deterministic over a revision's output
 * and the master CV version it names, so a second run writes the same findings
 * over themselves rather than a second set of them. A duplicate dispatch is
 * the ordinary consequence of a retried job, not an anomaly.
 */
class ValidateGeneratedRevision implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Revision $revision) {}

    public function handle(ValidateRevision $validate, RenderGuard $guard, Dispatcher $bus): void
    {
        // The database queue re-reads the model when the job is unserialised;
        // the `sync` connection hands over the instance the caller held. The
        // refresh makes the guard below read the stored status either way.
        $revision = $this->revision->refresh();

        if ($revision->status->isComplete()) {
            // A revision that has been rendered or failed is immutable, so
            // there is nothing to write and nothing to decide. The run that
            // completed it left its own lines in the log.
            return;
        }

        $validate->handle($revision);

        if ($guard->allows($revision)) {
            $bus->dispatch(new RenderValidatedRevision($revision));
        }
    }
}
