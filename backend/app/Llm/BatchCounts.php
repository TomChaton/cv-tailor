<?php

namespace App\Llm;

/**
 * How a batch's requests are distributed across the outcomes.
 *
 * Every outcome is reported, including the ones nothing is in: an outcome
 * missing from a report cannot be told apart from one that has been renamed.
 */
final readonly class BatchCounts
{
    public function __construct(
        public int $processing = 0,
        public int $succeeded = 0,
        public int $errored = 0,
        public int $canceled = 0,
        public int $expired = 0,
    ) {}

    public function total(): int
    {
        return $this->processing
            + $this->succeeded
            + $this->errored
            + $this->canceled
            + $this->expired;
    }

    /**
     * @return array{
     *     processing: int,
     *     succeeded: int,
     *     errored: int,
     *     canceled: int,
     *     expired: int,
     * }
     */
    public function logContext(): array
    {
        return [
            'processing' => $this->processing,
            'succeeded' => $this->succeeded,
            'errored' => $this->errored,
            'canceled' => $this->canceled,
            'expired' => $this->expired,
        ];
    }
}
