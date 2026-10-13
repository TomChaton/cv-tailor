<?php

namespace App\Jobs;

use App\Batching\ApplyResult;
use App\Llm\BatchResult;
use App\Models\LlmBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One batch result, applied in the worker.
 *
 * One queued job per result rather than one for the batch: a result that
 * cannot be stored is then one failed job on the queue rather than a
 * collection that stopped halfway through, and the rest of the batch lands.
 */
class ApplyBatchResult implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly LlmBatch $batch,
        public readonly BatchResult $result,
    ) {}

    public function handle(ApplyResult $apply): void
    {
        $apply->handle($this->batch, $this->result);
    }
}
