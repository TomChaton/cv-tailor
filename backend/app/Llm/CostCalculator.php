<?php

namespace App\Llm;

/**
 * What a response cost, in integer micros.
 *
 * Micros — millionths of a currency unit — match the `cost_micros` column, and
 * money is never a float. Rates are divided out at the end and rounded up, so
 * a cost is never reported as less than it was.
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

    public function micros(string $model, TokenUsage $usage, bool $batched): int
    {
        $rates = $this->rates[$model] ?? throw UnknownModel::forRates($model);

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
