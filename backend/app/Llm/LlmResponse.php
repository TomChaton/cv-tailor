<?php

namespace App\Llm;

/**
 * What the provider returned for one request, priced.
 *
 * Two model names exist in this system and each has one job, so a response
 * carries both and no caller has to guess which it is holding:
 *
 * - **`requestedModel`** is what the application asked for. Models are
 *   configured as aliases, rates are keyed by those names, and this is the
 *   name `costMicros` was computed from.
 * - **`reportedModel`** is what the provider says served the request, which
 *   may be a dated build of the alias. It is the only record of what actually
 *   ran, and it is what a revision stores.
 *
 * A response is only ever made by pricing a reply, which is why the
 * constructor is private: an unpriced response, or one priced from the name
 * the provider answered with, is the bug this shape exists to make
 * unexpressible.
 */
final readonly class LlmResponse
{
    private function __construct(
        public string $requestedModel,
        public string $reportedModel,
        public string $text,
        public ?string $stopReason,
        public TokenUsage $usage,
        public int $costMicros,
    ) {}

    public static function priced(LlmReply $reply, string $requestedModel, int $costMicros): self
    {
        return new self(
            requestedModel: $requestedModel,
            reportedModel: $reply->reportedModel,
            text: $reply->text,
            stopReason: $reply->stopReason,
            usage: $reply->usage,
            costMicros: $costMicros,
        );
    }
}
