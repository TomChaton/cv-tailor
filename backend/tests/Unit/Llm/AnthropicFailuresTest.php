<?php

namespace Tests\Unit\Llm;

use Anthropic\APIErrorObject;
use Anthropic\AuthenticationError;
use Anthropic\BillingError;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\ErrorResponse;
use Anthropic\GatewayTimeoutError;
use Anthropic\InvalidRequestError;
use Anthropic\NotFoundError;
use Anthropic\OverloadedError;
use Anthropic\PermissionError;
use Anthropic\RateLimitError;
use App\Llm\Anthropic\AnthropicFailures;
use App\Llm\LlmFailureKind;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AnthropicFailuresTest extends TestCase
{
    /**
     * @return list<array{int, LlmFailureKind}>
     */
    public static function statuses(): array
    {
        return [
            [400, LlmFailureKind::BadRequest],
            [401, LlmFailureKind::BadRequest],
            [403, LlmFailureKind::BadRequest],
            [404, LlmFailureKind::BadRequest],
            [422, LlmFailureKind::BadRequest],
            [429, LlmFailureKind::RateLimit],
            [500, LlmFailureKind::ServerError],
            [529, LlmFailureKind::ServerError],
        ];
    }

    #[DataProvider('statuses')]
    public function test_a_status_maps_to_a_failure_class(int $status, LlmFailureKind $expected): void
    {
        $failure = AnthropicFailures::fromException(APIStatusException::from(
            new Request('post', 'https://api.anthropic.com/v1/messages'),
            new Response($status, ['request-id' => 'req_abc'], '{"type":"error","error":{"type":"api_error","message":"nope"}}'),
        ));

        $this->assertSame($expected, $failure->kind);
        $this->assertSame('req_abc', $failure->providerRequestId);
        $this->assertSame($status, $failure->status);
    }

    public function test_rate_limits_and_server_errors_are_distinguishable_from_a_bad_request(): void
    {
        $this->assertFalse(LlmFailureKind::RateLimit->isMalformedRequest());
        $this->assertFalse(LlmFailureKind::ServerError->isMalformedRequest());
        $this->assertTrue(LlmFailureKind::BadRequest->isMalformedRequest());
    }

    public function test_a_transport_failure_is_a_server_error_not_a_bad_request(): void
    {
        $request = new Request('post', 'https://api.anthropic.com/v1/messages');

        $this->assertSame(
            LlmFailureKind::ServerError,
            AnthropicFailures::fromException(new APIConnectionException(request: $request))->kind,
        );
        $this->assertSame(
            LlmFailureKind::ServerError,
            AnthropicFailures::fromException(new APITimeoutException(request: $request))->kind,
        );
    }

    /**
     * @return list<array{InvalidRequestError|AuthenticationError|PermissionError|NotFoundError|BillingError|RateLimitError|OverloadedError|APIErrorObject|GatewayTimeoutError, LlmFailureKind}>
     */
    public static function errorObjects(): array
    {
        return [
            [InvalidRequestError::with(), LlmFailureKind::BadRequest],
            [AuthenticationError::with(), LlmFailureKind::BadRequest],
            [PermissionError::with(), LlmFailureKind::BadRequest],
            [NotFoundError::with(), LlmFailureKind::BadRequest],
            [BillingError::with(), LlmFailureKind::BadRequest],
            [RateLimitError::with(), LlmFailureKind::RateLimit],
            [OverloadedError::with(), LlmFailureKind::ServerError],
            [APIErrorObject::with(), LlmFailureKind::ServerError],
            [GatewayTimeoutError::with(), LlmFailureKind::ServerError],
        ];
    }

    #[DataProvider('errorObjects')]
    public function test_an_errored_batch_result_separates_a_malformed_request_from_a_provider_failure(
        InvalidRequestError|AuthenticationError|PermissionError|NotFoundError|BillingError|RateLimitError|OverloadedError|APIErrorObject|GatewayTimeoutError $error,
        LlmFailureKind $expected,
    ): void {
        $response = ErrorResponse::with(error: $error, requestID: 'req_abc');

        $this->assertSame($expected, AnthropicFailures::fromErrorResponse($response));
    }
}
