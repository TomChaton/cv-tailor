<?php

namespace App\Llm;

/**
 * One request's result, read back out of a finished batch.
 *
 * The custom ID travels with the result because the provider returns results
 * in any order. Keying by position works right up until a batch holds more
 * than one request, which is the point at which it stops being testable.
 */
final readonly class BatchResult
{
    private function __construct(
        public string $customId,
        public BatchOutcome $outcome,
        public ?LlmResponse $response = null,
        public ?LlmFailureKind $failure = null,
    ) {}

    public static function succeeded(string $customId, LlmResponse $response): self
    {
        return new self($customId, BatchOutcome::Succeeded, response: $response);
    }

    public static function errored(string $customId, LlmFailureKind $failure): self
    {
        return new self($customId, BatchOutcome::Errored, failure: $failure);
    }

    public static function canceled(string $customId): self
    {
        return new self($customId, BatchOutcome::Canceled);
    }

    public static function expired(string $customId): self
    {
        return new self($customId, BatchOutcome::Expired);
    }
}
