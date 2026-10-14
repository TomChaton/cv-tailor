<?php

namespace App\Postings;

use App\Models\Posting;

/**
 * Records that a posting is worth a CV.
 *
 * Capture is free and speculative — a posting is saved because it looked
 * interesting for a moment — and generation costs money and attention. Those
 * are two decisions, and this is where the second one is taken. Nothing on a
 * schedule takes it: `cv:queue` sweeps `wanted`, so a posting arrives there
 * only because the owner put it there.
 *
 * **Only a captured posting can be marked.** Every later state already has a
 * revision or is the owner's own verdict on one, and marking from there would
 * either ask for a second revision of a posting that has one or walk the
 * tracker backwards.
 */
class MarkPostingWanted
{
    public function __construct(private readonly PostingEvents $events) {}

    /**
     * @return bool Whether this call was the one that marked it.
     */
    public function handle(Posting $posting): bool
    {
        // The condition is in the update, so the database settles the whole
        // decision in one statement: a posting that has moved on matches no
        // row, and a second click writes nothing and says nothing.
        $marked = Posting::query()
            ->whereKey($posting->getKey())
            ->where('status', PostingStatus::Captured)
            ->update(['status' => PostingStatus::Wanted]);

        if ($marked === 0) {
            return false;
        }

        $this->events->wanted($posting);

        return true;
    }
}
