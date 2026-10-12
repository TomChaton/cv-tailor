<?php

namespace App\Llm;

use App\Observability\EventLogger;

/**
 * The provider's log lines, in one place.
 *
 * Event names are part of the interface, so both the real client and the fake
 * emit them from here and cannot drift apart.
 *
 * Nothing here carries prompt text, completion text, CV content or an API key.
 * Sizes, counts, identifiers and outcomes only: a log line that would be
 * embarrassing in a public bug report is a bug.
 */
final readonly class LlmEvents
{
    public function __construct(private EventLogger $log) {}

    public function request(LlmPurpose $purpose, LlmResponse $response): void
    {
        $this->log->log('llm.request', [
            'model' => $response->model,
            'purpose' => $purpose->value,
            'outcome' => $response->stopReason ?? 'unknown',
            'cost_micros' => $response->costMicros,
            ...$response->usage->logContext(),
        ]);
    }

    public function batchSubmitted(string $providerBatchId, int $requestCount, string $model): void
    {
        $this->log->log('llm.batch_submitted', [
            'provider_batch_id' => $providerBatchId,
            'request_count' => $requestCount,
            'model' => $model,
        ]);
    }

    /**
     * @param  string|null  $model  Null where the call concerns a batch rather
     *                              than one model's request.
     */
    public function failed(
        ?string $model,
        LlmFailure $failure,
        ?string $providerBatchId = null,
    ): void {
        $this->log->warning('llm.failed', [
            'model' => $model,
            'failure' => $failure->kind->value,
            // The only thing that lets a provider-side problem be traced, and
            // lost the moment it is not logged.
            'provider_request_id' => $failure->providerRequestId,
            'provider_batch_id' => $providerBatchId,
        ]);
    }
}
