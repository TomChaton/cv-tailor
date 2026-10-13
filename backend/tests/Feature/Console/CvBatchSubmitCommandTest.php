<?php

namespace Tests\Feature\Console;

use App\Batching\BatchRequestBuilder;
use App\Batching\SubmitterHalt;
use App\Llm\LlmFailureKind;
use App\Llm\Testing\FakeLlmClient;
use App\Models\Revision;
use App\Revisions\RevisionStatus;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\StubRequestBuilder;
use Tests\TestCase;

class CvBatchSubmitCommandTest extends TestCase
{
    use RefreshDatabase;

    private StubRequestBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new StubRequestBuilder;
        $this->instance(BatchRequestBuilder::class, $this->builder);
    }

    public function test_it_reports_what_it_submitted(): void
    {
        Revision::factory()->count(2)->create();

        $this->artisan('cv:batch-submit')
            ->expectsOutputToContain('2 revisions submitted as batch 1')
            ->assertSuccessful();

        $this->assertDatabaseCount('llm_batch_revision', 2);
    }

    public function test_it_reports_a_single_revision_as_one(): void
    {
        Revision::factory()->create();

        $this->artisan('cv:batch-submit')
            ->expectsOutputToContain('1 revision submitted as batch 1')
            ->assertSuccessful();
    }

    public function test_it_succeeds_with_nothing_pending(): void
    {
        $this->artisan('cv:batch-submit')
            ->expectsOutputToContain('Nothing pending.')
            ->assertSuccessful();
    }

    /**
     * Non-zero, so that someone running this by hand after fixing their CV
     * sees that it is still not fixed.
     */
    public function test_it_fails_when_no_revision_can_be_built(): void
    {
        $revision = Revision::factory()->create();
        $this->builder->refusing($revision->id);

        $this->artisan('cv:batch-submit')
            ->expectsOutputToContain('cv:lint')
            ->assertFailed();

        $this->assertSame(RevisionStatus::Pending, $revision->fresh()?->status);
    }

    public function test_it_fails_when_the_provider_will_not_take_the_batch(): void
    {
        Revision::factory()->create();

        $this->app->make(FakeLlmClient::class)->willRefuseTheBatch(LlmFailureKind::ServerError);

        $this->artisan('cv:batch-submit')
            ->expectsOutputToContain('still pending')
            ->assertFailed();

        $this->assertDatabaseCount('llm_batches', 0);
    }

    public function test_a_halted_submitter_says_so_and_names_the_way_back(): void
    {
        Revision::factory()->create();

        $halt = $this->app->make(SubmitterHalt::class);
        $halt->recordFullyInvalid(3);
        $halt->recordFullyInvalid(4);

        $this->assertSame(Command::FAILURE, Artisan::call('cv:batch-submit'));

        $output = Artisan::output();

        $this->assertStringContainsString('The submitter is halted', $output);
        $this->assertStringContainsString('batch 3, batch 4', $output);
        $this->assertStringContainsString('cv:batch-resume', $output);

        $this->assertDatabaseCount('llm_batches', 0);
    }

    /**
     * In this process rather than in a subprocess, because the scheduler sends
     * a scheduled command's output to /dev/null and this application's log is
     * its processes' standard error.
     */
    public function test_it_is_scheduled_every_five_minutes_in_process_and_never_overlaps(): void
    {
        $event = $this->scheduled('cv:batch-submit');

        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_the_scheduled_run_submits_what_is_pending(): void
    {
        Revision::factory()->create();

        $this->scheduled('cv:batch-submit')->run($this->app);

        $this->assertDatabaseCount('llm_batches', 1);
    }

    /**
     * The alarm is the `batch.submitter_halted` error that halted it, not a
     * failed task every five minutes afterwards.
     */
    public function test_the_scheduled_run_reports_no_failure_when_the_submitter_is_halted(): void
    {
        Revision::factory()->create();

        $halt = $this->app->make(SubmitterHalt::class);
        $halt->recordFullyInvalid(3);
        $halt->recordFullyInvalid(4);

        $event = $this->scheduled('cv:batch-submit');
        $event->run($this->app);

        $this->assertSame(0, $event->exitCode);
        $this->assertDatabaseCount('llm_batches', 0);
    }

    private function scheduled(string $name): CallbackEvent
    {
        $events = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn ($event): bool => $event->description === $name,
        ));

        $this->assertCount(1, $events);
        $this->assertInstanceOf(CallbackEvent::class, $events[0]);

        return $events[0];
    }
}
