<?php

namespace App\Llm;

/**
 * One request's result, read back out of a finished batch.
 *
 * The custom ID travels with the result because the provider returns results
 * in any order. Keying by position works right up until a batch holds more
 * than one request, which is the point at which it stops being testable.
 *
 * **A successful result carries an unpriced reply.** The cost has to be
 * computed from the model the request asked for, and a batch's results say
 * only which build served them; the requested model is recorded on the
 * revision the result belongs to, so collection prices it from there.
 */
final readonly class BatchResult
{
    private function __construct(
        public string $customId,
        public BatchOutcome $outcome,
        public ?LlmReply $reply = null,
        public ?ProviderError $failure = null,
    ) {}

    public static function succeeded(string $customId, LlmReply $reply): self
    {
        return new self($customId, BatchOutcome::Succeeded, reply: $reply);
    }

    public static function errored(string $customId, ProviderError $failure): self
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
