<?php

namespace App\Llm;

/**
 * One block of the system prompt.
 *
 * A block may carry the cache breakpoint. The master CV is the large stable
 * prefix of every generation request, so marking its block cacheable is what
 * makes prompt caching worth anything here.
 */
final readonly class SystemBlock
{
    private function __construct(
        public string $text,
        public bool $cacheable,
    ) {}

    public static function text(string $text): self
    {
        return new self($text, cacheable: false);
    }

    /**
     * A block ending in the cache breakpoint, with the longer cache lifetime.
     *
     * Batch requests run in parallel over many minutes, so the shorter
     * lifetime would expire before most of the batch reached the cache.
     */
    public static function cacheable(string $text): self
    {
        return new self($text, cacheable: true);
    }
}
