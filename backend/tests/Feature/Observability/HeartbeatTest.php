<?php

namespace Tests\Feature\Observability;

use App\Observability\EventLogger;
use App\Observability\Heartbeat;
use App\Observability\HeartbeatStore;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class HeartbeatTest extends TestCase
{
    public function test_it_writes_at_most_one_line_a_clock_minute(): void
    {
        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('log')->twice();

        $heartbeat = new Heartbeat($this->store(), $logger);

        $this->travelTo(CarbonImmutable::parse('2026-01-01 12:00:00'));
        $this->assertTrue($heartbeat->beat('worker', 'worker.heartbeat'));

        $this->travelTo(CarbonImmutable::parse('2026-01-01 12:00:59'));
        $this->assertFalse($heartbeat->beat('worker', 'worker.heartbeat'));

        $this->travelTo(CarbonImmutable::parse('2026-01-01 12:01:00'));
        $this->assertTrue($heartbeat->beat('worker', 'worker.heartbeat'));
    }

    public function test_it_records_when_the_process_last_reported(): void
    {
        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('log');

        $store = $this->store();

        $this->assertNull($store->lastReportedAt('scheduler'));

        $this->travelTo(CarbonImmutable::parse('2026-01-01 12:00:00'));
        (new Heartbeat($store, $logger))->beat('scheduler', 'scheduler.heartbeat');

        $this->assertEquals(
            CarbonImmutable::parse('2026-01-01 12:00:00'),
            $store->lastReportedAt('scheduler'),
        );
    }

    public function test_processes_are_throttled_independently_of_each_other(): void
    {
        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('log')->twice();

        $heartbeat = new Heartbeat($this->store(), $logger);

        $this->travelTo(CarbonImmutable::parse('2026-01-01 12:00:00'));

        $this->assertTrue($heartbeat->beat('worker', 'worker.heartbeat'));
        $this->assertTrue($heartbeat->beat('scheduler', 'scheduler.heartbeat'));
    }

    private function store(): HeartbeatStore
    {
        return new HeartbeatStore(new Repository(new ArrayStore));
    }
}
