<?php

namespace App\Llm;

/**
 * The one way this application talks to a model.
 *
 * Domain code depends on this interface and never on a provider's own types,
 * which is what makes the provider a configuration choice rather than a
 * rewrite — and what makes "no test calls a live model" enforceable rather
 * than aspirational.
 *
 * Retries are not this interface's business. The revision's `attempts` counter
 * is the single place an attempt is counted, and a retry buried in here would
 * hide attempts from the state machine that counts them.
 */
interface LlmClient
{
    /**
     * Submit many requests at once and return the provider's batch ID.
     *
     * @param  list<BatchRequest>  $requests
     *
     * @throws LlmFailure
     */
    public function submitBatch(array $requests): string;

    /**
     * Report whether a batch has ended, and how its requests have landed.
     *
     * @throws LlmFailure
     */
    public function pollBatch(string $providerBatchId): BatchState;

    /**
     * Stream a finished batch's results.
     *
     * It streams rather than returning an array: a batch result set is
     * unbounded in principle, and reading it into memory is a trap waiting for
     * a larger CV. Callers key by `BatchResult::$customId`, because the
     * provider returns results in any order.
     *
     * @return iterable<BatchResult>
     *
     * @throws LlmFailure
     */
    public function fetchResults(string $providerBatchId): iterable;

    /**
     * Make one live request.
     *
     * @throws LlmFailure
     */
    public function complete(LlmRequest $request): LlmResponse;
}
