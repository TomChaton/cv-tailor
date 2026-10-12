<?php

namespace App\Observability;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * Remembers when each long-running process last reported.
 *
 * The store is the cache rather than a table of its own: the value has to
 * outlive the process that wrote it and be readable from another container,
 * and the cache table is already part of Laravel's own schema. Clearing the
 * cache resets the report, which is the cost of owning no schema.
 */
class HeartbeatStore
{
    public function __construct(private readonly Repository $cache) {}

    public function lastReportedAt(string $process): ?CarbonImmutable
    {
        $value = $this->cache->get($this->key($process));

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }

    public function record(string $process, CarbonImmutable $at): void
    {
        // Stored without expiry: an entry that aged out would be indis-
        // tinguishable from a process that has never reported at all.
        $this->cache->forever($this->key($process), $at->toIso8601String());
    }

    private function key(string $process): string
    {
        return "cv-tailor:heartbeat:{$process}";
    }
}
