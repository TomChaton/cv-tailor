<?php

namespace Tests\Feature\Observability;

use App\Observability\HeartbeatStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WorkerHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_worker_loop_emits_the_heartbeat_with_its_named_context_keys(): void
    {
        $log = Log::spy();

        event(new Looping('database', 'default'));

        $log->shouldHaveReceived('log')->once()->withArgs(
            function (string $level, string $message, array $context): bool {
                return $message === 'worker.heartbeat'
                    && $context['event'] === 'worker.heartbeat'
                    && $context['queue'] === 'default'
                    && is_int($context['pending_jobs'])
                    && is_int($context['failed_jobs']);
            }
        );
    }

    public function test_the_loop_records_the_heartbeat_where_cv_status_can_read_it(): void
    {
        event(new Looping('database', 'default'));

        $this->assertNotNull(app(HeartbeatStore::class)->lastReportedAt('worker'));
    }
}
