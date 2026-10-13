<?php

namespace App\Llm\Testing;

use App\Llm\BatchCounts;
use App\Llm\BatchOutcome;
use App\Llm\BatchRequest;
use App\Llm\BatchResult;
use App\Llm\BatchState;
use App\Llm\CostCalculator;
use App\Llm\LlmClient;
use App\Llm\LlmEvents;
use App\Llm\LlmFailure;
use App\Llm\LlmFailureKind;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Llm\ModelRegistry;
use App\Llm\TokenUsage;
use Generator;
use LogicException;

/**
 * Answers from recorded responses on disk instead of a model.
 *
 * It is bound to `LlmClient` in the test environment, so a test that forgets
 * to fake still cannot reach the network. It emits the same log events as the
 * real client, from the same place, so observability is testable without a
 * provider.
 *
 * Fixtures are recorded against the fictional master CV only. No fixture
 * derives from a real CV.
 */
final class FakeLlmClient implements LlmClient
{
    /** @var list<LlmRequest> */
    private array $liveRequests = [];

    /** @var list<array{batch_id: string, requests: list<BatchRequest>}> */
    private array $submittedBatches = [];

    /** @var list<array{customId: string, outcome: BatchOutcome, fixture: string|null, failure: LlmFailureKind|null}> */
    private array $queuedResults = [];

    /** @var list<array{fixture: string|null, failure: LlmFailureKind|null}> */
    private array $liveOutcomes = [];

    private bool $batchOpen = false;

    private ?LlmFailureKind $batchFailure = null;

    /** @var array<string, LlmFailureKind> */
    private array $pollFailures = [];

    private int $batches = 0;

    public function __construct(
        private readonly string $fixturePath,
        private readonly CostCalculator $cost,
        private readonly ModelRegistry $models,
        private readonly LlmEvents $events,
    ) {}

    /**
     * Answer the next live call from this recorded response.
     */
    public function willAnswerWith(string $fixture): self
    {
        $this->liveOutcomes[] = ['fixture' => $fixture, 'failure' => null];

        return $this;
    }

    /**
     * Fail the next live call.
     */
    public function willFailWith(LlmFailureKind $kind): self
    {
        $this->liveOutcomes[] = ['fixture' => null, 'failure' => $kind];

        return $this;
    }

    /**
     * Refuse to answer about one batch, the way a provider answers about a
     * batch it no longer holds.
     */
    public function willFailPollOf(string $providerBatchId, LlmFailureKind $kind): self
    {
        $this->pollFailures[$providerBatchId] = $kind;

        return $this;
    }

    /**
     * Forget the queued results, so that a second batch answers with its own
     * rather than with every result the test has ever queued.
     */
    public function forgetResults(): self
    {
        $this->queuedResults = [];

        return $this;
    }

    /**
     * Refuse the next batch submission, the way a provider refuses a batch it
     * will not take at all.
     */
    public function willRefuseTheBatch(LlmFailureKind $kind): self
    {
        $this->batchFailure = $kind;

        return $this;
    }

    public function willSucceed(string $customId, string $fixture): self
    {
        $this->queuedResults[] = [
            'customId' => $customId,
            'outcome' => BatchOutcome::Succeeded,
            'fixture' => $fixture,
            'failure' => null,
        ];

        return $this;
    }

    /**
     * Queue an `errored` result. A `BadRequest` kind is the malformed-request
     * branch the batch lifecycle must not retry.
     */
    public function willError(string $customId, LlmFailureKind $kind): self
    {
        $this->queuedResults[] = [
            'customId' => $customId,
            'outcome' => BatchOutcome::Errored,
            'fixture' => null,
            'failure' => $kind,
        ];

        return $this;
    }

    public function willCancel(string $customId): self
    {
        $this->queuedResults[] = [
            'customId' => $customId,
            'outcome' => BatchOutcome::Canceled,
            'fixture' => null,
            'failure' => null,
        ];

        return $this;
    }

    public function willExpire(string $customId): self
    {
        $this->queuedResults[] = [
            'customId' => $customId,
            'outcome' => BatchOutcome::Expired,
            'fixture' => null,
            'failure' => null,
        ];

        return $this;
    }

    /**
     * Report every poll as still in progress, so the waiting branch is
     * reachable.
     */
    public function willHoldBatchOpen(): self
    {
        $this->batchOpen = true;

        return $this;
    }

    /**
     * What the fake was asked, so a test can assert on a request without a
     * network call.
     *
     * @return list<LlmRequest>
     */
    public function liveRequests(): array
    {
        return $this->liveRequests;
    }

    /**
     * @return list<array{batch_id: string, requests: list<BatchRequest>}>
     */
    public function submittedBatches(): array
    {
        return $this->submittedBatches;
    }

