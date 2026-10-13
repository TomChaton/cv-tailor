<?php

namespace Tests\Feature\Batching;

use App\Batching\ApplyResult;
use App\Batching\LlmBatchState;
use App\Batching\SubmissionOutcome;
use App\Llm\BatchResult;
use App\Llm\LlmFailureKind;
use App\Llm\LlmResponse;
use App\Llm\TokenUsage;
use App\Models\LlmBatch;
use App\Models\LlmBatchRevision;
use App\Models\Revision;
use App\Observability\EventLogger;
use App\Revisions\RevisionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecordingEventLogger;
use Tests\TestCase;

class ApplyResultTest extends TestCase
{
    use RefreshDatabase;

    private RecordingEventLogger $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = new RecordingEventLogger;
        $this->instance(EventLogger::class, $this->log);

        config(['cv.batches.attempt_limit' => 3]);
    }

    public function test_a_succeeded_result_is_stored_and_the_revision_is_generated(): void
    {
        [$batch, $revision] = $this->submitted();

        $this->apply()->handle($batch, BatchResult::succeeded((string) $revision->id, $this->response()));

        $stored = $revision->fresh();

        $this->assertNotNull($stored);
        $this->assertSame(RevisionStatus::Generated, $stored->status);
        $this->assertSame(['headline' => 'Backend Engineer'], $stored->output_json);
        $this->assertSame(0, $stored->attempts);
        $this->assertSame(SubmissionOutcome::Succeeded, $this->submission($batch, $revision)->outcome);

        $line = $this->log->first('revision.result_stored');

        $this->assertNotNull($line);
        $this->assertSame($revision->id, $line['context']['revision_id']);
        $this->assertSame($batch->id, $line['context']['llm_batch_id']);
    }

    /**
     * A response that reached the token ceiling comes back cut off. Nothing is
     * stored, and the attempt is spent.
     */
    public function test_an_unusable_response_spends_an_attempt(): void
    {
        [$batch, $revision] = $this->submitted();

        $this->apply()->handle(
            $batch,
            BatchResult::succeeded((string) $revision->id, $this->response('{"headline":"Backend Eng')),
        );

        $stored = $revision->fresh();

        $this->assertNotNull($stored);
        $this->assertSame(RevisionStatus::Pending, $stored->status);
        $this->assertSame(1, $stored->attempts);
        $this->assertNull($stored->output_json);
    }

    public function test_a_server_side_error_returns_the_revision_to_pending_having_spent_an_attempt(): void
    {
        [$batch, $revision] = $this->submitted();

        $this->apply()->handle(
            $batch,
            BatchResult::errored((string) $revision->id, LlmFailureKind::ServerError),
        );

        $stored = $revision->fresh();

        $this->assertNotNull($stored);
        $this->assertSame(RevisionStatus::Pending, $stored->status);
        $this->assertSame(1, $stored->attempts);
        $this->assertNull($stored->error);
        $this->assertSame(SubmissionOutcome::Errored, $this->submission($batch, $revision)->outcome);
        $this->assertTrue($this->log->has('revision.result_retried'));
    }

    public function test_an_expired_result_returns_the_revision_to_pending_having_spent_an_attempt(): void
    {
        [$batch, $revision] = $this->submitted();

        $this->apply()->handle($batch, BatchResult::expired((string) $revision->id));

        $stored = $revision->fresh();

        $this->assertNotNull($stored);
        $this->assertSame(RevisionStatus::Pending, $stored->status);
        $this->assertSame(1, $stored->attempts);
        $this->assertSame(SubmissionOutcome::Expired, $this->submission($batch, $revision)->outcome);
    }

    public function test_a_canceled_result_returns_the_revision_to_pending_having_spent_an_attempt(): void
    {
        [$batch, $revision] = $this->submitted();

        $this->apply()->handle($batch, BatchResult::canceled((string) $revision->id));

        $stored = $revision->fresh();

        $this->assertNotNull($stored);
        $this->assertSame(RevisionStatus::Pending, $stored->status);
        $this->assertSame(1, $stored->attempts);
        $this->assertSame(SubmissionOutcome::Canceled, $this->submission($batch, $revision)->outcome);
    }

    /**
     * The request we built was refused, so the revision is not at fault: it
     * costs no attempt, and `failed` would be both terminal and undeserved.
     */
    public function test_a_malformed_request_leaves_the_revision_pending_with_its_attempts_untouched(): void
    {
        [$batch, $revision] = $this->submitted(attempts: 2);

        $this->apply()->handle(
            $batch,
            BatchResult::errored((string) $revision->id, LlmFailureKind::BadRequest),
        );

        $stored = $revision->fresh();

        $this->assertNotNull($stored);
        $this->assertSame(RevisionStatus::Pending, $stored->status);
        $this->assertSame(2, $stored->attempts);
        $this->assertNull($stored->error);

        $line = $this->log->first('batch.request_invalid');

        $this->assertNotNull($line);
        $this->assertSame('warning', $line['level']);
        $this->assertSame($revision->id, $line['context']['revision_id']);
        $this->assertSame($batch->id, $line['context']['llm_batch_id']);
    }

    /**
     * A revision caught by one bad batch after another is never driven to
     * `failed` by it, however many times it goes out.
     */
    public function test_a_malformed_request_can_never_exhaust_the_attempts(): void
    {
        config(['cv.batches.attempt_limit' => 1]);

        [$batch, $revision] = $this->submitted();

        $this->apply()->handle(
            $batch,
            BatchResult::errored((string) $revision->id, LlmFailureKind::BadRequest),
        );

        $this->assertSame(RevisionStatus::Pending, $revision->fresh()?->status);
    }

    public function test_the_last_attempt_fails_the_revision_and_records_what_the_owner_reads(): void
    {
        config(['cv.batches.attempt_limit' => 2]);

        [$batch, $revision] = $this->submitted(attempts: 1);

        $this->apply()->handle($batch, BatchResult::expired((string) $revision->id));

        $stored = $revision->fresh();

        $this->assertNotNull($stored);
        $this->assertSame(RevisionStatus::Failed, $stored->status);
        $this->assertSame(2, $stored->attempts);
        $this->assertSame('The model did not answer in time, after 2 attempts.', $stored->error);

        $line = $this->log->first('revision.result_failed');

        $this->assertNotNull($line);
        $this->assertSame('warning', $line['level']);
        $this->assertSame(2, $line['context']['attempts']);
    }

    /**
     * The case the guard exists for: a revision went out in one batch, that
     * batch expired, the revision went out again, and then the first batch's
     * results arrived after all.
     */
    public function test_a_result_never_overwrites_a_revision_that_has_been_resubmitted(): void
    {
        [$expired, $revision] = $this->submitted();

        // The first batch expired and its result was applied: the revision
        // went back to pending and the submission row records what happened.
        $this->apply()->handle($expired, BatchResult::expired((string) $revision->id));

        // It went out again, in a batch of its own.
        $resubmitted = LlmBatch::factory()->create();
        LlmBatchRevision::create([
            'llm_batch_id' => $resubmitted->id,
            'revision_id' => $revision->id,
            'outcome' => SubmissionOutcome::Awaiting,
        ]);
        Revision::query()->whereKey($revision->id)->update(['status' => RevisionStatus::Submitted]);

        // And now the first batch's output turns up.
        $this->apply()->handle($expired, BatchResult::succeeded((string) $revision->id, $this->response()));

        $stored = $revision->fresh();

        $this->assertNotNull($stored);
        $this->assertSame(RevisionStatus::Submitted, $stored->status);
        $this->assertNull($stored->output_json);
        $this->assertSame(SubmissionOutcome::Expired, $this->submission($expired, $revision)->outcome);
        $this->assertSame(SubmissionOutcome::Awaiting, $this->submission($resubmitted, $revision)->outcome);

        $line = $this->log->first('revision.result_rejected');

        $this->assertNotNull($line);
        $this->assertSame('warning', $line['level']);
        $this->assertSame($revision->id, $line['context']['revision_id']);
        $this->assertSame($expired->id, $line['context']['llm_batch_id']);
        $this->assertSame('the result has already been applied', $line['context']['reason']);
    }

    public function test_a_result_is_dropped_when_the_revision_is_no_longer_waiting_for_one(): void
    {
        [$batch, $revision] = $this->submitted();

        $revision->fill(['status' => RevisionStatus::Validated])->save();

        $this->apply()->handle($batch, BatchResult::succeeded((string) $revision->id, $this->response()));

        $this->assertNull($revision->fresh()?->output_json);
        $this->assertSame(RevisionStatus::Validated, $revision->fresh()?->status);
        // The outcome is a fact about the submission whatever the revision has
        // since become, so the row is resolved and its batch can close.
        $this->assertSame(SubmissionOutcome::Succeeded, $this->submission($batch, $revision)->outcome);
        $this->assertSame('the revision is not waiting for a result', $this->rejection());
    }

    public function test_a_result_for_a_revision_this_batch_did_not_carry_is_dropped(): void
    {
        $batch = LlmBatch::factory()->create();
        $revision = Revision::factory()->status(RevisionStatus::Submitted)->create();

        $this->apply()->handle($batch, BatchResult::succeeded((string) $revision->id, $this->response()));

        $this->assertNull($revision->fresh()?->output_json);
        $this->assertSame('this batch carries no submission for the revision', $this->rejection());
    }

    public function test_a_custom_id_that_names_no_revision_is_dropped(): void
    {
        $batch = LlmBatch::factory()->create();

        $this->apply()->handle($batch, BatchResult::expired('not-a-revision'));

        $line = $this->log->first('revision.result_rejected');

        $this->assertNotNull($line);
        $this->assertNull($line['context']['revision_id']);
        $this->assertSame('the custom ID names no revision', $line['context']['reason']);
    }

    /**
     * The reason on the first dropped result.
     */
    private function rejection(): ?string
    {
        $line = $this->log->first('revision.result_rejected');

        $this->assertNotNull($line);
        $reason = $line['context']['reason'];

        return is_string($reason) ? $reason : null;
    }

    /**
     * @return array{LlmBatch, Revision}
     */
    private function submitted(int $attempts = 0): array
    {
        $batch = LlmBatch::factory()->inState(LlmBatchState::Ended)->create();
        $revision = Revision::factory()
            ->status(RevisionStatus::Submitted)
            ->create(['attempts' => $attempts]);

        LlmBatchRevision::create([
            'llm_batch_id' => $batch->id,
            'revision_id' => $revision->id,
            'outcome' => SubmissionOutcome::Awaiting,
        ]);

        return [$batch, $revision];
    }

    private function submission(LlmBatch $batch, Revision $revision): LlmBatchRevision
    {
        return LlmBatchRevision::query()
            ->where('llm_batch_id', $batch->id)
            ->where('revision_id', $revision->id)
            ->sole();
    }

    private function response(string $text = '{"headline":"Backend Engineer"}'): LlmResponse
    {
        return new LlmResponse(
            model: 'test-model',
            text: $text,
            stopReason: 'end_turn',
            usage: new TokenUsage(
                inputTokens: 100,
                outputTokens: 50,
                cacheWriteTokens: 0,
                cacheReadTokens: 0,
            ),
            costMicros: 1000,
        );
    }

    private function apply(): ApplyResult
    {
        return $this->app->make(ApplyResult::class);
    }
}
