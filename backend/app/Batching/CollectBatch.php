<?php

namespace App\Batching;

use App\Jobs\ApplyBatchResult;
use App\Llm\BatchOutcome;
use App\Llm\BatchResult;
use App\Llm\LlmClient;
use App\Models\LlmBatch;
use App\Models\LlmBatchRevision;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Takes an ended batch's results and hands each one to the worker.
 *
 * The results are streamed and keyed by the custom ID each one carries, never
 * by position: position-indexing works perfectly in a batch of one and
 * silently misattributes results in a batch of three.
 *
 * Collecting is resumable. A row stands at `awaiting` until a result has been
 * applied to it, so a run that dies halfway through a batch re-runs and
 * dispatches only what is left — neither applying anything twice nor dropping
 * anything. The batch closes only once no row is awaiting, which is why the
 * provider's "ended" and our "collected" are separate states.
 */
class CollectBatch
{
    public function __construct(
        private readonly LlmClient $client,
        private readonly SubmitterHalt $halt,
        private readonly BatchEvents $events,
        private readonly Dispatcher $bus,
    ) {}

    /**
     * @return bool Whether this run closed the batch.
     */
    public function handle(LlmBatch $batch): bool
    {
        $results = 0;
        $invalid = 0;

        foreach ($this->client->fetchResults($batch->provider_batch_id) as $result) {
            $results++;
            $invalid += $this->isMalformedRequest($result) ? 1 : 0;

            $this->dispatch($batch, $result);
        }

        $this->judgeTheRequestBuilder($batch, $results, $invalid);

        return $this->close($batch);
    }

    private function dispatch(LlmBatch $batch, BatchResult $result): void
    {
        $revisionId = $this->revisionId($result->customId);
        $submission = $revisionId === null ? null : $this->submission($batch, $revisionId);

        if ($submission === null) {
            // Logged and skipped rather than fatal: a result nobody can place
            // must not stop the rest of the batch being collected.
            $this->events->resultRejected($revisionId, $batch, 'this batch carries no submission for the custom ID');

            return;
        }

        if ($submission->outcome !== SubmissionOutcome::Awaiting) {
            // Already applied by an earlier run. Nothing to say and nothing to
            // do: this is resumption working.
            return;
        }

        // Applied in the worker rather than here, so that a scheduled poll is
        // never the thing that writes a revision, and so that one result that
        // cannot be stored is one failed queue job rather than a collection
        // that stopped halfway.
        $this->bus->dispatch(new ApplyBatchResult($batch, $result));
    }

    private function revisionId(string $customId): ?int
    {
        return ctype_digit($customId) ? (int) $customId : null;
    }

    private function submission(LlmBatch $batch, int $revisionId): ?LlmBatchRevision
    {
        return LlmBatchRevision::query()
            ->where('llm_batch_id', $batch->id)
            ->where('revision_id', $revisionId)
            ->first();
    }

    /**
     * Two consecutive batches whose every result is a malformed request halt
     * the submitter. One such batch is more likely a single pathological job
     * description; two in a row is the request builder.
     */
    private function judgeTheRequestBuilder(LlmBatch $batch, int $results, int $invalid): void
    {
        if ($results === 0) {
            return;
        }

        if ($invalid < $results) {
            $this->halt->recordUsableResults();

            return;
        }

        $tripped = $this->halt->recordFullyInvalid($batch->id);

        if ($tripped !== null) {
            $this->events->submitterHalted($tripped);
        }
    }

    private function close(LlmBatch $batch): bool
    {
        $awaiting = $batch->submissions()
            ->where('outcome', SubmissionOutcome::Awaiting)
            ->exists();

        if ($awaiting) {
            return false;
        }

        $outcomes = $this->outcomes($batch);

        $batch->update(['state' => $this->finalState($outcomes)]);

        $this->events->collected($batch, $outcomes);

        return true;
    }

    /**
     * A count per outcome, every outcome reported. An outcome missing from the
     * line cannot be told apart from one that has been renamed.
     *
     * @return array<string, int>
     */
    private function outcomes(LlmBatch $batch): array
    {
        $counts = array_fill_keys(SubmissionOutcome::values(), 0);

        foreach ($batch->submissions()->get() as $submission) {
            $counts[$submission->outcome->value]++;
        }

        return $counts;
    }

    /**
     * What became of the batch, not merely that it was read. A batch every one
     * of whose requests expired has not been collected in any sense worth the
     * word, and a reader of `cv:status` needs to see that without counting
     * rows.
     *
     * @param  array<string, int>  $outcomes
     */
    private function finalState(array $outcomes): LlmBatchState
    {
        $total = array_sum($outcomes);

        return match (true) {
            $total === 0 => LlmBatchState::Collected,
            $outcomes[SubmissionOutcome::Expired->value] === $total => LlmBatchState::Expired,
            $outcomes[SubmissionOutcome::Errored->value] === $total => LlmBatchState::Failed,
            default => LlmBatchState::Collected,
        };
    }

    private function isMalformedRequest(BatchResult $result): bool
    {
        return $result->outcome === BatchOutcome::Errored
            && $result->failure?->isMalformedRequest() === true;
    }
}
