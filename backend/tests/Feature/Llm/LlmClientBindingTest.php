<?php

namespace Tests\Feature\Llm;

use Anthropic\Client;
use App\Extraction\PromptVersion as ExtractionPromptVersion;
use App\Generation\PromptVersion as GenerationPromptVersion;
use App\Llm\Anthropic\AnthropicLlmClient;
use App\Llm\CostCalculator;
use App\Llm\LlmClient;
use App\Llm\LlmPurpose;
use App\Llm\ModelRegistry;
use App\Llm\Testing\FakeLlmClient;
use App\Llm\TokenUsage;
use App\Llm\UnknownModel;
use RuntimeException;
use Tests\TestCase;

class LlmClientBindingTest extends TestCase
{
    public function test_the_interface_resolves_to_the_fake_in_the_test_environment(): void
    {
        $this->assertInstanceOf(FakeLlmClient::class, $this->app->make(LlmClient::class));
    }

    public function test_the_same_fake_is_shared_so_a_test_can_steer_what_the_code_under_test_gets(): void
    {
        $this->assertSame($this->app->make(LlmClient::class), $this->app->make(FakeLlmClient::class));
    }

    public function test_building_a_live_provider_client_in_a_test_fails_loudly(): void
    {
        $this->expectException(RuntimeException::class);

        $this->app->make(Client::class);
    }

    /**
     * The implementation holds a factory for the SDK client, so resolving it
     * costs nothing: a scheduled run with no work needs no credentials.
     */
    public function test_the_provider_implementation_builds_no_sdk_client_until_it_is_used(): void
    {
        $client = $this->app->make(AnthropicLlmClient::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A test asked for a live LLM client.');

        $client->pollBatch('msgbatch_anything');
    }

    public function test_the_configured_models_and_rates_are_wired_up(): void
    {
        $registry = $this->app->make(ModelRegistry::class);

        $this->assertSame('claude-opus-5', $registry->for(LlmPurpose::Generation));
        $this->assertSame('claude-haiku-4-5', $registry->for(LlmPurpose::Validation));
        $this->assertSame('claude-haiku-4-5', $registry->for(LlmPurpose::Extraction));

        $cost = $this->app->make(CostCalculator::class);

        $this->assertSame(
            5_000_000,
            $cost->micros('claude-opus-5', new TokenUsage(1_000_000, 0, 0, 0), batched: false),
        );
        $this->assertSame(
            2_500_000,
            $cost->micros('claude-opus-5', new TokenUsage(1_000_000, 0, 0, 0), batched: true),
        );

        // Extraction is a live call, so the live rate is the one it pays.
        $this->assertSame(
            1_000_000,
            $cost->micros(
                $registry->for(LlmPurpose::Extraction),
                new TokenUsage(1_000_000, 0, 0, 0),
                batched: false,
            ),
        );
    }

    /**
     * Rates change without notice, so a model whose rate nobody has configured
     * raises rather than reporting a spend of nothing.
     */
    public function test_a_configured_model_with_no_configured_rate_raises(): void
    {
        config(['cv.llm.models.extraction' => 'some-unpriced-model']);

        // The registry is a singleton built from configuration, so it is
        // forgotten before it is asked about a model the config has just
        // changed.
        $this->app->forgetInstance(ModelRegistry::class);

        $registry = $this->app->make(ModelRegistry::class);

        $this->assertSame('some-unpriced-model', $registry->for(LlmPurpose::Extraction));

        $this->expectException(UnknownModel::class);

        $this->app->make(CostCalculator::class)
            ->micros('some-unpriced-model', new TokenUsage(10, 10, 0, 0), batched: false);
    }

    /**
     * Extraction has a prompt version of its own, independent of generation's:
     * the two prompts change for different reasons.
     */
    public function test_extraction_carries_its_own_prompt_version(): void
    {
        $this->assertNotSame(GenerationPromptVersion::CURRENT, ExtractionPromptVersion::CURRENT);
    }
}
