<?php

namespace App\Postings;

use App\Models\Posting;

/**
 * Records that the owner has read the CV.
 *
 * **Only a `ready` posting can be marked.** `reviewed` sits between the
 * pipeline's last state and the owner's own verdict, so there is one state it
 * can be reached from: a posting at `applied` has been sent and one at
 * `discarded` is finished with, and moving either here would walk the tracker
 * backwards.
 *
 * The revision in the log line is the one the owner was looking at when they
 * said so, read from the posting rather than from the row the update wrote.
 */
class MarkPostingReviewed
{
    public function __construct(private readonly PostingEvents $events) {}

    /**
     * @return bool Whether this call was the one that marked it.
     */
    public function handle(Posting $posting): bool
    {
        $revisionId = $posting->current_revision_id;

        // The condition is in the update, as every other move of this column
        // is: a posting that has moved on matches no row, and a second click
        // writes nothing and says nothing.
        $marked = Posting::query()
            ->whereKey($posting->getKey())
            ->where('status', PostingStatus::Ready)
            ->update(['status' => PostingStatus::Reviewed]);

        if ($marked === 0) {
            return false;
        }

        $this->events->reviewed($posting, $revisionId);

        return true;
    }
}
