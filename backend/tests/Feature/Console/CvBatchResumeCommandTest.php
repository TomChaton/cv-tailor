<?php

namespace Tests\Feature\Console;

use App\Batching\SubmitterHalt;
use App\Models\Revision;
use App\Observability\EventLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecordingEventLogger;
use Tests\TestCase;

class CvBatchResumeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_clears_the_halt_and_says_what_is_waiting(): void
    {
        $log = new RecordingEventLogger;
        $this->instance(EventLogger::class, $log);

        Revision::factory()->count(2)->create();

        $halt = $this->app->make(SubmitterHalt::class);
        $halt->recordFullyInvalid(3);
        $halt->recordFullyInvalid(4);

        $this->artisan('cv:batch-resume')
            ->expectsOutputToContain('The submitter is running. 2 pending revisions are waiting.')
            ->assertSuccessful();

        $this->assertFalse($halt->isHalted());

        $line = $log->first('batch.submitter_resumed');

        $this->assertNotNull($line);
        $this->assertSame([3, 4], $line['context']['llm_batch_ids']);
        $this->assertSame(2, $line['context']['released']);
    }

    public function test_it_says_so_when_the_submitter_was_already_running(): void
    {
        $log = new RecordingEventLogger;
        $this->instance(EventLogger::class, $log);

        $this->artisan('cv:batch-resume')
            ->expectsOutputToContain('The submitter is running.')
            ->assertSuccessful();

        $this->assertFalse($log->has('batch.submitter_resumed'));
    }
}
