<?php

namespace App\Batching;

/**
 * What one run of the poller did.
 *
 * `unreachable` counts batches the provider would not answer about. They stay
 * open and the next run asks again, so the count is a symptom rather than a
 * verdict.
 */
final readonly class PollOutcome
{
    public function __construct(
        public int $open = 0,
        public int $ended = 0,
        public int $drained = 0,
        public int $stale = 0,
        public int $unreachable = 0,
    ) {}
}
