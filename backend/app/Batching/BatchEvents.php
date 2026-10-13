<?php

namespace App\Batching;

use App\Models\LlmBatch;
use App\Models\Revision;
use App\Observability\EventLogger;

/**
 * The batch lifecycle's log lines, in one place.
 *
 * Event names are part of the interface, so they live here rather than at
 * their call sites and are not renamed casually.
 *
 * Nothing here carries prompt text, job description text, CV content or model
 * output. Identifiers, counts and outcomes only: a log line that would be
 * embarrassing in a public bug report is a bug.
 */
final readonly class BatchEvents
{
    public function __construct(private EventLogger $log) {}

    public function submitted(LlmBatch $batch): void
    {
        $this->log->log('batch.submitted', [
            'llm_batch_id' => $batch->id,
            'provider_batch_id' => $batch->provider_batch_id,
            'request_count' => $batch->request_count,
        ]);
    }

    /**
     * The provider has finished with the batch. What it holds is not ours
     * until it has been collected, which is why the two are separate lines.
     */
    public function ended(LlmBatch $batch): void
    {
        $this->log->log('batch.ended', [
            'llm_batch_id' => $batch->id,
        ]);
    }

    /**
     * @param  array<string, int>  $outcomes  A count per submission outcome,
     *                                        every outcome reported.
     */
    public function collected(LlmBatch $batch, array $outcomes): void
    {
        $this->log->log('batch.collected', [
            'llm_batch_id' => $batch->id,
            'state' => $batch->state->value,
            ...$outcomes,
        ]);
    }

    /**
     * A batch that has been open too long. The expensive failure is not a
     * request that errors but a batch that is paid for and never collected,
     * so this is the warning that says so while there is still time.
     */
    public function stale(LlmBatch $batch, int $minutes): void
    {
        $this->log->warning('batch.stale', [
            'llm_batch_id' => $batch->id,
            'provider_batch_id' => $batch->provider_batch_id,
            'state' => $batch->state->value,
            'age_minutes' => $minutes,
        ]);
    }

    /**
     * The provider refused the request we built. The revision is not at fault,
     * and this is the signal that the request builder is broken.
     */
    public function requestInvalid(int $revisionId, LlmBatch $batch): void
    {
        $this->log->warning('batch.request_invalid', [
            'revision_id' => $revisionId,
            'llm_batch_id' => $batch->id,
        ]);
    }

    /**
     * @param  list<int>  $llmBatchIds  The two batches that tripped it.
     */
    public function submitterHalted(array $llmBatchIds): void
    {
        $this->log->error('batch.submitter_halted', [
            'llm_batch_ids' => $llmBatchIds,
        ]);
    }

    /**
     * @param  list<int>  $llmBatchIds  The batches the halt was holding.
     * @param  int  $released  Revisions that were waiting on the halt.
     */
    public function submitterResumed(array $llmBatchIds, int $released): void
    {
        $this->log->log('batch.submitter_resumed', [
            'llm_batch_ids' => $llmBatchIds,
            'released' => $released,
        ]);
    }

    public function resultStored(Revision $revision, LlmBatch $batch): void
    {
        $this->log->log('revision.result_stored', [
            'revision_id' => $revision->id,
            'llm_batch_id' => $batch->id,
        ]);
    }

    /**
     * A result that was not applied, and why. A revision that has moved on
     * since its batch went out must not be written over by the old batch's
     * output, and a result dropped in silence would be indistinguishable from
     * one that never arrived.
     *
     * @param  string  $reason  A fixed phrase, never a quote from the result.
     */
    public function resultRejected(?int $revisionId, LlmBatch $batch, string $reason): void
    {
        $this->log->warning('revision.result_rejected', [
            'revision_id' => $revisionId,
            'llm_batch_id' => $batch->id,
            'reason' => $reason,
        ]);
    }

    /**
     * The provider could not serve this request and may well serve it next
     * time, so the revision goes back to `pending` having used an attempt.
     */
    public function resultRetried(Revision $revision, LlmBatch $batch, SubmissionOutcome $outcome): void
    {
        $this->log->log('revision.result_retried', [
            'revision_id' => $revision->id,
            'llm_batch_id' => $batch->id,
            'outcome' => $outcome->value,
            'attempts' => $revision->attempts,
        ]);
    }

    public function resultFailed(Revision $revision, LlmBatch $batch, SubmissionOutcome $outcome): void
    {
        $this->log->warning('revision.result_failed', [
            'revision_id' => $revision->id,
            'llm_batch_id' => $batch->id,
            'outcome' => $outcome->value,
            'attempts' => $revision->attempts,
        ]);
    }
}
