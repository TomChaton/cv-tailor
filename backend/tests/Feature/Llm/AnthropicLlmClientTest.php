<?php

namespace Tests\Feature\Llm;

use Anthropic\Client;
use App\Llm\Anthropic\AnthropicLlmClient;
use App\Llm\Anthropic\AnthropicRequestFactory;
use App\Llm\BatchOutcome;
use App\Llm\BatchRequest;
use App\Llm\BatchResult;
use App\Llm\CostCalculator;
use App\Llm\CostRates;
use App\Llm\LlmEvents;
use App\Llm\LlmFailure;
use App\Llm\LlmFailureKind;
use App\Llm\LlmPurpose;
use App\Llm\LlmRequest;
use App\Llm\ModelRegistry;
use App\Llm\SystemBlock;
use App\Observability\EventLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Psr\Log\LogLevel;
use Tests\Support\StubTransporter;
use Tests\TestCase;

class AnthropicLlmClientTest extends TestCase
{
    private const MODEL = 'claude-opus-5';

    private function client(StubTransporter $transporter): AnthropicLlmClient
    {
        $registry = new ModelRegistry([
            'generation' => self::MODEL,
            'validation' => 'claude-haiku-4-5',
        ]);

        return new AnthropicLlmClient(
            // A real SDK client over a canned transporter: the mapping, the
            // SDK's own deserialisation and the error translation are all
            // exercised, and no socket is opened. `maxRetries: 0` keeps a
            // failing status from being retried behind the test's back.
            new Client(
                apiKey: 'test-key',
                requestOptions: ['transporter' => $transporter, 'maxRetries' => 0],
            ),
            new AnthropicRequestFactory($registry),
            new CostCalculator(
                [self::MODEL => new CostRates(
                    input: 5_000_000,
                    output: 25_000_000,
                    cacheWrite: 10_000_000,
                    cacheRead: 500_000,
                )],
                batchPercent: 50,
            ),
            new LlmEvents($this->app->make(EventLogger::class)),
        );
    }

    private function request(): LlmRequest
    {
        return new LlmRequest(
            purpose: LlmPurpose::Generation,
            system: [SystemBlock::cacheable('The master CV.')],
            prompt: 'The job description.',
            maxTokens: 4096,
        );
    }

