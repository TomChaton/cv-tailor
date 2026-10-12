<?php

namespace Tests\Feature\Observability;

use App\Observability\HeartbeatStore;
use App\Observability\Listeners\RecordSchedulerHeartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SchedulerHeartbeatTest extends TestCase
{
    public function test_it_emits_the_heartbeat_with_its_named_context_key(): void
    {
        $log = Log::spy();

        app()->call(app(RecordSchedulerHeartbeat::class));

        $log->shouldHaveReceived('log')->once()->withArgs(
            function (string $level, string $message, array $context): bool {
                return $message === 'scheduler.heartbeat'
                    && $context['event'] === 'scheduler.heartbeat'
                    && is_int($context['due_tasks']);
            }
        );

        $this->assertNotNull(app(HeartbeatStore::class)->lastReportedAt('scheduler'));
    }

    public function test_the_heartbeat_is_itself_a_task_that_runs_every_minute(): void
    {
        $events = array_filter(
            app(Schedule::class)->events(),
            fn ($event): bool => $event->description === 'scheduler.heartbeat',
        );

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', array_values($events)[0]->expression);
    }
}
