<?php

namespace App\Llm;

use RuntimeException;

/**
 * The one failure a caller of `LlmClient` catches.
 *
 * Provider exceptions are translated into this, so no caller ever names an SDK
 * class and swapping providers stays an implementation change.
 *
 * It deliberately does not chain the provider's own exception. A provider
 * error body can quote the request that caused it, and a chained exception is
 * reported with its whole chain: that is how a prompt ends up in a log file.
 * The request ID is what makes a provider-side problem traceable, and it is
 * carried here instead.
 */
class LlmFailure extends RuntimeException
{
    public function __construct(
        public readonly LlmFailureKind $kind,
        public readonly ?string $providerRequestId = null,
        public readonly ?int $status = null,
    ) {
        parent::__construct(sprintf(
            'The LLM provider call failed (%s, status %s, request %s).',
            $kind->value,
            $status ?? 'none',
            $providerRequestId ?? 'none',
        ));
    }
}
