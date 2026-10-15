<?php

namespace App\Revisions;

use App\Models\Posting;
use App\Models\Revision;

/**
 * Stops the generation nobody is waiting for any more.
 *
 * **`abandoned` is not `failed`.** A failure is the model or the provider not
 * delivering, and it spends one of a revision's attempts. This is a decision
 * the owner took, it costs nothing, and nothing ever retries it.
 *
 * **Only a `pending` revision is abandoned.** One that has already gone out
 * has been paid for, and its submission row has to be resolved or its batch
 * never closes — so it runs to completion and its result is applied exactly as
 * it would have been. `MarkPostingReady` is what then declines to move a
 * discarded posting.
 */
class AbandonPendingRevisions
{
    public function __construct(private readonly RevisionEvents $events) {}

    /**
     * @return int How many revisions this call abandoned.
     */
    public function handle(Posting $posting): int
    {
        $abandoned = 0;

        foreach ($this->pending($posting) as $revision) {
            $from = $revision->status;

            // **This is not a mutation of a completed revision, so the
            // immutability rule is untouched.** That rule is about a revision
            // moving *away* from `ready` or `failed`, and the condition below
            // is what holds it: only a `pending` row matches, and `pending` is
            // neither. The rule's own guard would reach the same answer — it
            // reads the stored status and lets a revision that is not complete
            // be written — but it observes model writes rather than
            // query-builder ones, and this has to be a conditional update:
            // the submitter claims a revision with the same idiom, and one it
            // claimed between this read and this write is already paid for and
            // must be left `submitted`.
            $written = Revision::query()
                ->whereKey($revision->getKey())
                ->where('status', $from)
                ->update(['status' => RevisionStatus::Abandoned]);

            if ($written === 0) {
                continue;
            }

            // Written here rather than by the caller, so there is one place
            // that says a revision was abandoned however the decision arrived.
            $this->events->abandoned($revision, $from);

            $abandoned++;
        }

        return $abandoned;
    }

    /**
     * @return list<Revision>
     */
    private function pending(Posting $posting): array
    {
        /** @var list<Revision> */
        return Revision::query()
            ->where('posting_id', $posting->getKey())
            ->where('status', RevisionStatus::Pending)
            ->oldest('id')
            ->get()
            ->all();
    }
}
