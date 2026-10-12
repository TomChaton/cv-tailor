<?php

namespace App\Llm;

/**
 * One request within a batch, tagged with the ID its result will carry back.
 */
final readonly class BatchRequest
{
    public function __construct(
        public string $customId,
        public LlmRequest $request,
    ) {}
}
