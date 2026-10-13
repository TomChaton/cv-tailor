<?php

namespace Tests\Feature\Batching;

use App\Batching\SubmissionOutcome;
use App\Jobs\ApplyBatchResult;
use App\Llm\BatchOutcome;
use App\Llm\BatchResult;
use App\Llm\LlmResponse;
use App\Llm\TokenUsage;
use App\Models\LlmBatch;
use App\Models\LlmBatchRevision;
use App\Models\Revision;
use App\Revisions\RevisionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplyBatchResultJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The job crosses a queue, so it crosses a serialisation. A result that
     * cannot survive the trip would fail in the worker and nowhere else.
     */
    public function test_a_result_survives_the_queue(): void
    {
        $batch = LlmBatch::factory()->create();
        $job = new ApplyBatchResult($batch, BatchResult::succeeded('42', new LlmResponse(
            model: 'test-model',
            text: '{"headline":"Backend Engineer"}',
            stopReason: 'end_turn',
            usage: new TokenUsage(
                inputTokens: 100,
                outputTokens: 50,
                cacheWriteTokens: 0,
                cacheReadTokens: 0,
            ),
            costMicros: 1000,
        )));

        $restored = unserialize(serialize($job));

        $this->assertInstanceOf(ApplyBatchResult::class, $restored);
        $this->assertSame($batch->id, $restored->batch->id);
        $this->assertSame('42', $restored->result->customId);
        $this->assertSame(BatchOutcome::Succeeded, $restored->result->outcome);
        $this->assertSame('{"headline":"Backend Engineer"}', $restored->result->response?->text);
    }

    public function test_the_job_applies_the_result(): void
    {
        $batch = LlmBatch::factory()->create();
        $revision = Revision::factory()->status(RevisionStatus::Submitted)->create();

        LlmBatchRevision::create([
            'llm_batch_id' => $batch->id,
            'revision_id' => $revision->id,
            'outcome' => SubmissionOutcome::Awaiting,
        ]);

        $this->app->call([new ApplyBatchResult($batch, BatchResult::expired((string) $revision->id)), 'handle']);

        $stored = $revision->fresh();

        $this->assertNotNull($stored);
        $this->assertSame(RevisionStatus::Pending, $stored->status);
        $this->assertSame(1, $stored->attempts);
    }
}
