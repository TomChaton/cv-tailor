<?php

namespace App\Llm;

/**
 * One request to the provider, in the application's own terms.
 *
 * It carries no model name: the model follows from the purpose and the
 * configuration, so no caller can pin one by accident.
 */
final readonly class LlmRequest
{
    /**
     * @param  list<SystemBlock>  $system  Ordered most to least stable, so the
     *                                     cache breakpoint has a prefix to sit on.
     * @param  string  $prompt  The per-request user turn.
     * @param  array<string, mixed>|null  $schema  A JSON schema the response is
     *                                             constrained to. This class
     *                                             transports a schema; it does
     *                                             not build one.
     */
    public function __construct(
        public LlmPurpose $purpose,
        public array $system,
        public string $prompt,
        public int $maxTokens,
        public ?array $schema = null,
    ) {}
}
