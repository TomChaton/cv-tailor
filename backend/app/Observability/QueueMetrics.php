<?php

namespace App\Observability;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\Failed\CountableFailedJobProvider;

/**
 * Counts of Laravel's own queue jobs — not of captured postings.
 */
class QueueMetrics
{
    public function __construct(
        private readonly QueueFactory $queue,
        private readonly CountableFailedJobProvider $failed,
    ) {}

    public function pending(string $queue = 'default'): int
    {
        return $this->queue->connection()->size($queue);
    }

    public function failed(): int
    {
        return $this->failed->count();
    }
}
