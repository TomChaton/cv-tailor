<?php

namespace App\Batching;

use App\Generation\GenerationIsRefused;
use App\Llm\BatchRequest;
use App\Llm\LlmClient;
use App\Llm\LlmFailure;
use App\Llm\ModelRegistry;
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
 *
 * **Claiming a revision records the model its request asked for**, which is
 * the name the result will be priced from when it is collected. The provider
 * answers about the build that served the request, and nothing is configured
 * under that name, so the name asked for has to be written where collection
 * can find it.
 */
class SubmitBatch
{
    public function __construct(
        private readonly LlmClient $client,
        private readonly BatchRequestBuilder $requests,
        private readonly ModelRegistry $models,
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

        $refusals = RefusalReason::none();

        // Counted before anything is selected, because a run that passed one
        // over would otherwise look like a run that found nothing: these
        // revisions are deliberately left `pending` and nothing will ever pick
        // them up.
        $refusals[RefusalReason::PostingDiscarded->value] = $this->behindADiscardedPosting();

        $pending = $this->pending();

        if ($pending === []) {
            return array_sum($refusals) === 0
                ? SubmitOutcome::nothingPending()
                : SubmitOutcome::nothingUsable($refusals);
        }

        /** @var array<int, BatchRequest> $requests */
        $requests = [];

        foreach ($pending as $revision) {
            try {
                $requests[$revision->id] = $this->requests->batchRequestFor($revision);
            } catch (GenerationIsRefused) {
                // The builder has already written `generation.refused`, which
                // is the alarm. The revision stays `pending`, so the first run
                // after the master CV is fixed picks it up.
                $refusals[RefusalReason::NotBuildable->value]++;
            }
        }

        if ($requests === []) {
            return SubmitOutcome::nothingUsable($refusals);
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

        $batch = $this->record($providerBatchId, $this->requestedModels($requests));

        $this->events->submitted($batch);

        return SubmitOutcome::submitted($batch, $refusals);
    }

    /**
     * Revisions waiting to be generated, oldest first.
     *
     * What counts as waiting is the `Revision` model's to say —
     * `cv:batch-resume` reports the answer to the same question, and the two
     * must not be able to drift. Oldest first is this caller's: the order
     * requests go out in is no part of what is owed.
     *
     * @return list<Revision>
     */
    private function pending(): array
    {
        /** @var list<Revision> */
        return Revision::query()
            ->waiting()
            ->oldest('id')
            ->get()
            ->all();
    }

    /**
     * How many waiting revisions the selection above leaves behind.
     */
    private function behindADiscardedPosting(): int
    {
        return Revision::query()->pendingBehindADiscardedPosting()->count();
    }

    /**
     * The model each revision's request asks for, keyed by revision.
     *
     * The purpose chooses the model, so this is a configuration lookup rather
     * than anything the request carries: an `LlmRequest` deliberately names no
     * model, so that no caller can pin one by accident.
     *
     * @param  array<int, BatchRequest>  $requests
     * @return array<int, string>
     */
    private function requestedModels(array $requests): array
    {
        $models = [];

        foreach ($requests as $revisionId => $request) {
            $models[$revisionId] = $this->models->for($request->request->purpose);
        }

        return $models;
    }

    /**
     * @param  array<int, string>  $requestedModels  The model each revision's
     *                                               request asked for, keyed
     *                                               by revision.
     */
    private function record(string $providerBatchId, array $requestedModels): LlmBatch
    {
        return $this->database->transaction(function () use ($providerBatchId, $requestedModels): LlmBatch {
            $batch = LlmBatch::create([
                'provider_batch_id' => $providerBatchId,
                'state' => LlmBatchState::Submitted,
                'submitted_at' => CarbonImmutable::now(),
                'request_count' => count($requestedModels),
            ]);

            foreach ($requestedModels as $revisionId => $requestedModel) {
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
                    ->update([
                        'status' => RevisionStatus::Submitted,
                        // What this submission will be priced from. Written
                        // again here because the revision has been waiting
                        // since it was queued, and what would generate it now
                        // is what has just been sent.
                        'model' => $requestedModel,
                    ]);

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
