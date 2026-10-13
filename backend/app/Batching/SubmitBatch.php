<?php

namespace App\Batching;

use App\Generation\GenerationIsRefused;
use App\Llm\BatchRequest;
use App\Llm\LlmClient;
use App\Llm\LlmFailure;
use App\Models\LlmBatch;
use App\Models\LlmBatchRevision;
use App\Models\Revision;
use App\Revisions\RevisionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Sends every revision that is waiting to the provider, as one batch.
 *
 * There is no minimum batch size and no maximum wait. The batch API's discount
 * applies to a batch of one, so a quorum would buy nothing and cost every
 * revision a wait for company that may never arrive.
 */
class SubmitBatch
{
    public function __construct(
        private readonly LlmClient $client,
        private readonly BatchRequestBuilder $requests,
        private readonly SubmitterHalt $halt,
        private readonly BatchEvents $events,
        private readonly ConnectionInterface $database,
    ) {}

    /**
     * @throws LlmFailure When the provider would not take the batch. Nothing
     *                    has moved: every revision is still `pending`.
     */
    public function handle(): SubmitOutcome
    {
        if ($this->halt->isHalted()) {
            return SubmitOutcome::halted($this->halt->haltedBy());
        }

        $pending = $this->pending();

        if ($pending === []) {
            return SubmitOutcome::nothingPending();
        }

        /** @var array<int, BatchRequest> $requests */
        $requests = [];
        $refused = 0;

        foreach ($pending as $revision) {
            try {
                $requests[$revision->id] = $this->requests->batchRequestFor($revision);
            } catch (GenerationIsRefused) {
                // The builder has already written `generation.refused`, which
                // is the alarm. The revision stays `pending`, so the first run
                // after the master CV is fixed picks it up.
                $refused++;
            }
        }

        if ($requests === []) {
            return SubmitOutcome::nothingUsable($refused);
        }

        // Submitted before anything is written down, so that a failure here
        // leaves every revision `pending` rather than `submitted` with no
        // batch to collect it — a state nothing in the system would ever move
        // out of. The cost is the other way round: a crash between the
        // provider accepting the batch and this recording it loses track of a
        // batch that was paid for. The client writes `llm.batch_submitted`
        // with the provider's ID before this returns, so the log holds the one
        // thing needed to find it again.
        $providerBatchId = $this->client->submitBatch(array_values($requests));

        $batch = $this->record($providerBatchId, array_keys($requests));

        $this->events->submitted($batch);

        return SubmitOutcome::submitted($batch, $refused);
    }

    /**
     * Revisions waiting to be generated, oldest first.
     *
     * @return list<Revision>
     */
    private function pending(): array
    {
        /** @var list<Revision> */
        return Revision::query()
            ->where('status', RevisionStatus::Pending)
            ->oldest('id')
            ->get()
            ->all();
    }

    /**
     * @param  list<int>  $revisionIds
     */
    private function record(string $providerBatchId, array $revisionIds): LlmBatch
    {
        return $this->database->transaction(function () use ($providerBatchId, $revisionIds): LlmBatch {
            $batch = LlmBatch::create([
                'provider_batch_id' => $providerBatchId,
                'state' => LlmBatchState::Submitted,
                'submitted_at' => CarbonImmutable::now(),
                'request_count' => count($revisionIds),
            ]);

            foreach ($revisionIds as $revisionId) {
                // The claim is the lock, and it is an ordinary conditional
                // update rather than `SELECT ... FOR UPDATE`: SQLite ignores
                // row locks and nothing here may depend on one database's
                // dialect. A revision another run has already moved off
                // `pending` gets no submission row, so this batch's result for
                // it is dropped by the guard rather than written over that
                // run's work.
                $claimed = Revision::query()
                    ->whereKey($revisionId)
                    ->where('status', RevisionStatus::Pending)
                    ->update(['status' => RevisionStatus::Submitted]);

                if ($claimed === 0) {
                    continue;
                }

                LlmBatchRevision::create([
                    'llm_batch_id' => $batch->id,
                    'revision_id' => $revisionId,
                    'outcome' => SubmissionOutcome::Awaiting,
                ]);
            }

            return $batch;
        });
    }
}
