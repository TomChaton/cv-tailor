<?php

namespace Tests\Unit\Llm;

use App\Llm\CostCalculator;
use App\Llm\CostRates;
use App\Llm\LlmReply;
use App\Llm\TokenUsage;
use App\Llm\UnknownModel;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CostCalculatorTest extends TestCase
{
    private function calculator(): CostCalculator
    {
        return new CostCalculator(
            ['test-model' => new CostRates(
                input: 5_000_000,
                output: 25_000_000,
                cacheWrite: 10_000_000,
                cacheRead: 500_000,
            )],
            batchPercent: 50,
        );
    }

    public function test_it_prices_a_million_of_each_token_at_the_configured_rate(): void
    {
        $cost = $this->calculator()->micros(
            'test-model',
            new TokenUsage(
                inputTokens: 1_000_000,
                outputTokens: 0,
                cacheWriteTokens: 0,
                cacheReadTokens: 0,
            ),
            batched: false,
        );

        $this->assertSame(5_000_000, $cost);
    }

    public function test_cache_writes_and_cache_reads_are_priced_apart(): void
    {
        $calculator = $this->calculator();

        $usage = fn (int $write, int $read): TokenUsage => new TokenUsage(
            inputTokens: 0,
            outputTokens: 0,
            cacheWriteTokens: $write,
            cacheReadTokens: $read,
        );

        $this->assertSame(
            10_000_000,
            $calculator->micros('test-model', $usage(1_000_000, 0), batched: false),
        );
        $this->assertSame(
            500_000,
            $calculator->micros('test-model', $usage(0, 1_000_000), batched: false),
        );
    }

    public function test_a_batched_response_is_billed_at_the_configured_percentage(): void
    {
        $usage = new TokenUsage(
            inputTokens: 1_000_000,
            outputTokens: 1_000_000,
            cacheWriteTokens: 0,
            cacheReadTokens: 0,
        );

        $calculator = $this->calculator();

        $this->assertSame(30_000_000, $calculator->micros('test-model', $usage, batched: false));
        $this->assertSame(15_000_000, $calculator->micros('test-model', $usage, batched: true));
    }

    public function test_a_cost_is_an_integer_number_of_micros_and_never_rounds_down_to_nothing(): void
    {
        $cost = $this->calculator()->micros(
            'test-model',
            new TokenUsage(
                inputTokens: 1,
                outputTokens: 0,
                cacheWriteTokens: 0,
                cacheReadTokens: 0,
            ),
            batched: false,
        );

        $this->assertSame(5, $cost);
    }

    public function test_an_unconfigured_model_raises_rather_than_costing_nothing(): void
    {
        $this->expectException(UnknownModel::class);

        $this->calculator()->micros(
            'model-nobody-configured',
            new TokenUsage(1, 1, 0, 0),
            batched: false,
        );
    }

    /**
     * The name the provider answers with is a dated build of the alias that
     * was asked for, and nothing is configured under it. Pricing reads the
     * requested name, and the reply's own name travels on untouched.
     */
    public function test_a_reply_is_priced_from_the_requested_model_and_not_the_reported_one(): void
    {
        $response = $this->calculator()->price(
            'test-model',
            new LlmReply(
                reportedModel: 'test-model-20260101',
                text: 'the answer',
                stopReason: 'end_turn',
                usage: new TokenUsage(
                    inputTokens: 1_000_000,
                    outputTokens: 0,
                    cacheWriteTokens: 0,
                    cacheReadTokens: 0,
                ),
            ),
            batched: false,
        );

        $this->assertSame(5_000_000, $response->costMicros);
        $this->assertSame('test-model', $response->requestedModel);
        $this->assertSame('test-model-20260101', $response->reportedModel);
        $this->assertSame('the answer', $response->text);
        $this->assertSame('end_turn', $response->stopReason);
    }

    public function test_pricing_a_reply_against_an_unconfigured_model_raises_naming_that_model(): void
    {
        $reply = new LlmReply(
            reportedModel: 'test-model',
            text: 'the answer',
            stopReason: 'end_turn',
            usage: new TokenUsage(1, 1, 0, 0),
        );

        try {
            $this->calculator()->price('model-nobody-configured', $reply, batched: false);

            $this->fail('A model with no configured rate was priced anyway.');
        } catch (UnknownModel $raised) {
            // The requested name, because that is the one the owner can act
            // on: the reported one was never theirs to configure.
            $this->assertStringContainsString('model-nobody-configured', $raised->getMessage());
            $this->assertStringNotContainsString('reported', $raised->getMessage());
        }
    }

    public function test_it_says_whether_a_model_has_a_rate_at_all(): void
    {
        $calculator = $this->calculator();

        $this->assertTrue($calculator->hasRates('test-model'));
        $this->assertFalse($calculator->hasRates('test-model-20260101'));
    }

    public function test_it_reads_rates_from_configuration(): void
    {
        $calculator = CostCalculator::fromConfig([
            'test-model' => [
                'input' => 1_000_000,
                'output' => 2_000_000,
                'cache_write' => 3_000_000,
                'cache_read' => 4_000_000,
            ],
        ], batchPercent: 50);

        $this->assertSame(
            10_000_000,
            $calculator->micros(
                'test-model',
                new TokenUsage(1_000_000, 1_000_000, 1_000_000, 1_000_000),
                batched: false,
            ),
        );
    }

    public function test_a_rate_that_is_not_an_integer_number_of_micros_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CostCalculator::fromConfig([
            'test-model' => [
                'input' => 1.5,
                'output' => 1,
                'cache_write' => 1,
                'cache_read' => 1,
            ],
        ], batchPercent: 50);
    }
}
