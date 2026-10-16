<?php

namespace App\Llm;

/**
 * What the provider said about one request it would not serve.
 *
 * The `kind` is what the batch lifecycle acts on: a malformed request is this
 * application's own bug and retrying it fails the same way for ever. The
 * provider's own `type` and `message` are what the owner acts on, and they are
 * carried because the kind alone cannot be acted on at all — "the request was
 * refused" names no fault and suggests no fix, which leaves diagnosing one to
 * somebody willing to go round this application to the provider's SDK.
 *
 * **The message is bounded.** A refusal is a sentence in practice, and
 * nothing here is allowed to become a route by which a request's own contents
 * reach a log file.
 */
final readonly class ProviderError
{
    /**
     * Long enough for any refusal the provider actually writes.
     */
    private const MESSAGE_LIMIT = 500;

    public string $message;

    public function __construct(
        public LlmFailureKind $kind,
        public string $type,
        string $message,
        public ?string $providerRequestId = null,
    ) {
        $this->message = mb_substr($message, 0, self::MESSAGE_LIMIT);
    }
}
