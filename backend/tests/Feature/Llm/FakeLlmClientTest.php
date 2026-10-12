<?php

namespace Tests\Feature\Llm;

use App\Llm\BatchOutcome;
use App\Llm\BatchRequest;
use App\Llm\BatchResult;
use App\Llm\CostCalculator;
use App\Llm\LlmEvents;
use App\Llm\LlmFailure;
use App\Llm\LlmFailureKind;
use App\Llm\LlmPurpose;
use App\Llm\LlmRequest;
use App\Llm\ModelRegistry;
use App\Llm\SystemBlock;
use App\Llm\Testing\FakeLlmClient;
use App\Llm\Testing\MissingFixture;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Psr\Log\LogLevel;
use Tests\TestCase;

class FakeLlmClientTest extends TestCase
{
    /**
     * The shared instance — the one the code under test is handed.
     */
    private function fake(): FakeLlmClient
    {
        return $this->app->make(FakeLlmClient::class);
    }

    /**
     * A fake of its own, for asserting on two independent batches in one test.
     */
    private function freshFake(): FakeLlmClient
    {
        return new FakeLlmClient(
            Config::string('cv.llm.fixture_path'),
            $this->app->make(CostCalculator::class),
            $this->app->make(ModelRegistry::class),
            $this->app->make(LlmEvents::class),
        );
    }

    private function request(LlmPurpose $purpose = LlmPurpose::Generation): LlmRequest
    {
        return new LlmRequest(
            purpose: $purpose,
            system: [SystemBlock::cacheable('The master CV.')],
            prompt: 'The job description.',
            maxTokens: 4096,
        );
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

    public function test_it_answers_a_live_call_from_a_recorded_response(): void
    {
        $response = $this->fake()->willAnswerWith('faithfulness-supported')
            ->complete($this->request(LlmPurpose::Validation));

        $this->assertSame('claude-haiku-4-5', $response->model);
        $this->assertStringContainsString('"supported":true', $response->text);
        $this->assertSame(190, $response->usage->inputTokens);
        $this->assertSame(24, $response->usage->outputTokens);
        $this->assertSame(310, $response->costMicros);
    }

    public function test_it_records_what_it_was_asked(): void
    {
        $fake = $this->fake()->willAnswerWith('selection');
        $request = $this->request();

        $fake->complete($request);

        $this->assertSame([$request], $fake->liveRequests());
    }

    public function test_it_records_a_submitted_batch_and_returns_a_batch_id(): void
    {
        $fake = $this->fake();
        $requests = [new BatchRequest('41', $this->request()), new BatchRequest('42', $this->request())];

        $batchId = $fake->submitBatch($requests);

        $this->assertSame([['batch_id' => $batchId, 'requests' => $requests]], $fake->submittedBatches());
    }

    public function test_every_result_type_is_reachable_including_a_malformed_request(): void
    {
        $fake = $this->fake()
            ->willSucceed('41', 'selection')
            ->willError('42', LlmFailureKind::BadRequest)
            ->willError('43', LlmFailureKind::ServerError)
            ->willCancel('44')
            ->willExpire('45');

        /** @var array<string, BatchResult> $results */
        $results = [];

        foreach ($fake->fetchResults('msgbatch_fake_1') as $result) {
            $results[$result->customId] = $result;
        }

        $succeeded = $this->resultFor($results, '41');
        $this->assertSame(BatchOutcome::Succeeded, $succeeded->outcome);
        // Half the live cost: the fake prices a batched response like the real
        // client does, from the same configured rates.
        $this->assertSame(20_305, $succeeded->response?->costMicros);

        $malformed = $this->resultFor($results, '42');
        $this->assertSame(BatchOutcome::Errored, $malformed->outcome);
        $this->assertTrue($malformed->failure?->isMalformedRequest());

        $providerSide = $this->resultFor($results, '43');
        $this->assertSame(BatchOutcome::Errored, $providerSide->outcome);
        $this->assertFalse($providerSide->failure?->isMalformedRequest());

        $this->assertSame(BatchOutcome::Canceled, $this->resultFor($results, '44')->outcome);
        $this->assertSame(BatchOutcome::Expired, $this->resultFor($results, '45')->outcome);
    }

    public function test_polling_reports_the_queued_results_and_can_be_held_open(): void
    {
        $ended = $this->freshFake()->willSucceed('41', 'selection')->willExpire('42')
            ->pollBatch('msgbatch_fake_1');

        $this->assertTrue($ended->ended);
        $this->assertSame(1, $ended->counts->succeeded);
        $this->assertSame(1, $ended->counts->expired);

        $open = $this->freshFake()->willSucceed('41', 'selection')->willHoldBatchOpen()
            ->pollBatch('msgbatch_fake_1');

        $this->assertFalse($open->ended);
        $this->assertSame(1, $open->counts->processing);
    }

    public function test_it_can_be_told_to_fail(): void
    {
        $this->expectException(LlmFailure::class);

        $this->fake()->willFailWith(LlmFailureKind::RateLimit)->complete($this->request());
    }

    public function test_it_emits_the_same_events_as_the_real_client(): void
    {
        $log = Log::spy();

        $fake = $this->fake()->willAnswerWith('selection')->willFailWith(LlmFailureKind::ServerError);

        $fake->complete($this->request());

        try {
            $fake->complete($this->request());
        } catch (LlmFailure) {
            // The log line is the point of the test.
        }

        $log->shouldHaveReceived('log')->withArgs(
            fn (string $level, string $message, array $context): bool => $level === LogLevel::INFO
                && $message === 'llm.request'
                && $context['model'] === 'claude-opus-5'
                && $context['purpose'] === 'generation'
                && $context['outcome'] === 'end_turn'
                && $context['cost_micros'] === 40_610
                && $context['cache_write_tokens'] === 3140
                && $context['cache_read_tokens'] === 0,
        );

        $log->shouldHaveReceived('log')->withArgs(
            fn (string $level, string $message, array $context): bool => $level === LogLevel::WARNING
                && $message === 'llm.failed'
                && $context['model'] === 'claude-opus-5'
                && $context['failure'] === 'server_error'
                && $context['provider_request_id'] === 'req_fake',
        );
    }

    public function test_a_recorded_response_that_is_not_there_raises(): void
    {
        $this->expectException(MissingFixture::class);

        $this->fake()->willAnswerWith('no-such-recording')->complete($this->request());
    }
}
