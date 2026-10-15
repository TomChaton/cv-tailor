<?php

namespace App\Extraction;

use App\Jobs\ExtractPostingFields;
use App\Models\Posting;
use App\Postings\PostingStatus;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Asks again about every posting extraction has not answered.
 *
 * Extraction spends its attempts and gives up, and what is left is a posting
 * listed with no title and `extracted_at` null. What stopped it is usually not
 * the page: an API key that was not set, a model with no configured rate, a
 * provider that was unreachable. The fix for each of those is configuration,
 * and this is what applies it to the postings that were waiting on it — the
 * same shape as a master CV being corrected and the next sweep queueing
 * everything that was blocked.
 *
 * **It dispatches the ordinary extraction job**, so a posting read again here
 * emits exactly the events it did the first time. This is the same work, not a
 * new kind of it.
 *
 * A posting extraction has already answered is left alone, by the same
 * `extracted_at` guard that makes a retried queue job cheap: a second reading
 * of the same page would be paid for twice to reach the same answer. That
 * includes a page extraction read and found no job in — the answer to one of
 * those is to discard the posting, not to buy the answer again. A discarded
 * posting is never read at all.
 */
class ExtractWaitingPostings
{
    public function __construct(private readonly Dispatcher $bus) {}

    /**
     * @return int How many postings were asked about again.
     */
    public function handle(): int
    {
        $waiting = Posting::query()
            ->whereNull('extracted_at')
            ->whereNot('status', PostingStatus::Discarded)
            // The key alone: a posting carries a whole page's text, none of
            // which is needed to dispatch a job, and the job re-reads the row
            // it is handed anyway.
            ->select('id')
            ->oldest('id')
            ->get();

        foreach ($waiting as $posting) {
            $this->bus->dispatch(new ExtractPostingFields($posting));
        }

        return $waiting->count();
    }
}