    private function message(string $text = 'the answer'): string
    {
        return json_encode([
            'id' => 'msg_01',
            'type' => 'message',
            'role' => 'assistant',
            'model' => self::MODEL,
            'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
                ['type' => 'text', 'text' => $text, 'citations' => null],
            ],
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'container' => null,
            'diagnostics' => null,
            'stop_details' => null,
            'usage' => [
                'input_tokens' => 1_000_000,
                'output_tokens' => 1_000_000,
                'cache_creation_input_tokens' => 1_000_000,
                'cache_read_input_tokens' => 1_000_000,
                'cache_creation' => null,
                'inference_geo' => null,
                'output_tokens_details' => null,
                'server_tool_use' => null,
                'service_tier' => null,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function batch(string $status, array $counts = []): string
    {
        return json_encode([
            'id' => 'msgbatch_01',
            'type' => 'message_batch',
            'archived_at' => null,
            'cancel_initiated_at' => null,
            'created_at' => '2026-01-01T00:00:00Z',
            'ended_at' => $status === 'ended' ? '2026-01-01T00:30:00Z' : null,
            'expires_at' => '2026-01-02T00:00:00Z',
            'processing_status' => $status,
            'results_url' => $status === 'ended' ? 'https://api.anthropic.com/results' : null,
            'request_counts' => [
                'processing' => $counts['processing'] ?? 0,
                'succeeded' => $counts['succeeded'] ?? 0,
                'errored' => $counts['errored'] ?? 0,
                'canceled' => $counts['canceled'] ?? 0,
                'expired' => $counts['expired'] ?? 0,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, BatchResult>  $results
     */
    private function resultFor(array $results, string $customId): BatchResult
    {
        $result = $results[$customId] ?? null;

        $this->assertInstanceOf(BatchResult::class, $result);

        return $result;
    }

    public function test_a_live_call_reports_usage_cache_tokens_apart_and_a_cost_in_micros(): void
    {
        $transporter = (new StubTransporter)->json(200, $this->message());

        $response = $this->client($transporter)->complete($this->request());

        $this->assertSame(self::MODEL, $response->model);
        $this->assertSame('the answer', $response->text);
        $this->assertSame('end_turn', $response->stopReason);
        $this->assertSame(1_000_000, $response->usage->inputTokens);
        $this->assertSame(1_000_000, $response->usage->outputTokens);
        $this->assertSame(1_000_000, $response->usage->cacheWriteTokens);
        $this->assertSame(1_000_000, $response->usage->cacheReadTokens);
        $this->assertSame(40_500_000, $response->costMicros);
    }

    public function test_a_live_call_sends_the_cache_breakpoint_with_the_one_hour_lifetime(): void
    {
        $transporter = (new StubTransporter)->json(200, $this->message());

        $this->client($transporter)->complete($this->request());

        $body = $transporter->lastBody();

        $this->assertSame('/v1/messages', $transporter->lastRequest()['path']);
        $this->assertSame(
            ['type' => 'ephemeral', 'ttl' => '1h'],
            Arr::get($body, 'system.0.cache_control'),
        );
    }

    public function test_a_live_call_logs_one_request_line(): void
    {
        $transporter = (new StubTransporter)->json(200, $this->message());
        $log = Log::spy();

        $this->client($transporter)->complete($this->request());

        $log->shouldHaveReceived('log')->once()->withArgs(
            fn (string $level, string $message, array $context): bool => $level === LogLevel::INFO
                && $message === 'llm.request'
                && $context['event'] === 'llm.request'
                && $context['model'] === self::MODEL
                && $context['purpose'] === 'generation'
                && $context['outcome'] === 'end_turn'
                && $context['cost_micros'] === 40_500_000
                && $context['input_tokens'] === 1_000_000
                && $context['output_tokens'] === 1_000_000
                && $context['cache_write_tokens'] === 1_000_000
                && $context['cache_read_tokens'] === 1_000_000,
        );
    }

    public function test_no_log_line_carries_prompt_or_completion_text(): void
    {
        $transporter = (new StubTransporter)->json(200, $this->message('a sentence from the CV'));
        $log = Log::spy();

        $this->client($transporter)->complete($this->request());

        $log->shouldHaveReceived('log')->withArgs(
            function (string $level, string $message, array $context): bool {
                $encoded = json_encode($context, JSON_THROW_ON_ERROR);

                return ! str_contains($encoded, 'a sentence from the CV')
                    && ! str_contains($encoded, 'The master CV.')
                    && ! str_contains($encoded, 'The job description.')
                    && ! str_contains($encoded, 'test-key');
            },
        );
    }

    public function test_submitting_a_batch_returns_the_provider_batch_id_and_logs_it(): void
    {
        $transporter = (new StubTransporter)->json(200, $this->batch('in_progress', ['processing' => 2]));
        $log = Log::spy();

        $batchId = $this->client($transporter)->submitBatch([
            new BatchRequest('41', $this->request()),
            new BatchRequest('42', $this->request()),
        ]);

        $this->assertSame('msgbatch_01', $batchId);
        $this->assertSame('/v1/messages/batches', $transporter->lastRequest()['path']);

        $body = $transporter->lastBody();
        $this->assertSame('41', Arr::get($body, 'requests.0.custom_id'));
        $this->assertSame('42', Arr::get($body, 'requests.1.custom_id'));
        // Live-only parameters are never sent on a batch request.
        $this->assertNull(Arr::get($body, 'requests.0.params.stream'));

        $log->shouldHaveReceived('log')->once()->withArgs(
            fn (string $level, string $message, array $context): bool => $message === 'llm.batch_submitted'
                && $context['provider_batch_id'] === 'msgbatch_01'
                && $context['request_count'] === 2
                && $context['model'] === self::MODEL,
        );
    }

    public function test_polling_reports_whether_a_batch_has_ended_and_its_per_outcome_counts(): void
    {
        $transporter = (new StubTransporter)
            ->json(200, $this->batch('in_progress', ['processing' => 3]))
            ->json(200, $this->batch('ended', ['succeeded' => 1, 'errored' => 1, 'expired' => 1]));

        $client = $this->client($transporter);

        $open = $client->pollBatch('msgbatch_01');
        $this->assertFalse($open->ended);
        $this->assertSame(3, $open->counts->processing);

        $ended = $client->pollBatch('msgbatch_01');
        $this->assertTrue($ended->ended);
        $this->assertSame(3, $ended->counts->total());
        $this->assertSame(1, $ended->counts->succeeded);
        $this->assertSame(1, $ended->counts->errored);
        $this->assertSame(0, $ended->counts->canceled);
        $this->assertSame(1, $ended->counts->expired);
    }

    public function test_results_stream_back_keyed_by_custom_id_in_whatever_order_they_arrive(): void
    {
        $lines = implode("\n", [
            json_encode(['custom_id' => '43', 'result' => ['type' => 'expired']], JSON_THROW_ON_ERROR),
            json_encode(['custom_id' => '41', 'result' => ['type' => 'succeeded', 'message' => json_decode($this->message(), true)]], JSON_THROW_ON_ERROR),
            json_encode(['custom_id' => '42', 'result' => ['type' => 'errored', 'error' => ['type' => 'error', 'request_id' => 'req_1', 'error' => ['type' => 'invalid_request_error', 'message' => 'nope']]]], JSON_THROW_ON_ERROR),
            json_encode(['custom_id' => '44', 'result' => ['type' => 'canceled']], JSON_THROW_ON_ERROR),
        ])."\n";

        $transporter = (new StubTransporter)->jsonLines($lines);

        $results = $this->client($transporter)->fetchResults('msgbatch_01');

        $this->assertInstanceOf(\Generator::class, $results);

        $order = [];
        /** @var array<string, BatchResult> $byCustomId */
        $byCustomId = [];

        foreach ($results as $result) {
            $order[] = $result->customId;
            $byCustomId[$result->customId] = $result;
        }

        // The provider returns results in any order, so a caller keys by the
        // custom ID rather than by position.
        $this->assertSame(['43', '41', '42', '44'], $order);

        $succeeded = $this->resultFor($byCustomId, '41');
        $this->assertSame(BatchOutcome::Succeeded, $succeeded->outcome);
        // Half the live cost: the batch API bills at the configured percentage.
        $this->assertSame(20_250_000, $succeeded->response?->costMicros);

        $errored = $this->resultFor($byCustomId, '42');
        $this->assertSame(BatchOutcome::Errored, $errored->outcome);
        $this->assertTrue($errored->failure?->isMalformedRequest());

        $this->assertSame(BatchOutcome::Expired, $this->resultFor($byCustomId, '43')->outcome);
        $this->assertSame(BatchOutcome::Canceled, $this->resultFor($byCustomId, '44')->outcome);
    }

    public function test_a_provider_exception_becomes_the_applications_own_failure_and_a_warning(): void
    {
        $transporter = (new StubTransporter)->json(
            429,
            '{"type":"error","request_id":"req_stub_1","error":{"type":"rate_limit_error","message":"slow down"}}',
        );
        $log = Log::spy();

        try {
            $this->client($transporter)->complete($this->request());
            $this->fail('The client swallowed a provider failure.');
        } catch (LlmFailure $failure) {
            $this->assertSame(LlmFailureKind::RateLimit, $failure->kind);
            $this->assertSame('req_stub_1', $failure->providerRequestId);
            $this->assertSame(429, $failure->status);
            $this->assertStringNotContainsString('slow down', $failure->getMessage());
        }

        $log->shouldHaveReceived('log')->once()->withArgs(
            fn (string $level, string $message, array $context): bool => $level === LogLevel::WARNING
                && $message === 'llm.failed'
                && $context['model'] === self::MODEL
                && $context['failure'] === 'rate_limit'
                && $context['provider_request_id'] === 'req_stub_1',
        );
    }

    public function test_a_malformed_request_is_told_apart_from_a_provider_side_failure(): void
    {
        $bad = (new StubTransporter)->json(
            400,
            '{"type":"error","error":{"type":"invalid_request_error","message":"nope"}}',
        );
        $down = (new StubTransporter)->json(
            500,
            '{"type":"error","error":{"type":"api_error","message":"nope"}}',
        );

        try {
            $this->client($bad)->complete($this->request());
            $this->fail('A bad request went unreported.');
        } catch (LlmFailure $failure) {
            $this->assertTrue($failure->kind->isMalformedRequest());
        }

        try {
            $this->client($down)->complete($this->request());
            $this->fail('A server error went unreported.');
        } catch (LlmFailure $failure) {
            $this->assertFalse($failure->kind->isMalformedRequest());
            $this->assertSame(LlmFailureKind::ServerError, $failure->kind);
        }
    }

    public function test_a_failure_is_reported_and_never_retried_here(): void
    {
        $transporter = (new StubTransporter)->json(
            500,
            '{"type":"error","error":{"type":"api_error","message":"nope"}}',
        );

        try {
            $this->client($transporter)->complete($this->request());
        } catch (LlmFailure) {
            // Asserted below: the revision's attempts counter owns retrying.
        }

        $this->assertSame(1, $transporter->sentCount());
    }
}
