<?php

namespace Tests\Feature\Console;

use App\Batching\LlmBatchState;
use App\Batching\SubmissionOutcome;
use App\Batching\SubmitterHalt;
use App\Jobs\ValidateGeneratedRevision;
use App\Llm\Testing\FakeLlmClient;
use App\Models\LlmBatch;
use App\Models\LlmBatchRevision;
use App\Models\Revision;
use App\Revisions\RevisionStatus;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CvBatchPollCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The chain beyond a stored result belongs to validation and is
        // tested there. These tests stop at the hand-off.
        Queue::fake([ValidateGeneratedRevision::class]);
    }

    public function test_it_succeeds_with_no_open_batches(): void
    {
        $this->artisan('cv:batch-poll')
            ->expectsOutputToContain('No open batches.')
            ->assertSuccessful();
    }

    public function test_it_reports_what_it_collected(): void
    {
        $revision = $this->open();

        $this->app->make(FakeLlmClient::class)->willSucceed((string) $revision->id, 'selection');

        $this->artisan('cv:batch-poll')
            ->expectsOutputToContain('1 open: 1 ended, 1 collected.')
            ->assertSuccessful();

        $this->assertSame(RevisionStatus::Generated, $revision->fresh()?->status);
    }

    /**
     * The poller is never halted. Batches already in flight still need
     * collecting, and stopping both would turn a schema bug into work paid for
     * and thrown away.
     */
    public function test_it_collects_while_the_submitter_is_halted(): void
    {
        $revision = $this->open();

        $halt = $this->app->make(SubmitterHalt::class);
        $halt->recordFullyInvalid(1);
        $halt->recordFullyInvalid(2);

        $this->app->make(FakeLlmClient::class)->willSucceed((string) $revision->id, 'selection');

        $this->artisan('cv:batch-poll')->assertSuccessful();

        $this->assertSame(RevisionStatus::Generated, $revision->fresh()?->status);
        $this->assertSame(LlmBatchState::Collected, LlmBatch::query()->sole()->state);
        $this->assertTrue($halt->isHalted());
    }

    public function test_it_is_scheduled_every_five_minutes_in_process_and_never_overlaps(): void
    {
        $events = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn ($event): bool => $event->description === 'cv:batch-poll',
        ));

        $this->assertCount(1, $events);
        $this->assertInstanceOf(CallbackEvent::class, $events[0]);
        $this->assertSame('*/5 * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
    }

    public function test_the_scheduled_run_collects_what_has_ended(): void
    {
        $revision = $this->open();

        $this->app->make(FakeLlmClient::class)->willSucceed((string) $revision->id, 'selection');

        $this->scheduled()->run($this->app);

        $this->assertSame(LlmBatchState::Collected, LlmBatch::query()->sole()->state);
    }

    private function open(): Revision
    {
        $batch = LlmBatch::factory()->create();
        $revision = Revision::factory()->status(RevisionStatus::Submitted)->create();

        LlmBatchRevision::create([
            'llm_batch_id' => $batch->id,
            'revision_id' => $revision->id,
            'outcome' => SubmissionOutcome::Awaiting,
        ]);

        return $revision;
    }

    private function scheduled(): CallbackEvent
    {
        $events = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn ($event): bool => $event->description === 'cv:batch-poll',
        ));

        $this->assertInstanceOf(CallbackEvent::class, $events[0]);

        return $events[0];
    }
}
