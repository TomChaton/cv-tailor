<?php

namespace Tests\Feature\Generation;

use App\Generation\GenerationOutputIsUnusable;
use App\Generation\StoreGenerationOutput;
use App\Llm\LlmResponse;
use App\Llm\TokenUsage;
use App\Models\Revision;
use App\Observability\EventLogger;
use App\Revisions\RevisionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class StoreGenerationOutputTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_the_selection_the_usage_and_the_model(): void
    {
        $revision = Revision::factory()->create();

        $this->store()->handle($revision, $this->response());

        $stored = $revision->fresh();

        $this->assertNotNull($stored);
        $this->assertSame(['headline' => 'Platform Engineer'], $stored->output_json);
        $this->assertSame('test-model', $stored->model);
        $this->assertSame(RevisionStatus::Generated, $stored->status);
        $this->assertSame(412, $stored->input_tokens);
        $this->assertSame(286, $stored->output_tokens);
        $this->assertSame(3140, $stored->cache_write_tokens);
        $this->assertSame(17, $stored->cache_read_tokens);
        $this->assertSame(2500, $stored->cost_micros);
    }

    /**
     * The prompt version is recorded when the request is built, which is when
     * it becomes true of the revision. Storing a result is too late to learn
     * which prompt produced it.
     */
    public function test_it_leaves_the_prompt_version_alone(): void
    {
        $revision = Revision::factory()->create(['prompt_version' => 'something-older']);

        $this->store()->handle($revision, $this->response());

        $this->assertSame('something-older', $revision->fresh()?->prompt_version);
    }

    public function test_it_logs_what_it_stored_as_sizes(): void
    {
        $revision = Revision::factory()->create();
        $response = $this->response();

        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with('generation.stored', Mockery::on(function (array $context) use ($revision): bool {
                $this->assertSame($revision->id, $context['revision_id']);
                $this->assertSame('test-model', $context['model']);
                $this->assertSame($revision->prompt_version, $context['prompt_version']);
                $this->assertGreaterThan(0, $context['output_chars']);
                $this->assertSame(2500, $context['cost_micros']);
                $this->assertSame(412, $context['input_tokens']);

                return true;
            }));

        $this->instance(EventLogger::class, $logger);

        $this->store()->handle($revision, $response);
    }

    /**
     * Structured outputs make this unlikely rather than impossible: a response
     * that reaches the token ceiling comes back cut off mid-document.
     */
    public function test_it_refuses_a_response_that_is_not_a_selection(): void
    {
        $revision = Revision::factory()->create();

        $this->expectException(GenerationOutputIsUnusable::class);

        try {
            $this->store()->handle($revision, $this->response('{"headline":"Platf'));
        } finally {
            $this->assertNull($revision->fresh()?->output_json);
            $this->assertSame(RevisionStatus::Pending, $revision->fresh()?->status);
        }
    }

    public function test_it_logs_an_unusable_response_as_a_warning(): void
    {
        $revision = Revision::factory()->create();

        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with('generation.unusable', Mockery::on(function (array $context) use ($revision): bool {
                $this->assertSame($revision->id, $context['revision_id']);
                $this->assertSame('max_tokens', $context['stop_reason']);
                $this->assertSame(18, $context['output_chars']);

                return true;
            }));

        $this->instance(EventLogger::class, $logger);

        try {
            $this->store()->handle($revision, $this->response('{"headline":"Platf', 'max_tokens'));
        } catch (GenerationOutputIsUnusable) {
            // The log line is what this test is about.
        }
    }

    private function response(
        string $text = '{"headline":"Platform Engineer"}',
        string $stopReason = 'end_turn',
    ): LlmResponse {
        return new LlmResponse(
            model: 'test-model',
            text: $text,
            stopReason: $stopReason,
            usage: new TokenUsage(
                inputTokens: 412,
                outputTokens: 286,
                cacheWriteTokens: 3140,
                cacheReadTokens: 17,
            ),
            costMicros: 2500,
        );
    }

    private function store(): StoreGenerationOutput
    {
        return $this->app->make(StoreGenerationOutput::class);
    }
}
