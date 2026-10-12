<?php

namespace App\Observability\Listeners;

use App\Observability\Heartbeat;
use App\Observability\QueueMetrics;
use Illuminate\Queue\Events\Looping;

/**
 * The queue worker loops whether or not there is work, so the heartbeat hangs
 * off that loop: an idle worker still reports, and a stopped one stops.
 */
class RecordWorkerHeartbeat
{
    public const string PROCESS = 'worker';

    public const string EVENT = 'worker.heartbeat';

    public function __construct(
        private readonly Heartbeat $heartbeat,
        private readonly QueueMetrics $metrics,
    ) {}

    public function handle(Looping $event): void
    {
        $queue = $event->queue !== '' ? $event->queue : 'default';

        $this->heartbeat->beat(self::PROCESS, self::EVENT, [
            'queue' => $queue,
            'pending_jobs' => $this->metrics->pending($queue),
            'failed_jobs' => $this->metrics->failed(),
        ]);
    }
}
