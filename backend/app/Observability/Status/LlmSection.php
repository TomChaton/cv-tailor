<?php

namespace App\Observability\Status;

/**
 * Whether this instance can reach a model at all.
 *
 * A key that is not set is a precondition the owner can see and fix, and this
 * is where it belongs: the alternative is every scheduled run reporting a
 * failure, which trains a reader to ignore the channel that has to stay worth
 * reading.
 *
 * The key itself is never printed, in part or in whole. Whether it is there is
 * the only question this answers.
 */
class LlmSection implements StatusSection
{
    public function __construct(private readonly string $apiKey) {}

    public function heading(): string
    {
        return 'LLM';
    }

    public function rows(): array
    {
        return [
            'api key' => trim($this->apiKey) === ''
                ? 'not set: put ANTHROPIC_API_KEY in .env'
                : 'set',
        ];
    }
}