    public function submitBatch(array $requests): string
    {
        if ($requests === []) {
            throw new LogicException('A batch must carry at least one request.');
        }

        if ($this->batchFailure !== null) {
            $failure = new LlmFailure(kind: $this->batchFailure, providerRequestId: 'req_fake');

            $this->events->failed(null, $failure);

            throw $failure;
        }

        $batchId = sprintf('msgbatch_fake_%d', ++$this->batches);

        $this->submittedBatches[] = ['batch_id' => $batchId, 'requests' => $requests];

        $models = [];

        foreach ($requests as $request) {
            $models[$this->models->for($request->request->purpose)] = true;
        }

        $this->events->batchSubmitted($batchId, count($requests), implode(',', array_keys($models)));

        return $batchId;
    }

    public function pollBatch(string $providerBatchId): BatchState
    {
        if (isset($this->pollFailures[$providerBatchId])) {
            $failure = new LlmFailure(
                kind: $this->pollFailures[$providerBatchId],
                providerRequestId: 'req_fake',
            );

            $this->events->failed(null, $failure, $providerBatchId);

            throw $failure;
        }

        if ($this->batchOpen) {
            return new BatchState(
                providerBatchId: $providerBatchId,
                ended: false,
                counts: new BatchCounts(processing: count($this->queuedResults)),
            );
        }

        $tally = [
            BatchOutcome::Succeeded->value => 0,
            BatchOutcome::Errored->value => 0,
            BatchOutcome::Canceled->value => 0,
            BatchOutcome::Expired->value => 0,
        ];

        foreach ($this->queuedResults as $result) {
            $tally[$result['outcome']->value]++;
        }

        return new BatchState(
            providerBatchId: $providerBatchId,
            ended: true,
            counts: new BatchCounts(
                succeeded: $tally[BatchOutcome::Succeeded->value],
                errored: $tally[BatchOutcome::Errored->value],
                canceled: $tally[BatchOutcome::Canceled->value],
                expired: $tally[BatchOutcome::Expired->value],
            ),
        );
    }

    /**
     * @return Generator<int, BatchResult>
     */
    public function fetchResults(string $providerBatchId): Generator
    {
        foreach ($this->queuedResults as $queued) {
            yield match ($queued['outcome']) {
                BatchOutcome::Succeeded => BatchResult::succeeded(
                    $queued['customId'],
                    $this->recorded($queued['fixture'] ?? '', batched: true),
                ),
                BatchOutcome::Errored => BatchResult::errored(
                    $queued['customId'],
                    $queued['failure'] ?? LlmFailureKind::ServerError,
                ),
                BatchOutcome::Canceled => BatchResult::canceled($queued['customId']),
                BatchOutcome::Expired => BatchResult::expired($queued['customId']),
            };
        }
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $this->liveRequests[] = $request;

        if ($this->liveOutcomes === []) {
            throw new LogicException(
                'The fake LLM client was asked to complete a request with nothing queued to answer it.'
            );
        }

        // One ordered queue, so a test can line a sequence of answers and
        // failures up with the calls the code under test makes.
        $next = array_shift($this->liveOutcomes);

        if ($next['failure'] !== null) {
            $failure = new LlmFailure(
                kind: $next['failure'],
                providerRequestId: 'req_fake',
            );

            $this->events->failed($this->models->for($request->purpose), $failure);

            throw $failure;
        }

        $response = $this->recorded($next['fixture'] ?? '', batched: false);

        $this->events->request($request->purpose, $response);

        return $response;
    }

    private function recorded(string $fixture, bool $batched): LlmResponse
    {
        $path = sprintf('%s/%s.json', rtrim($this->fixturePath, '/'), $fixture);

        if (! is_file($path) || ! is_readable($path)) {
            throw MissingFixture::at($path);
        }

        $contents = (string) file_get_contents($path);
        $decoded = json_decode($contents, associative: true);

        if (! is_array($decoded)) {
            throw MissingFixture::malformed($path, 'it is not a JSON object');
        }

        $model = $decoded['model'] ?? null;
        $text = $decoded['text'] ?? null;
        $stopReason = $decoded['stop_reason'] ?? null;
        $usage = $decoded['usage'] ?? null;

        if (! is_string($model) || ! is_string($text) || ! is_array($usage)) {
            throw MissingFixture::malformed($path, 'it needs a model, a text and a usage object');
        }

        $tokens = new TokenUsage(
            inputTokens: self::tokens($path, $usage, 'input_tokens'),
            outputTokens: self::tokens($path, $usage, 'output_tokens'),
            cacheWriteTokens: self::tokens($path, $usage, 'cache_write_tokens'),
            cacheReadTokens: self::tokens($path, $usage, 'cache_read_tokens'),
        );

        return new LlmResponse(
            model: $model,
            text: $text,
            stopReason: is_string($stopReason) ? $stopReason : null,
            usage: $tokens,
            costMicros: $this->cost->micros($model, $tokens, $batched),
        );
    }

    /**
     * @param  array<array-key, mixed>  $usage
     */
    private static function tokens(string $path, array $usage, string $key): int
    {
        $value = $usage[$key] ?? 0;

        if (! is_int($value) || $value < 0) {
            throw MissingFixture::malformed($path, sprintf('%s must be a non-negative integer', $key));
        }

        return $value;
    }
}
