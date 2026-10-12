<?php

namespace Tests\Unit\Llm;

use App\Llm\Anthropic\AnthropicRequestFactory;
use App\Llm\LlmPurpose;
use App\Llm\LlmRequest;
use App\Llm\ModelRegistry;
use App\Llm\SystemBlock;
use PHPUnit\Framework\TestCase;

class AnthropicRequestFactoryTest extends TestCase
{
    private function factory(): AnthropicRequestFactory
    {
        return new AnthropicRequestFactory(new ModelRegistry([
            'generation' => 'big-model',
            'validation' => 'small-model',
        ]));
    }

    /**
     * @param  array<string, mixed>|null  $schema
     */
    private function request(?array $schema = null): LlmRequest
    {
        return new LlmRequest(
            purpose: LlmPurpose::Generation,
            system: [
                SystemBlock::text('Static instructions.'),
                SystemBlock::cacheable('The master CV.'),
            ],
            prompt: 'The job description.',
            maxTokens: 4096,
            schema: $schema,
        );
    }

    public function test_the_model_comes_from_configuration_rather_than_the_caller(): void
    {
        $this->assertSame('big-model', $this->factory()->params($this->request())->model);
        $this->assertSame('small-model', $this->factory()->params(new LlmRequest(
            purpose: LlmPurpose::Validation,
            system: [],
            prompt: 'Does this hold up?',
            maxTokens: 512,
        ))->model);
    }

    public function test_only_the_block_the_caller_marked_carries_the_one_hour_cache_breakpoint(): void
    {
        $system = $this->factory()->params($this->request())->system;

        $this->assertCount(2, $system);
        $this->assertSame('Static instructions.', $system[0]->text);
        $this->assertNull($system[0]->cacheControl);
        $this->assertSame('The master CV.', $system[1]->text);
        $this->assertSame('1h', $system[1]->cacheControl?->ttl);
    }

    public function test_a_schema_becomes_a_structured_output_format(): void
    {
        $schema = ['type' => 'object', 'properties' => ['headline' => ['type' => 'string']]];

        $params = $this->factory()->params($this->request($schema));

        $this->assertSame($schema, $params->outputConfig?->format?->schema);
    }

    public function test_a_request_without_a_schema_sends_no_output_config(): void
    {
        $this->assertNull($this->factory()->params($this->request())->outputConfig);
    }

    public function test_a_batch_request_carries_the_custom_id_and_nothing_the_batch_api_refuses(): void
    {
        $request = $this->factory()->params($this->request())->batchRequest('41');

        $this->assertSame('41', $request->customID);
        $this->assertSame('big-model', $request->params->model);
        $this->assertSame(4096, $request->params->maxTokens);

        // `stream` is declared on the batch params type but must never be set
        // on a batch request. Nothing in the mapping can set it.
        $this->assertNull($request->params->stream);
    }
}
