<?php

namespace App\Llm;

/**
 * Where a submitted batch has got to.
 */
final readonly class BatchState
{
    public function __construct(
        public string $providerBatchId,
        public bool $ended,
        public BatchCounts $counts,
    ) {}
}
