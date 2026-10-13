<?php

namespace Tests\Feature\Batching;

use App\Batching\BatchRequestBuilder;
use App\Batching\LlmBatchState;
use App\Batching\SubmissionOutcome;
use App\Batching\SubmitBatch;
use App\Batching\SubmitterHalt;
use App\Generation\BuildGenerationRequest;
use App\Llm\Anthropic\AnthropicLlmClient;
use App\Llm\BatchRequest;
use App\Llm\LlmClient;
use App\Llm\LlmFailure;
use App\Llm\LlmFailureKind;
use App\Llm\Testing\FakeLlmClient;
use App\Models\LlmBatch;
use App\Models\LlmBatchRevision;
use App\Models\Revision;
use App\Observability\EventLogger;
use App\Revisions\RevisionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\RecordingEventLogger;
use Tests\Support\StubRequestBuilder;
use Tests\TestCase;

class SubmitBatchTest extends TestCase
{
    use RefreshDatabase;

    private RecordingEventLogger $log;

    private StubRequestBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = new RecordingEventLogger;
        $this->instance(EventLogger::class, $this->log);

        $this->builder = new StubRequestBuilder;
        $this->instance(BatchRequestBuilder::class, $this->builder);
    }

    /**
     * A batch of one is the normal case here and earns the full discount, so
     * there is no quorum to reach and no wait to serve out.
     */
    public function test_a_batch_of_one_submits(): void
    {
        $revision = Revision::factory()->create();

        $outcome = $this->submit()->handle();

        $this->assertSame(1, $outcome->requestCount);
        $this->assertSame(0, $outcome->refused);

        $batch = LlmBatch::query()->sole();

        $this->assertSame(LlmBatchState::Submitted, $batch->state);
        $this->assertSame(1, $batch->request_count);
        $this->assertNull($batch->ended_at);
        $this->assertSame($batch->id, $outcome->llmBatchId);
        $this->assertSame(RevisionStatus::Submitted, $revision->fresh()?->status);

        $submission = LlmBatchRevision::query()->sole();

        $this->assertSame($batch->id, $submission->llm_batch_id);
        $this->assertSame($revision->id, $submission->revision_id);
        $this->assertSame(SubmissionOutcome::Awaiting, $submission->outcome);
    }

    public function test_every_pending_revision_goes_out_in_one_batch_keyed_by_its_own_id(): void
    {
        $first = Revision::factory()->create();
        $second = Revision::factory()->create();
        $third = Revision::factory()->create();

        $this->submit()->handle();

        $submitted = $this->fake()->submittedBatches();

        $this->assertCount(1, $submitted);
        $this->assertSame(
            [(string) $first->id, (string) $second->id, (string) $third->id],
            array_map(
                static fn (BatchRequest $request): string => $request->customId,
                $submitted[0]['requests'],
            ),
        );
        $this->assertSame($submitted[0]['batch_id'], LlmBatch::query()->sole()->provider_batch_id);
    }

    public function test_it_logs_the_submission(): void
    {
        Revision::factory()->create();

        $this->submit()->handle();

        $batch = LlmBatch::query()->sole();
        $line = $this->log->first('batch.submitted');

        $this->assertNotNull($line);
        $this->assertSame($batch->id, $line['context']['llm_batch_id']);
        $this->assertSame($batch->provider_batch_id, $line['context']['provider_batch_id']);
        $this->assertSame(1, $line['context']['request_count']);
    }

    public function test_nothing_pending_submits_nothing_and_says_nothing(): void
    {
        Revision::factory()->status(RevisionStatus::Ready)->create();

        $outcome = $this->submit()->handle();

        $this->assertSame(0, $outcome->requestCount);
        $this->assertSame([], $this->fake()->submittedBatches());
        $this->assertDatabaseCount('llm_batches', 0);
        $this->assertSame([], $this->log->events());
    }

    /**
     * A revision the builder will not build stays `pending`, so the first run
     * after the master CV is fixed picks it up.
     */
    public function test_a_refused_revision_is_left_pending(): void
    {
        $refused = Revision::factory()->create();
        $built = Revision::factory()->create();

        $this->builder->refusing($refused->id);

        $outcome = $this->submit()->handle();

        $this->assertSame(1, $outcome->requestCount);
        $this->assertSame(1, $outcome->refused);
        $stored = $refused->fresh();

        $this->assertNotNull($stored);
        $this->assertSame(RevisionStatus::Pending, $stored->status);
        $this->assertSame(0, $stored->attempts);
        $this->assertSame(RevisionStatus::Submitted, $built->fresh()?->status);
    }

    public function test_nothing_is_submitted_when_no_revision_can_be_built(): void
    {
        $revision = Revision::factory()->create();

        $this->builder->refusing($revision->id);

        $outcome = $this->submit()->handle();

        $this->assertSame(0, $outcome->requestCount);
        $this->assertSame(1, $outcome->refused);
        $this->assertDatabaseCount('llm_batches', 0);
        $this->assertSame(RevisionStatus::Pending, $revision->fresh()?->status);
    }

    /**
     * The claim comes after the provider has taken the batch, so a provider
     * that refuses it leaves every revision where it was rather than at
     * `submitted` with no batch to collect it.
     */
    public function test_a_provider_failure_leaves_every_revision_pending(): void
    {
        $revision = Revision::factory()->create();

        $this->fake()->willRefuseTheBatch(LlmFailureKind::RateLimit);

        try {
            $this->submit()->handle();
            $this->fail('The failure should have been reported.');
        } catch (LlmFailure $failure) {
            $this->assertSame(LlmFailureKind::RateLimit, $failure->kind);
        }

        $this->assertDatabaseCount('llm_batches', 0);
        $this->assertSame(RevisionStatus::Pending, $revision->fresh()?->status);
    }

    public function test_a_halted_submitter_submits_nothing(): void
    {
        Revision::factory()->create();

        $halt = $this->app->make(SubmitterHalt::class);
        $halt->recordFullyInvalid(1);
        $halt->recordFullyInvalid(2);

        $outcome = $this->submit()->handle();

        $this->assertTrue($outcome->halted);
        $this->assertSame([1, 2], $outcome->haltedBy);
        $this->assertSame([], $this->fake()->submittedBatches());
        $this->assertDatabaseCount('llm_batches', 0);
    }

    /**
     * A scheduled run resolves the submitter to find out whether it has
     * anything to do, and a run with nothing to do must not need credentials.
     * The provider's SDK client refuses to be built in the test environment,
     * so a run that built one would raise here rather than pass.
     */
    public function test_a_run_with_nothing_pending_resolves_no_provider_client(): void
    {
        $this->instance(LlmClient::class, $this->app->make(AnthropicLlmClient::class));

        $outcome = $this->submit()->handle();

        $this->assertSame(0, $outcome->requestCount);
        $this->assertSame([], $this->log->events());
    }

    /**
     * The control for the test above: a run with work does reach the provider,
     * so the one before it is passing for the right reason.
     */
    public function test_a_run_with_work_reaches_the_provider(): void
    {
        Revision::factory()->create();

        $this->instance(LlmClient::class, $this->app->make(AnthropicLlmClient::class));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A test asked for a live LLM client.');

        $this->submit()->handle();
    }

    /**
     * The one assertion about the contract itself: generation builds the
     * requests the submitter sends.
     */
    public function test_the_request_builder_is_generations_own(): void
    {
        $this->app->forgetInstance(BatchRequestBuilder::class);

        $this->assertInstanceOf(
            BuildGenerationRequest::class,
            $this->app->make(BatchRequestBuilder::class),
        );
    }

    private function submit(): SubmitBatch
    {
        return $this->app->make(SubmitBatch::class);
    }

    private function fake(): FakeLlmClient
    {
        return $this->app->make(FakeLlmClient::class);
    }
}
