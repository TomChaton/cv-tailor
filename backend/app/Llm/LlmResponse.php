<?php

namespace App\Llm;

/**
 * What the provider returned for one request.
 */
final readonly class LlmResponse
{
    public function __construct(
        public string $model,
        public string $text,
        public ?string $stopReason,
        public TokenUsage $usage,
        public int $costMicros,
    ) {}
}
