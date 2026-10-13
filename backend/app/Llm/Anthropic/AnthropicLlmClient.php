<?php

namespace App\Llm\Anthropic;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Messages\Batches\MessageBatch\ProcessingStatus;
use Anthropic\Messages\Batches\MessageBatchCanceledResult;
use Anthropic\Messages\Batches\MessageBatchErroredResult;
use Anthropic\Messages\Batches\MessageBatchExpiredResult;
use Anthropic\Messages\Batches\MessageBatchIndividualResponse;
use Anthropic\Messages\Batches\MessageBatchSucceededResult;
use Anthropic\Messages\Message;
use Anthropic\Messages\TextBlock;
use App\Llm\BatchCounts;
use App\Llm\BatchResult;
use App\Llm\BatchState;
use App\Llm\CostCalculator;
use App\Llm\LlmClient;
use App\Llm\LlmEvents;
use App\Llm\LlmFailure;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Llm\TokenUsage;
use Closure;
use Generator;
use InvalidArgumentException;

/**
 * The default implementation, against Anthropic's API.
 *
 * It uses the official SDK rather than hand-rolled HTTP, and it is the only
 * class in the application that names an SDK type. A second provider is a
 * second implementation of `LlmClient`, not a refactor of anything here.
 *
 * **The SDK client is built on first use.** Scheduled work resolves this class
 * to find out whether it has anything to do, and a run with nothing to do must
 * not need credentials: building the SDK client eagerly makes an instance
 * whose API key is not set yet report a stack trace every few minutes, on runs
 * that were never going to call anybody. Holding a factory keeps the cost
 * where the call is, and keeps ordinary constructor injection for every caller.
 */
final class AnthropicLlmClient implements LlmClient
{
    private ?Client $sdk = null;

    /**
     * @param  Closure(): Client  $sdkFactory  Resolved on the first call that
     *                                         needs the provider, and once.
     */
    public function __construct(
        private readonly Closure $sdkFactory,
        private readonly AnthropicRequestFactory $requests,
        private readonly CostCalculator $cost,
        private readonly LlmEvents $events,
    ) {}

    public function submitBatch(array $requests): string
    {
        if ($requests === []) {
            throw new InvalidArgumentException('A batch must carry at least one request.');
        }

        $payload = [];
        $models = [];

        foreach ($requests as $request) {
            $params = $this->requests->params($request->request);
            $payload[] = $params->batchRequest($request->customId);
            $models[$params->model] = true;
        }

        $model = implode(',', array_keys($models));

        try {
            $batch = $this->sdk()->messages->batches->create(requests: $payload);
        } catch (APIException $exception) {
            throw $this->fail($model, $exception);
        }

        $this->events->batchSubmitted($batch->id, count($requests), $model);

        return $batch->id;
    }

    public function pollBatch(string $providerBatchId): BatchState
    {
        try {
            $batch = $this->sdk()->messages->batches->retrieve($providerBatchId);
        } catch (APIException $exception) {
            throw $this->fail(null, $exception, $providerBatchId);
        }

        $counts = $batch->requestCounts;

        return new BatchState(
            providerBatchId: $batch->id,
            ended: $batch->processingStatus === ProcessingStatus::ENDED->value,
            counts: new BatchCounts(
                processing: $counts->processing,
                succeeded: $counts->succeeded,
                errored: $counts->errored,
                canceled: $counts->canceled,
                expired: $counts->expired,
            ),
        );
    }

    /**
     * @return Generator<int, BatchResult>
     */
    public function fetchResults(string $providerBatchId): Generator
    {
        try {
            $stream = $this->sdk()->messages->batches->resultsStream($providerBatchId);
        } catch (APIException $exception) {
            throw $this->fail(null, $exception, $providerBatchId);
        }

        foreach ($stream as $line) {
            yield $this->result($line);
        }
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $params = $this->requests->params($request);

        try {
            $message = $this->sdk()->messages->create(
                maxTokens: $params->maxTokens,
                messages: $params->messages,
                model: $params->model,
                outputConfig: $params->outputConfig,
                system: $params->system,
            );
        } catch (APIException $exception) {
            throw $this->fail($params->model, $exception);
        }

        $response = $this->response($message, batched: false);

        $this->events->request($request->purpose, $response);

        return $response;
    }

    private function sdk(): Client
    {
        return $this->sdk ??= ($this->sdkFactory)();
    }

    private function result(MessageBatchIndividualResponse $line): BatchResult
    {
        $result = $line->result;

        return match (true) {
            $result instanceof MessageBatchSucceededResult => BatchResult::succeeded(
                $line->customID,
                $this->response($result->message, batched: true),
            ),
            $result instanceof MessageBatchErroredResult => BatchResult::errored(
                $line->customID,
                AnthropicFailures::fromErrorResponse($result->error),
            ),
            $result instanceof MessageBatchExpiredResult => BatchResult::expired($line->customID),
            $result instanceof MessageBatchCanceledResult => BatchResult::canceled($line->customID),
            // No default: the provider has four result types and all four are
            // above. A fifth would raise an unhandled-match error rather than
            // being quietly folded into one of these.
        };
    }

    private function response(Message $message, bool $batched): LlmResponse
    {
        $usage = new TokenUsage(
            inputTokens: $message->usage->inputTokens,
            outputTokens: $message->usage->outputTokens,
            cacheWriteTokens: $message->usage->cacheCreationInputTokens ?? 0,
            cacheReadTokens: $message->usage->cacheReadInputTokens ?? 0,
        );

        return new LlmResponse(
            model: $message->model,
            text: $this->text($message),
            stopReason: $message->stopReason,
            usage: $usage,
            costMicros: $this->cost->micros($message->model, $usage, $batched),
        );
    }

    /**
     * The response's text blocks, concatenated.
     *
     * A response may carry blocks that are not text — thinking, tool use — and
     * none of them are the model's answer.
     */
    private function text(Message $message): string
    {
        $text = '';

        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                $text .= $block->text;
            }
        }

        return $text;
    }

    private function fail(
        ?string $model,
        APIException $exception,
        ?string $providerBatchId = null,
    ): LlmFailure {
        $failure = AnthropicFailures::fromException($exception);

        $this->events->failed($model, $failure, $providerBatchId);

        return $failure;
    }
}
