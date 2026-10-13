<?php

namespace App\Postings;

use App\Models\Posting;

/**
 * Records a captured posting, or finds the one that already holds it.
 *
 * **Nothing here reads the master CV and nothing here creates a revision.** A
 * posting captured while the CV fails its lint is still a posting worth having,
 * so capture is the one part of the pipeline that cannot be stopped by the
 * state of the CV. Queueing it for generation is a separate step.
 */
class CapturePosting
{
    public function __construct(private readonly PostingEvents $events) {}

    public function handle(PostingCapture $capture): CapturedPosting
    {
        // `firstOrCreate` keyed on the hash, so two captures of the same
        // posting arriving at once cannot both insert: the unique index
        // refuses the second and Eloquent re-reads the row that won.
        $posting = Posting::firstOrCreate(
            ['dedupe_hash' => DedupeHash::for($capture)],
            [...$capture->attributes(), 'status' => PostingStatus::Captured],
        );

        $isNew = $posting->wasRecentlyCreated;

        // A match returns the posting as it stands. The stored capture is the
        // one that created it, because that is the material the posting's
        // revisions were generated from.
        if ($isNew) {
            $this->events->captured($posting, $capture);
        } else {
            $this->events->duplicate($posting, $capture);
        }

        return new CapturedPosting($posting->id, $posting->status, $isNew);
    }
}
