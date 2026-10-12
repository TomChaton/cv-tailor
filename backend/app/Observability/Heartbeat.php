<?php

namespace App\Observability;

use Carbon\CarbonImmutable;

/**
 * Emits a process's heartbeat, at most once a clock minute.
 */
class Heartbeat
{
    public function __construct(
        private readonly HeartbeatStore $store,
        private readonly EventLogger $log,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return bool Whether a line was written.
     */
    public function beat(string $process, string $event, array $context = []): bool
    {
        $now = CarbonImmutable::now();
        $last = $this->store->lastReportedAt($process);

        // Bucketed on the clock minute rather than on elapsed seconds: an
        // elapsed window skips a minute whenever the caller's own interval
        // jitters just under it, and a gap in a heartbeat reads as a stopped
        // process.
        if ($last !== null && $last->isSameMinute($now)) {
            return false;
        }

        $this->store->record($process, $now);
        $this->log->log($event, $context);

        return true;
    }
}
