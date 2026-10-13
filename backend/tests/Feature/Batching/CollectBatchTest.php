<?php

namespace Tests\Feature\Batching;

use App\Batching\CollectBatch;
use App\Batching\LlmBatchState;
use App\Batching\SubmissionOutcome;
use App\Batching\SubmitterHalt;
use App\Jobs\ApplyBatchResult;
use App\Jobs\ValidateGeneratedRevision;
use App\Llm\LlmFailureKind;
use App\Llm\Testing\FakeLlmClient;
use App\Models\LlmBatch;
use App\Models\LlmBatchRevision;
use App\Models\Revision;
use App\Observability\EventLogger;
use App\Revisions\RevisionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingEventLogger;
use Tests\TestCase;

class CollectBatchTest extends TestCase
{
    use RefreshDatabase;

    private RecordingEventLogger $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = new RecordingEventLogger;
        $this->instance(EventLogger::class, $this->log);

        // The chain beyond a stored result belongs to validation and is
        // tested there. These tests stop at the hand-off.
        Queue::fake([ValidateGeneratedRevision::class]);
    }

    public function test_it_dispatches_one_job_per_result_and_leaves_the_batch_open_until_they_are_applied(): void
    {
        Queue::fake();

        [$batch, $revisions] = $this->ended(2);

        $this->fake()
            ->willSucceed((string) $revisions[0]->id, 'selection')
            ->willSucceed((string) $revisions[1]->id, 'selection');

        $closed = $this->collect()->handle($batch);

        Queue::assertPushed(ApplyBatchResult::class, 2);

        $this->assertFalse($closed);
        $this->assertSame(LlmBatchState::Ended, $batch->fresh()?->state);
        $this->assertFalse($this->log->has('batch.collected'));
    }

    /**
     * The provider returns a batch's results in any order, so they are matched
     * by the custom ID each one carries. Position-indexing works perfectly in
     * a batch of one and misattributes silently in a batch of three.
     */
    public function test_results_arriving_out_of_order_land_on_the_right_revisions(): void
    {
        [$batch, $revisions] = $this->ended(3);

        $this->fake()
            ->willExpire((string) $revisions[2]->id)
            ->willSucceed((string) $revisions[0]->id, 'selection')
            ->willError((string) $revisions[1]->id, LlmFailureKind::ServerError);

        $this->collect()->handle($batch);

        $this->assertSame(RevisionStatus::Generated, $revisions[0]->fresh()?->status);
        $this->assertSame(RevisionStatus::Pending, $revisions[1]->fresh()?->status);
        $this->assertSame(RevisionStatus::Pending, $revisions[2]->fresh()?->status);

        $this->assertSame(SubmissionOutcome::Succeeded, $this->outcomeOf($batch, $revisions[0]));
        $this->assertSame(SubmissionOutcome::Errored, $this->outcomeOf($batch, $revisions[1]));
        $this->assertSame(SubmissionOutcome::Expired, $this->outcomeOf($batch, $revisions[2]));
    }

    public function test_a_batch_whose_results_are_all_applied_is_collected_with_a_count_per_outcome(): void
    {
        [$batch, $revisions] = $this->ended(2);

        $this->fake()
            ->willSucceed((string) $revisions[0]->id, 'selection')
            ->willExpire((string) $revisions[1]->id);

        $this->assertTrue($this->collect()->handle($batch));

        $this->assertSame(LlmBatchState::Collected, $batch->fresh()?->state);

        $line = $this->log->first('batch.collected');

        $this->assertNotNull($line);
        $this->assertSame($batch->id, $line['context']['llm_batch_id']);
        $this->assertSame(0, $line['context']['awaiting']);
        $this->assertSame(1, $line['context']['succeeded']);
        $this->assertSame(1, $line['context']['expired']);
        $this->assertSame(0, $line['context']['errored']);
        $this->assertSame(0, $line['context']['canceled']);
    }

    /**
     * A poller that stopped halfway through a batch re-runs without applying
     * anything twice and without dropping anything. The outcome on each
     * submission row is what makes that possible.
     */
    public function test_collecting_is_resumable(): void
    {
        [$batch, $revisions] = $this->ended(2);

        $this->fake()
            ->willSucceed((string) $revisions[0]->id, 'selection')
            ->willSucceed((string) $revisions[1]->id, 'selection');

        // The first run dispatches both and closes nothing: a result is not
        // applied until the worker gets to it.
        Queue::fake();
        $this->assertFalse($this->collect()->handle($batch));
        Queue::assertPushed(ApplyBatchResult::class, 2);

        // Only one of the two was applied before the run stopped.
        $this->apply($batch, $revisions[0]);

        // The second run picks up what is left and nothing else.
        Queue::fake();
        $this->assertFalse($this->collect()->handle($batch));
        Queue::assertPushed(ApplyBatchResult::class, 1);

        $this->apply($batch, $revisions[1]);

        // And closes once every row is resolved, with nothing dispatched
        // twice.
        Queue::fake();
        $this->assertTrue($this->collect()->handle($batch));
        Queue::assertNothingPushed();

        $this->assertSame(LlmBatchState::Collected, $batch->fresh()?->state);
    }

    public function test_a_custom_id_that_resolves_to_no_submission_is_logged_and_skipped(): void
    {
        [$batch, $revisions] = $this->ended(1);

        $this->fake()
            ->willSucceed('404', 'selection')
            ->willSucceed((string) $revisions[0]->id, 'selection');

        $this->assertTrue($this->collect()->handle($batch));

        $line = $this->log->first('revision.result_rejected');

        $this->assertNotNull($line);
        $this->assertSame(404, $line['context']['revision_id']);
        $this->assertSame('this batch carries no submission for the custom ID', $line['context']['reason']);
        $this->assertSame(RevisionStatus::Generated, $revisions[0]->fresh()?->status);
    }

    /**
     * A batch every one of whose requests expired has not been collected in
     * any sense worth the word, and a reader of `cv:status` needs to see that
     * without counting rows.
     */
    public function test_a_batch_whose_every_request_expired_is_expired(): void
    {
        [$batch, $revisions] = $this->ended(1);

        $this->fake()->willExpire((string) $revisions[0]->id);

        $this->collect()->handle($batch);

        $this->assertSame(LlmBatchState::Expired, $batch->fresh()?->state);
    }

    public function test_a_batch_whose_every_request_errored_has_failed(): void
    {
        [$batch, $revisions] = $this->ended(1);

        $this->fake()->willError((string) $revisions[0]->id, LlmFailureKind::ServerError);

        $this->collect()->handle($batch);

        $this->assertSame(LlmBatchState::Failed, $batch->fresh()?->state);
    }

    /**
     * One such batch is more likely a single pathological job description than
     * a broken schema, and a batch of one is the normal case here.
     */
    public function test_one_fully_invalid_batch_does_not_halt_the_submitter(): void
    {
        [$batch, $revisions] = $this->ended(1);

        $this->fake()->willError((string) $revisions[0]->id, LlmFailureKind::BadRequest);

        $this->collect()->handle($batch);

        $this->assertFalse($this->halt()->isHalted());
        $this->assertFalse($this->log->has('batch.submitter_halted'));
    }

    public function test_two_consecutive_fully_invalid_batches_halt_the_submitter(): void
    {
        $first = $this->collectFullyInvalid();
        $second = $this->collectFullyInvalid();

        $this->assertTrue($this->halt()->isHalted());
        $this->assertSame([$first, $second], $this->halt()->haltedBy());

        $line = $this->log->first('batch.submitter_halted');

        $this->assertNotNull($line);
        $this->assertSame('error', $line['level']);
        $this->assertSame([$first, $second], $line['context']['llm_batch_ids']);
    }

    /**
     * Two *consecutive*: a batch the provider was willing to serve says the
     * requests being built are not the problem.
     */
    public function test_a_batch_with_a_usable_result_breaks_the_streak(): void
    {
        $this->collectFullyInvalid();

        [$batch, $revisions] = $this->ended(1);
        $this->fake()->willSucceed((string) $revisions[0]->id, 'selection');
        $this->collect()->handle($batch);

        $this->collectFullyInvalid();

        $this->assertFalse($this->halt()->isHalted());
    }

    /**
     * A batch is collected over as many runs as it takes, and must not be able
     * to trip the halt twice on its own.
     */
    public function test_one_batch_collected_twice_does_not_halt_the_submitter(): void
    {
        [$batch, $revisions] = $this->ended(1);

        $this->fake()->willError((string) $revisions[0]->id, LlmFailureKind::BadRequest);

        Queue::fake();
        $this->collect()->handle($batch);
        $this->collect()->handle($batch);

        $this->assertFalse($this->halt()->isHalted());
    }

    private function collectFullyInvalid(): int
    {
        [$batch, $revisions] = $this->ended(1);

        $this->fake()->willError((string) $revisions[0]->id, LlmFailureKind::BadRequest);
        $this->collect()->handle($batch);

        return $batch->id;
    }

    /**
     * An ended batch carrying `$count` submitted revisions, each awaiting its
     * result.
     *
     * @return array{LlmBatch, list<Revision>}
     */
    private function ended(int $count): array
    {
        $batch = LlmBatch::factory()->inState(LlmBatchState::Ended)->create(['request_count' => $count]);

        // Each batch answers with its own results rather than with every
        // result the test has queued so far.
        $this->fake()->forgetResults();

        $revisions = [];

        for ($i = 0; $i < $count; $i++) {
            $revision = Revision::factory()->status(RevisionStatus::Submitted)->create();

            LlmBatchRevision::create([
                'llm_batch_id' => $batch->id,
                'revision_id' => $revision->id,
                'outcome' => SubmissionOutcome::Awaiting,
            ]);

            $revisions[] = $revision;
        }

        return [$batch, $revisions];
    }

    private function apply(LlmBatch $batch, Revision $revision): void
    {
        LlmBatchRevision::query()
            ->where('llm_batch_id', $batch->id)
            ->where('revision_id', $revision->id)
            ->update(['outcome' => SubmissionOutcome::Succeeded]);
    }

    private function outcomeOf(LlmBatch $batch, Revision $revision): SubmissionOutcome
    {
        return LlmBatchRevision::query()
            ->where('llm_batch_id', $batch->id)
            ->where('revision_id', $revision->id)
            ->sole()
            ->outcome;
    }

    private function collect(): CollectBatch
    {
        return $this->app->make(CollectBatch::class);
    }

    private function halt(): SubmitterHalt
    {
        return $this->app->make(SubmitterHalt::class);
    }

    private function fake(): FakeLlmClient
    {
        return $this->app->make(FakeLlmClient::class);
    }
}
