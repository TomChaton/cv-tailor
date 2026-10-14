<?php

namespace App\Jobs;

use App\Extraction\ExtractionEvents;
use App\Extraction\ExtractPosting;
use App\Models\Posting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Extraction for one captured posting, run in the worker.
 *
 * Queued rather than inline: capture must answer the extension at the speed of
 * a database write, and a model call inside the request would make the owner's
 * browser wait for it.
 *
 * **A failure here never loses a posting.** The posting stays listed with its
 * fields absent and `extracted_at` null, and it is markable as wanted
 * regardless — a posting the owner recognises from its URL is still worth a CV.
 * The failure is logged and rethrown, so the queue retries it on its own terms
 * and a posting nothing could read eventually reaches `failed_jobs` where
 * `cv:status` counts it.
 *
 * Running twice is safe: a posting that has already been extracted is left
 * alone rather than read again at a second model's expense.
 */
class ExtractPostingFields implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Posting $posting) {}

    public function handle(ExtractPosting $extract, ExtractionEvents $events): void
    {
        // The database queue re-reads the model when the job is unserialised;
        // the `sync` connection hands over the instance the caller held. The
        // refresh makes the guard inside read the stored row either way.
        $posting = $this->posting->refresh();

        try {
            $extract->handle($posting);
        } catch (Throwable $failure) {
            $events->failed($posting, $failure, $this->attempts());

            throw $failure;
        }
    }
}
