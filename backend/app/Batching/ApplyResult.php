<?php

namespace App\Batching;

use App\Generation\GenerationOutputIsUnusable;
use App\Generation\StoreGenerationOutput;
use App\Llm\BatchOutcome;
use App\Llm\BatchResult;
use App\Models\LlmBatch;
use App\Models\LlmBatchRevision;
use App\Models\Revision;
use App\Revisions\RevisionStatus;
use Illuminate\Contracts\Translation\Translator;

/**
 * Applies one batch result to the revision it was built for.
 *
 * A result is applied only to a revision that is still waiting for it: the
 * submission row must still be `awaiting` and the revision must still be
 * `submitted`. The case that makes the guard necessary is a revision that has
 * moved on — it went out in one batch, that batch expired, it returned to
 * `pending` and went out again, and then the first batch's results arrived
 * after all. Keyed only on the custom ID, the old output would be written over
 * the revision's second life.
 */
class ApplyResult
{
    public function __construct(
        private readonly StoreGenerationOutput $store,
        private readonly BatchEvents $events,
        private readonly Translator $translator,
        private readonly int $attemptLimit,
    ) {}

    public function handle(LlmBatch $batch, BatchResult $result): void
    {
        $revisionId = ctype_digit($result->customId) ? (int) $result->customId : null;

        if ($revisionId === null) {
            $this->events->resultRejected(null, $batch, 'the custom ID names no revision');

            return;
        }

        $submission = LlmBatchRevision::query()
            ->where('llm_batch_id', $batch->id)
            ->where('revision_id', $revisionId)
            ->first();

        if ($submission === null) {
            $this->events->resultRejected($revisionId, $batch, 'this batch carries no submission for the revision');

            return;
        }

        // Claiming the row is the guard. It is a conditional update rather
        // than a read and a write, so two runs holding the same result cannot
        // both pass it, and an outcome that is already recorded is a result
        // that has already been dealt with.
        //
        // The row is claimed before the revision is looked at, because the
        // outcome is a fact about the submission whatever the revision has
        // since become. A row left `awaiting` would keep its batch open for
        // ever.
        $claimed = LlmBatchRevision::query()
            ->whereKey($submission->id)
            ->where('outcome', SubmissionOutcome::Awaiting)
            ->update(['outcome' => SubmissionOutcome::fromResult($result->outcome)]);

        if ($claimed === 0) {
            $this->events->resultRejected($revisionId, $batch, 'the result has already been applied');

            return;
        }

        $revision = Revision::query()->find($revisionId);

        if ($revision === null) {
            $this->events->resultRejected($revisionId, $batch, 'the revision is gone');

            return;
        }

        if ($revision->status !== RevisionStatus::Submitted) {
            $this->events->resultRejected($revisionId, $batch, 'the revision is not waiting for a result');

            return;
        }

        match ($result->outcome) {
            BatchOutcome::Succeeded => $this->succeeded($batch, $revision, $result),
            BatchOutcome::Errored => $this->errored($batch, $revision, $result),
            BatchOutcome::Expired => $this->spend($batch, $revision, SubmissionOutcome::Expired, 'expired'),
            BatchOutcome::Canceled => $this->spend($batch, $revision, SubmissionOutcome::Canceled, 'canceled'),
        };
    }

    private function succeeded(LlmBatch $batch, Revision $revision, BatchResult $result): void
    {
        if ($result->response === null) {
            $this->events->resultRejected($revision->id, $batch, 'the result carries no response');

            return;
        }

        try {
            $this->store->handle($revision, $result->response);
        } catch (GenerationOutputIsUnusable) {
            // A response that reached the token ceiling comes back cut off
            // mid-document. Nothing is stored, and the attempt is spent: the
            // answer is a larger ceiling, and the limit is what stops the
            // same truncation being bought over and over.
            $this->spend($batch, $revision, SubmissionOutcome::Succeeded, 'unusable');

            return;
        }

        $this->events->resultStored($revision, $batch);
    }

    private function errored(LlmBatch $batch, Revision $revision, BatchResult $result): void
    {
        if ($result->failure?->isMalformedRequest() !== true) {
            $this->spend($batch, $revision, SubmissionOutcome::Errored, 'errored');

            return;
        }

        // The request we built was refused, so the revision is not at fault
        // and no attempt of its own has been used. `failed` is terminal and
        // immutable, so driving it there would leave a child revision to
        // create for every posting caught by one bug; left `pending`, a fix
        // and a re-arm pick up everything that was waiting, intact.
        $revision->fill(['status' => RevisionStatus::Pending])->save();

        $this->events->requestInvalid($revision->id, $batch);
    }

    /**
     * The provider could not serve this request. The attempt is spent, and the
     * revision waits for another one until the limit.
     */
    private function spend(
        LlmBatch $batch,
        Revision $revision,
        SubmissionOutcome $outcome,
        string $reason,
    ): void {
        $attempts = $revision->attempts + 1;
        $exhausted = $attempts >= $this->attemptLimit;

        $revision->fill([
            'attempts' => $attempts,
            'status' => $exhausted ? RevisionStatus::Failed : RevisionStatus::Pending,
            ...($exhausted ? ['error' => $this->message($reason, $attempts)] : []),
        ])->save();

        $exhausted
            ? $this->events->resultFailed($revision, $batch, $outcome)
            : $this->events->resultRetried($revision, $batch, $outcome);
    }

    /**
     * What the owner reads on a revision that could not be produced.
     *
     * A catalogue entry like any other word on a screen, rendered here and
     * stored as written: one locale, one owner, and a sentence stored as
     * written still reads the same after this class has been rewritten.
     */
    private function message(string $reason, int $attempts): string
    {
        $key = 'revisions.failed.'.$reason;
        $message = $this->translator->get($key, ['attempts' => $attempts]);

        return is_string($message) ? $message : $key;
    }
}
