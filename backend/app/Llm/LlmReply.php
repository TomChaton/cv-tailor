<?php

namespace App\Llm;

/**
 * What the provider said about one request, before it has been priced.
 *
 * The model name here is the provider's own: the build that actually served
 * the request. It is the only record of what ran, and it is the name a
 * revision stores.
 *
 * **It is not the name a cost is computed from.** The application asks for an
 * alias and the provider may answer about a dated build of it, which is a name
 * nothing was ever configured under. So a reply cannot price itself: pricing
 * needs the model the request asked for, and only the caller that made the
 * request — or the revision that recorded it — knows that. See
 * `CostCalculator::price()`.
 */
final readonly class LlmReply
{
    public function __construct(
        public string $reportedModel,
        public string $text,
        public ?string $stopReason,
        public TokenUsage $usage,
    ) {}
}
