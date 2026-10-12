<?php

namespace App\Llm;

/**
 * What a response cost in tokens.
 *
 * Cache writes and cache reads are counted apart because they are priced
 * apart: a revision that could not tell them apart could not report what it
 * cost.
 */
final readonly class TokenUsage
{
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheWriteTokens,
        public int $cacheReadTokens,
    ) {}

    /**
     * @return array{
     *     input_tokens: int,
     *     output_tokens: int,
     *     cache_write_tokens: int,
     *     cache_read_tokens: int,
     * }
     */
    public function logContext(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
            'cache_read_tokens' => $this->cacheReadTokens,
        ];
    }
}
