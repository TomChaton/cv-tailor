<?php

namespace App\Llm;

/**
 * What a response cost, in integer micros.
 *
 * Micros — millionths of a currency unit — match the `cost_micros` column, and
 * money is never a float. Rates are divided out at the end and rounded up, so
 * a cost is never reported as less than it was.
 *
 * **Rates are keyed by the model the application asked for**, never by the one
 * the provider answered about. A model is configured as an alias, the alias is
 * what the published price belongs to, and the dated build behind it changes
 * with nobody doing anything — so a rate keyed on the reported name would be a
 * rate configured for a model the owner never chose.
 */
final readonly class CostCalculator
{
    private const TOKENS_PER_RATE = 1_000_000;

    /**
     * @param  array<string, CostRates>  $rates
     * @param  int  $batchPercent  What the batch API bills as a percentage of
     *                             the live rate.
     */
    public function __construct(
        private array $rates,
        private int $batchPercent,
    ) {}

    /**
     * @param  array<array-key, mixed>  $rates  As configured, keyed by model.
     */
    public static function fromConfig(array $rates, int $batchPercent): self
    {
        $parsed = [];

        foreach ($rates as $model => $modelRates) {
            if (! is_string($model) || ! is_array($modelRates)) {
                continue;
            }

            $parsed[$model] = CostRates::from($model, $modelRates);
        }

        return new self($parsed, $batchPercent);
    }

    /**
     * A reply, priced against the model the request asked for.
     *
     * The only way a response is made: a reply carries the provider's own name
     * for the model and pricing it by that name is the mistake this method
     * exists to prevent.
     */
    public function price(string $requestedModel, LlmReply $reply, bool $batched): LlmResponse
    {
        return LlmResponse::priced(
            $reply,
            $requestedModel,
            $this->micros($requestedModel, $reply->usage, $batched),
        );
    }

    /**
     * Whether a model the application may ask for has a rate to price it by.
     *
     * Asked by `cv:status`, so the answer is readable beside the API key
     * rather than arriving as a run that spent its attempts failing.
     */
    public function hasRates(string $requestedModel): bool
    {
        return isset($this->rates[$requestedModel]);
    }

    public function micros(string $requestedModel, TokenUsage $usage, bool $batched): int
    {
        $rates = $this->rates[$requestedModel] ?? throw UnknownModel::forRates($requestedModel);

        $weighted = $usage->inputTokens * $rates->input
            + $usage->outputTokens * $rates->output
            + $usage->cacheWriteTokens * $rates->cacheWrite
            + $usage->cacheReadTokens * $rates->cacheRead;

        if ($batched) {
            $weighted = intdiv($weighted * $this->batchPercent, 100);
        }

        return intdiv($weighted + self::TOKENS_PER_RATE - 1, self::TOKENS_PER_RATE);
    }
}
