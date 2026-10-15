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
 *
 * **A model name is logged under the name of its job.** `requested_model` is
 * what the application asked for and what a cost was computed from;
 * `reported_model` is the build the provider says served it. A single `model`
 * key would leave a reader unable to tell which of the two they were holding,
 * and a line that ambiguous cannot be used to check a bill.
 */
final readonly class LlmEvents
{
    public function __construct(private EventLogger $log) {}

    public function request(LlmPurpose $purpose, LlmResponse $response): void
    {
        $this->log->log('llm.request', [
            'requested_model' => $response->requestedModel,
            'reported_model' => $response->reportedModel,
            'purpose' => $purpose->value,
            'outcome' => $response->stopReason ?? 'unknown',
            'cost_micros' => $response->costMicros,
            ...$response->usage->logContext(),
        ]);
    }

    /**
     * @param  string  $requestedModel  Every model the batch asks about.
     */
    public function batchSubmitted(string $providerBatchId, int $requestCount, string $requestedModel): void
    {
        $this->log->log('llm.batch_submitted', [
            'provider_batch_id' => $providerBatchId,
            'request_count' => $requestCount,
            'requested_model' => $requestedModel,
        ]);
    }

    /**
     * @param  string|null  $requestedModel  The model that was asked about —
     *                                       there is no reported one, because
     *                                       nothing was answered. Null where
     *                                       the call concerns a batch rather
     *                                       than one model's request.
     */
    public function failed(
        ?string $requestedModel,
        LlmFailure $failure,
        ?string $providerBatchId = null,
    ): void {
        $this->log->warning('llm.failed', [
            'requested_model' => $requestedModel,
            'failure' => $failure->kind->value,
            // The only thing that lets a provider-side problem be traced, and
            // lost the moment it is not logged.
            'provider_request_id' => $failure->providerRequestId,
            'provider_batch_id' => $providerBatchId,
        ]);
    }
}
