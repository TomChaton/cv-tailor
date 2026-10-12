<?php

namespace App\Observability\Status;

use App\Observability\HeartbeatStore;
use App\Observability\Listeners\RecordSchedulerHeartbeat;
use App\Observability\Listeners\RecordWorkerHeartbeat;

class ProcessesSection implements StatusSection
{
    /** @var list<string> */
    private const array PROCESSES = [
        RecordWorkerHeartbeat::PROCESS,
        RecordSchedulerHeartbeat::PROCESS,
    ];

    public function __construct(private readonly HeartbeatStore $store) {}

    public function heading(): string
    {
        return 'Processes';
    }

    public function rows(): array
    {
        $rows = [];

        foreach (self::PROCESSES as $process) {
            $last = $this->store->lastReportedAt($process);

            $rows[$process] = $last === null
                ? 'never reported'
                : $last->toIso8601String().' ('.$last->diffForHumans().')';
        }

        return $rows;
    }
}
