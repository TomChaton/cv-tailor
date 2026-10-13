<?php

namespace App\Batching;

use App\Llm\LlmClient;
use App\Llm\LlmFailure;
use App\Models\LlmBatch;
use Carbon\CarbonImmutable;

/**
 * Asks the provider about every batch that still owes us results, and takes
 * the results of the ones that have finished.
 *
 * It drains every ended batch it finds in one run rather than one per cycle.
 * A batch that is submitted, paid for and never collected is the expensive
 * failure this exists to prevent: provider results expire, and the work is
 * then simply gone.
 *
 * The poller is never halted. Batches already in flight still need collecting,
 * and stopping the poller along with the submitter would turn a bug in the
 * request builder into work paid for and thrown away.
 */
class PollBatches
{
    public function __construct(
        private readonly LlmClient $client,
        private readonly CollectBatch $collect,
        private readonly BatchEvents $events,
        private readonly int $staleAfterMinutes,
    ) {}

    public function handle(): PollOutcome
    {
        /** @var list<LlmBatch> $open */
        $open = LlmBatch::query()->open()->get()->all();

        $ended = 0;
        $drained = 0;
        $stale = 0;
        $unreachable = 0;

        foreach ($open as $batch) {
            $stale += $this->warnIfStale($batch) ? 1 : 0;

            try {
                $state = $this->client->pollBatch($batch->provider_batch_id);

                if ($batch->state === LlmBatchState::Submitted && $state->ended) {
                    $batch->update([
                        'state' => LlmBatchState::Ended,
                        'ended_at' => CarbonImmutable::now(),
                    ]);

                    $this->events->ended($batch);
                    $ended++;
                }

                if ($batch->state !== LlmBatchState::Ended) {
                    continue;
                }

                $drained += $this->collect->handle($batch) ? 1 : 0;
            } catch (LlmFailure) {
                // The client has already written `llm.failed`. One batch the
                // provider will not answer about must not stop the others:
                // every ended batch still needs draining in this run.
                $unreachable++;
            }
        }

        return new PollOutcome(
            open: count($open),
            ended: $ended,
            drained: $drained,
            stale: $stale,
            unreachable: $unreachable,
        );
    }

    private function warnIfStale(LlmBatch $batch): bool
    {
        $minutes = (int) $batch->submitted_at->diffInMinutes(CarbonImmutable::now());

        if ($minutes < $this->staleAfterMinutes) {
            return false;
        }

        $this->events->stale($batch, $minutes);

        return true;
    }
}
