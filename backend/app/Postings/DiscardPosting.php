<?php

namespace App\Postings;

use App\Models\Posting;
use App\Revisions\AbandonPendingRevisions;

/**
 * Takes a posting out of the owner's way for good.
 *
 * **A discarded posting is never queued, whatever else was true of it.**
 * `cv:queue` sweeps `wanted` and discarding moves a posting off it, so the
 * guarantee is the status itself rather than a condition anything has to
 * remember to apply.
 *
 * It is reachable from every state but its own: a posting is discarded on
 * sight, or after its CV has been read, or after the application came to
 * nothing, and all three are the same decision.
 *
 * **Discarding also abandons the generation nobody is waiting for any more.**
 * A revision left `pending` would otherwise be submitted and paid for, and its
 * result applied to a posting that was thrown away — the same spend the
 * `wanted` gate exists to prevent, arriving by the other door.
 */
class DiscardPosting
{
    public function __construct(
        private readonly AbandonPendingRevisions $abandon,
        private readonly PostingEvents $events,
    ) {}

    /**
     * @return bool Whether this call was the one that discarded it.
     */
    public function handle(Posting $posting): bool
    {
        $from = $posting->status;

        if ($from === PostingStatus::Discarded) {
            return false;
        }

        // Conditional on the status the log line will name, so the two cannot
        // disagree. A posting that moved between being read and being
        // discarded matches no row, and is left to whatever moved it rather
        // than reported as discarded from a state it had already left.
        $discarded = Posting::query()
            ->whereKey($posting->getKey())
            ->where('status', $from)
            ->update(['status' => PostingStatus::Discarded]);

        if ($discarded === 0) {
            return false;
        }

        // After the posting has moved, so only the call that discarded it
        // abandons anything, and before the line that reports the discard, so
        // that line can say how much work went with it. There is no
        // transaction around the pair: a run that stopped between them would
        // leave a pending revision behind a discarded posting, which is
        // precisely what the submitter declines to submit.
        $abandoned = $this->abandon->handle($posting);

        $this->events->discarded($posting, $from, $abandoned);

        return true;
    }
}
