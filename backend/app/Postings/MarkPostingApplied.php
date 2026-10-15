<?php

namespace App\Postings;

use App\Models\Posting;

/**
 * Records that an application went in, and which CV went with it.
 *
 * **It writes `applied_revision_id` as well as the status.** The column is
 * there so that the version an employer actually read is answerable later, and
 * a tracker that knows a job was applied for but not with which CV is half a
 * record. `current_revision_id` is the revision the owner is looking at; this
 * one is the revision they sent, and only the second of those is still true
 * once a regeneration has happened.
 *
 * **It is reachable from `ready` and from `reviewed`.** Applying without
 * marking the CV read first is an ordinary thing to do — the owner read it on
 * the way to sending it — so demanding `reviewed` first would be a step that
 * records nothing. Neither `applied` nor `discarded` moves: the first is
 * already the record and the second is finished with.
 */
class MarkPostingApplied
{
    public function __construct(private readonly PostingEvents $events) {}

    /**
     * @return bool Whether this call was the one that marked it.
     */
    public function handle(Posting $posting): bool
    {
        $revisionId = $posting->current_revision_id;

        // Nothing to record. A posting at `ready` has a current revision by
        // construction, so this is the belt-and-braces case rather than one
        // the screen offers.
        if ($revisionId === null) {
            return false;
        }

        // Status and revision in one conditional update, so the row cannot
        // carry an applied status without the revision that was applied.
        $applied = Posting::query()
            ->whereKey($posting->getKey())
            ->whereIn('status', [PostingStatus::Ready, PostingStatus::Reviewed])
            ->update([
                'status' => PostingStatus::Applied,
                'applied_revision_id' => $revisionId,
            ]);

        if ($applied === 0) {
            return false;
        }

        $this->events->applied($posting, $revisionId);

        return true;
    }
}
