<?php

namespace App\Postings;

use App\Models\Posting;
use App\Models\Revision;
use App\Revisions\RevisionStatus;

/**
 * Keeps a posting's `ready` in step with the revision the owner is looking at.
 *
 * A posting's status is stored rather than derived, because `reviewed` and
 * `applied` are things the owner did and no revision knows about them. The
 * price of that is this class: `ready` is denormalised from the current
 * revision and something has to keep the two in agreement.
 *
 * **A posting moves to `ready` only from `queued`.** The tail of the status
 * list records what the owner did, so a render that arrived afterwards must
 * not drag the tracker backwards: a posting at `reviewed` or `applied` stays
 * where it is, and one at `discarded` is never moved at all.
 *
 * Only the *current* revision moves a posting. A revision the owner is not
 * looking at — a sibling that rendered late, a parent of the one on screen —
 * says nothing about where the posting has got to.
 */
class MarkPostingReady
{
    public function __construct(private readonly PostingEvents $events) {}

    /**
     * @return bool Whether this call was the one that moved the posting.
     */
    public function handle(Revision $revision): bool
    {
        if ($revision->status !== RevisionStatus::Ready) {
            return false;
        }

        // Every condition is in the update, so the whole decision is one
        // statement the database settles: a posting the owner has moved on,
        // discarded, or that is already `ready`, matches no row. That is what
        // makes a second call write nothing and say nothing — and a duplicate
        // dispatch is the ordinary consequence of a retried job, not an
        // anomaly.
        $moved = Posting::query()
            ->whereKey($revision->posting_id)
            ->where('status', PostingStatus::Queued)
            ->where('current_revision_id', $revision->getKey())
            ->update(['status' => PostingStatus::Ready]);

        if ($moved === 0) {
            return false;
        }

        $this->events->ready($revision);

        return true;
    }
}
