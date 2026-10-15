<?php

namespace Tests\Feature\Console;

use App\Batching\SubmitterHalt;
use App\Models\Posting;
use App\Models\Revision;
use App\Observability\EventLogger;
use App\Postings\PostingStatus;
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

    /**
     * What is waiting has to be what the submitter would send. A posting
     * discarded between one run and the next leaves a `pending` revision the
     * submitter passes over, and counting it here would promise the owner work
     * that the next run declines.
     */
    public function test_a_revision_behind_a_discarded_posting_is_not_waiting(): void
    {
        $log = new RecordingEventLogger;
        $this->instance(EventLogger::class, $log);

        $discarded = Posting::factory()->status(PostingStatus::Discarded)->create();
        Revision::factory()->create(['posting_id' => $discarded->id]);

        $halt = $this->app->make(SubmitterHalt::class);
        $halt->recordFullyInvalid(5);
        $halt->recordFullyInvalid(6);

        $this->artisan('cv:batch-resume')
            ->expectsOutputToContain('The submitter is running. 0 pending revisions are waiting.')
            ->assertSuccessful();

        $line = $log->first('batch.submitter_resumed');

        $this->assertNotNull($line);
        $this->assertSame(0, $line['context']['released']);
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
