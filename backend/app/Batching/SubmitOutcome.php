<?php

namespace App\Batching;

use App\Models\LlmBatch;

/**
 * What one run of the submitter did.
 *
 * `refused` counts revisions the request builder would not build, which is
 * what a master CV that no longer lints looks like from here. They are left
 * `pending` and the next run picks them up.
 */
final readonly class SubmitOutcome
{
    /**
     * @param  list<int>  $haltedBy  The batches that halted the submitter, when
     *                               it is halted.
     */
    private function __construct(
        public ?int $llmBatchId,
        public ?string $providerBatchId,
        public int $requestCount,
        public int $refused,
        public array $haltedBy = [],
        public bool $halted = false,
    ) {}

    /**
     * @param  list<int>  $llmBatchIds
     */
    public static function halted(array $llmBatchIds): self
    {
        return new self(null, null, 0, 0, $llmBatchIds, halted: true);
    }

    public static function nothingPending(): self
    {
        return new self(null, null, 0, 0);
    }

    public static function nothingUsable(int $refused): self
    {
        return new self(null, null, 0, $refused);
    }

    public static function submitted(LlmBatch $batch, int $refused): self
    {
        return new self($batch->id, $batch->provider_batch_id, $batch->request_count, $refused);
    }
}
