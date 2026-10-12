<?php

namespace App\Llm;

/**
 * Why a call to the provider failed.
 *
 * The three are kept apart because the batch lifecycle treats them
 * differently: a malformed request is this application's own bug and retrying
 * it will fail the same way forever, while a rate limit or a server error is
 * the provider having a bad minute and is worth another attempt.
 */
enum LlmFailureKind: string
{
    case BadRequest = 'bad_request';
    case RateLimit = 'rate_limit';
    case ServerError = 'server_error';

    /**
     * Whether the provider refused the request itself rather than failing to
     * serve it.
     */
    public function isMalformedRequest(): bool
    {
        return $this === self::BadRequest;
    }
}
