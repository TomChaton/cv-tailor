<?php

namespace App\Llm\Anthropic;

use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\ErrorResponse;
use App\Llm\LlmFailure;
use App\Llm\LlmFailureKind;
use App\Llm\ProviderError;

/**
 * Translates the provider's failures into the application's own.
 *
 * Two shapes arrive: an exception, when a call fails outright, and an error
 * object, when one request inside a batch errors. Both reduce to the same
 * three kinds, so the batch lifecycle reads a live failure and a batch-result
 * failure the same way.
 */
final class AnthropicFailures
{
    public static function fromException(APIException $exception): LlmFailure
    {
        return new LlmFailure(
            kind: self::kindOf($exception),
            providerRequestId: $exception->getRequestID(),
            status: $exception->status,
        );
    }

    /**
     * The error behind an `errored` batch result.
     *
     * A malformed request is the one the batch lifecycle must not retry, so it
     * is told apart from a provider-side failure here rather than nowhere.
     *
     * The provider's own type and message travel with the kind. A refusal of a
     * request this application built is a refusal the owner has to act on, and
     * the kind alone says only that something was refused.
     */
    public static function fromErrorResponse(ErrorResponse $response): ProviderError
    {
        return new ProviderError(
            kind: self::kindOfErrorType($response->error->type),
            type: $response->error->type,
            message: $response->error->message,
            providerRequestId: $response->requestID,
        );
    }

    private static function kindOfErrorType(string $type): LlmFailureKind
    {
        return match ($type) {
            'invalid_request_error',
            'authentication_error',
            'permission_error',
            'not_found_error',
            'billing_error' => LlmFailureKind::BadRequest,
            'rate_limit_error' => LlmFailureKind::RateLimit,
            default => LlmFailureKind::ServerError,
        };
    }

    private static function kindOf(APIException $exception): LlmFailureKind
    {
        $status = $exception->status;

        return match (true) {
            $exception instanceof RateLimitException => LlmFailureKind::RateLimit,
            $exception instanceof APITimeoutException,
            $exception instanceof APIConnectionException,
            $exception instanceof InternalServerException => LlmFailureKind::ServerError,
            $status === 429 => LlmFailureKind::RateLimit,
            $status !== null && $status >= 500 => LlmFailureKind::ServerError,
            default => LlmFailureKind::BadRequest,
        };
    }
}
