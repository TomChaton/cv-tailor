<?php

namespace App\Postings;

use App\Models\Posting;

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
 */
class DiscardPosting
{
    public function __construct(private readonly PostingEvents $events) {}

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

        $this->events->discarded($posting, $from);

        return true;
    }
}
