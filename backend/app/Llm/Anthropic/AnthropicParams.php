<?php

namespace App\Llm\Anthropic;

use Anthropic\Messages\Batches\BatchCreateParams\Request;
use Anthropic\Messages\Batches\BatchCreateParams\Request\Params;
use Anthropic\Messages\MessageParam;
use Anthropic\Messages\OutputConfig;
use Anthropic\Messages\TextBlockParam;

/**
 * One request, in the provider's terms.
 *
 * Its fields are deliberately the set the pinned SDK accepts on **both** a
 * live request and a batch request. The batch API refuses some live-only
 * parameters, and the SDK is the authority on which: compare
 * `Anthropic\Messages\MessageCreateParams` with
 * `Anthropic\Messages\Batches\BatchCreateParams\Request\Params` after any SDK
 * upgrade, rather than assuming. Keeping the payload to the intersection means
 * a request cannot be built here that only one of the two paths will take.
 *
 * `stream` is the one field the batch params type declares that must never be
 * set on a batch request, so nothing here can set it.
 */
final readonly class AnthropicParams
{
    /**
     * @param  list<MessageParam>  $messages
     * @param  list<TextBlockParam>  $system
     */
    public function __construct(
        public string $model,
        public int $maxTokens,
        public array $messages,
        public array $system,
        public ?OutputConfig $outputConfig,
    ) {}

    public function batchRequest(string $customId): Request
    {
        return Request::with(
            customID: $customId,
            params: Params::with(
                maxTokens: $this->maxTokens,
                messages: $this->messages,
                model: $this->model,
                outputConfig: $this->outputConfig,
                system: $this->system,
            ),
        );
    }
}
