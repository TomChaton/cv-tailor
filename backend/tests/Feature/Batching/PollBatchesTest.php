<?php

namespace Tests\Feature\Batching;

use App\Batching\LlmBatchState;
use App\Batching\PollBatches;
use App\Batching\SubmissionOutcome;
use App\Jobs\ValidateGeneratedRevision;
use App\Llm\Anthropic\AnthropicLlmClient;
use App\Llm\LlmClient;
use App\Llm\LlmFailureKind;
use App\Llm\Testing\FakeLlmClient;
use App\Models\LlmBatch;
use App\Models\LlmBatchRevision;
use App\Models\Revision;
use App\Observability\EventLogger;
use App\Revisions\RevisionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingEventLogger;
use Tests\TestCase;

class PollBatchesTest extends TestCase
{
    use RefreshDatabase;

    private RecordingEventLogger $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = new RecordingEventLogger;
        $this->instance(EventLogger::class, $this->log);

        config(['cv.batches.stale_after_minutes' => 360]);

        // The chain beyond a stored result belongs to validation and is
        // tested there. These tests stop at the hand-off.
        Queue::fake([ValidateGeneratedRevision::class]);
    }

    /**
     * As for the submitter: a run with nothing open must not need credentials,
     * and the SDK client refuses to be built in the test environment.
     */
    public function test_a_run_with_no_open_batches_resolves_no_provider_client(): void
    {
        $this->instance(LlmClient::class, $this->app->make(AnthropicLlmClient::class));

        $this->assertSame(0, $this->poll()->handle()->open);
        $this->assertSame([], $this->log->events());
    }

    public function test_a_batch_the_provider_is_still_working_on_is_left_alone(): void
    {
        [$batch] = $this->submitted();

        $this->fake()->willHoldBatchOpen();

        $outcome = $this->poll()->handle();

        $this->assertSame(1, $outcome->open);
        $this->assertSame(0, $outcome->ended);
        $this->assertSame(0, $outcome->drained);
        $polled = $batch->fresh();

        $this->assertNotNull($polled);
        $this->assertSame(LlmBatchState::Submitted, $polled->state);
        $this->assertNull($polled->ended_at);
        $this->assertFalse($this->log->has('batch.ended'));
    }

    /**
     * The provider reporting that it has finished and us having taken the
     * results are two different facts, and each gets its own line.
     */
    public function test_an_ended_batch_is_recorded_as_ended_and_then_collected(): void
    {
        [$batch, $revisions] = $this->submitted();

        $this->fake()->willSucceed((string) $revisions[0]->id, 'selection');

        $outcome = $this->poll()->handle();

        $this->assertSame(1, $outcome->ended);
        $this->assertSame(1, $outcome->drained);

        $polled = $batch->fresh();

        $this->assertNotNull($polled);
        $this->assertSame(LlmBatchState::Collected, $polled->state);
        $this->assertNotNull($polled->ended_at);
        $this->assertSame(RevisionStatus::Generated, $revisions[0]->fresh()?->status);

        $ended = $this->log->first('batch.ended');

        $this->assertNotNull($ended);
        $this->assertSame($batch->id, $ended['context']['llm_batch_id']);
        $this->assertTrue($this->log->has('batch.collected'));
    }

    /**
     * Leaving an ended batch uncollected is the exact risk the poller exists
     * to avoid, so it drains every one it finds rather than one per cycle.
     */
    public function test_it_drains_every_ended_batch_in_one_run(): void
    {
        [$first, $firstRevisions] = $this->submitted();
        [$second, $secondRevisions] = $this->submitted();

        $this->fake()
            ->willSucceed((string) $firstRevisions[0]->id, 'selection')
            ->willSucceed((string) $secondRevisions[0]->id, 'selection');

        $outcome = $this->poll()->handle();

        $this->assertSame(2, $outcome->open);
        $this->assertSame(2, $outcome->drained);
        $this->assertSame(LlmBatchState::Collected, $first->fresh()?->state);
        $this->assertSame(LlmBatchState::Collected, $second->fresh()?->state);
        $this->assertSame(RevisionStatus::Generated, $firstRevisions[0]->fresh()?->status);
        $this->assertSame(RevisionStatus::Generated, $secondRevisions[0]->fresh()?->status);
    }

    public function test_a_collected_batch_is_never_polled_again(): void
    {
        LlmBatch::factory()->inState(LlmBatchState::Collected)->create();
        LlmBatch::factory()->inState(LlmBatchState::Expired)->create();
        LlmBatch::factory()->inState(LlmBatchState::Failed)->create();

        $outcome = $this->poll()->handle();

        $this->assertSame(0, $outcome->open);
    }

    /**
     * An uncollected batch has to be impossible to miss: its results expire,
     * and the work is then paid for and gone.
     */
    public function test_a_batch_open_past_the_configured_age_warns(): void
    {
        config(['cv.batches.stale_after_minutes' => 60]);
        $this->travelTo(CarbonImmutable::parse('2026-01-01 12:00:00'));

        [$batch] = $this->submitted();
        $batch->update(['submitted_at' => CarbonImmutable::now()->subMinutes(90)]);

        $this->fake()->willHoldBatchOpen();

        $outcome = $this->poll()->handle();

        $this->assertSame(1, $outcome->stale);

        $line = $this->log->first('batch.stale');

        $this->assertNotNull($line);
        $this->assertSame('warning', $line['level']);
        $this->assertSame($batch->id, $line['context']['llm_batch_id']);
        $this->assertSame(90, $line['context']['age_minutes']);
        $this->assertSame('submitted', $line['context']['state']);
    }

    public function test_a_batch_inside_the_configured_age_says_nothing(): void
    {
        config(['cv.batches.stale_after_minutes' => 60]);
        $this->travelTo(CarbonImmutable::parse('2026-01-01 12:00:00'));

        [$batch] = $this->submitted();
        $batch->update(['submitted_at' => CarbonImmutable::now()->subMinutes(30)]);

        $this->fake()->willHoldBatchOpen();

        $this->assertSame(0, $this->poll()->handle()->stale);
        $this->assertFalse($this->log->has('batch.stale'));
    }

    /**
     * One batch the provider will not answer about must not stop the others
     * being collected in the same run.
     */
    public function test_a_batch_the_provider_will_not_answer_about_does_not_stop_the_run(): void
    {
        $unreachable = LlmBatch::factory()->create(['provider_batch_id' => 'msgbatch_gone']);
        [$batch, $revisions] = $this->submitted();

        $this->fake()
            ->willFailPollOf('msgbatch_gone', LlmFailureKind::ServerError)
            ->willSucceed((string) $revisions[0]->id, 'selection');

        $outcome = $this->poll()->handle();

        $this->assertSame(1, $outcome->unreachable);
        $this->assertSame(1, $outcome->drained);
        $this->assertSame(LlmBatchState::Submitted, $unreachable->fresh()?->state);
        $this->assertSame(LlmBatchState::Collected, $batch->fresh()?->state);
    }

    /**
     * A submitted batch carrying one revision that is awaiting its result.
     *
     * @return array{LlmBatch, list<Revision>}
     */
    private function submitted(): array
    {
        $batch = LlmBatch::factory()->create();
        $revision = Revision::factory()->status(RevisionStatus::Submitted)->create();

        LlmBatchRevision::create([
            'llm_batch_id' => $batch->id,
            'revision_id' => $revision->id,
            'outcome' => SubmissionOutcome::Awaiting,
        ]);

        return [$batch, [$revision]];
    }

    private function poll(): PollBatches
    {
        return $this->app->make(PollBatches::class);
    }

    private function fake(): FakeLlmClient
    {
        return $this->app->make(FakeLlmClient::class);
    }
}
