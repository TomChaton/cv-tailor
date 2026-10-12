<?php

namespace App\Llm\Anthropic;

use Anthropic\Messages\CacheControlEphemeral;
use Anthropic\Messages\CacheControlEphemeral\TTL;
use Anthropic\Messages\JSONOutputFormat;
use Anthropic\Messages\MessageParam;
use Anthropic\Messages\MessageParam\Role;
use Anthropic\Messages\OutputConfig;
use Anthropic\Messages\TextBlockParam;
use App\Llm\LlmRequest;
use App\Llm\ModelRegistry;

/**
 * Turns an `LlmRequest` into the provider's request shape.
 *
 * This is the only class that knows both vocabularies.
 */
final readonly class AnthropicRequestFactory
{
    public function __construct(private ModelRegistry $models) {}

    public function params(LlmRequest $request): AnthropicParams
    {
        return new AnthropicParams(
            model: $this->models->for($request->purpose),
            maxTokens: $request->maxTokens,
            messages: [MessageParam::with(content: $request->prompt, role: Role::USER)],
            system: $this->system($request),
            outputConfig: $this->outputConfig($request),
        );
    }

    /**
     * @return list<TextBlockParam>
     */
    private function system(LlmRequest $request): array
    {
        $blocks = [];

        foreach ($request->system as $block) {
            $blocks[] = TextBlockParam::with(
                text: $block->text,
                // The enum's value rather than the enum: the SDK types the
                // property as a string, and handing it the case leaves the
                // typed property unreadable.
                cacheControl: $block->cacheable
                    ? CacheControlEphemeral::with(ttl: TTL::TTL_1H->value)
                    : null,
            );
        }

        return $blocks;
    }

    private function outputConfig(LlmRequest $request): ?OutputConfig
    {
        if ($request->schema === null) {
            return null;
        }

        return OutputConfig::with(
            format: JSONOutputFormat::with(schema: $request->schema),
        );
    }
}
