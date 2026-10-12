<?php

namespace App\Observability\Listeners;

use App\Observability\Heartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;

/**
 * Runs as a scheduled task itself, so the scheduler reports only while it is
 * actually running tasks.
 */
class RecordSchedulerHeartbeat
{
    public const string PROCESS = 'scheduler';

    public const string EVENT = 'scheduler.heartbeat';

    public function __construct(
        private readonly Heartbeat $heartbeat,
        private readonly Schedule $schedule,
        private readonly Application $app,
    ) {}

    public function __invoke(): void
    {
        $this->heartbeat->beat(self::PROCESS, self::EVENT, [
            // This heartbeat is itself a due task, so the count is never zero.
            'due_tasks' => count($this->schedule->dueEvents($this->app)),
        ]);
    }
}
