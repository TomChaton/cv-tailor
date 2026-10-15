<?php

namespace App\Batching;

use App\Models\LlmBatch;

/**
 * What one run of the submitter did.
 *
 * `refusals` counts the revisions it found waiting and did not send, one count
 * per reason, because the reasons ask different things of the owner. A
 * revision the request builder would not build is left `pending` and the next
 * run picks it up; a revision behind a discarded posting is left `pending` and
 * nothing will ever pick it up, which is the point.
 */
final readonly class SubmitOutcome
{
    /**
     * @param  array<string, int>  $refusals  A count per refusal reason, every
     *                                        reason reported.
     * @param  list<int>  $haltedBy  The batches that halted the submitter, when
     *                               it is halted.
     */
    private function __construct(
        public ?int $llmBatchId,
        public ?string $providerBatchId,
        public int $requestCount,
        public array $refusals,
        public array $haltedBy = [],
        public bool $halted = false,
    ) {}

    /**
     * @param  list<int>  $llmBatchIds
     */
    public static function halted(array $llmBatchIds): self
    {
        return new self(null, null, 0, RefusalReason::none(), $llmBatchIds, halted: true);
    }

    public static function nothingPending(): self
    {
        return new self(null, null, 0, RefusalReason::none());
    }

    /**
     * @param  array<string, int>  $refusals
     */
    public static function nothingUsable(array $refusals): self
    {
        return new self(null, null, 0, $refusals);
    }

    /**
     * @param  array<string, int>  $refusals
     */
    public static function submitted(LlmBatch $batch, array $refusals): self
    {
        return new self($batch->id, $batch->provider_batch_id, $batch->request_count, $refusals);
    }

    public function refused(): int
    {
        return array_sum($this->refusals);
    }

    public function refusedBecause(RefusalReason $reason): int
    {
        return $this->refusals[$reason->value] ?? 0;
    }
}
