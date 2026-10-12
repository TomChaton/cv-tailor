<?php

namespace App\Llm;

use InvalidArgumentException;

/**
 * One model's prices, in micros per million tokens.
 *
 * Per million rather than per token: a per-token price is a fraction of a
 * micro, and the point of micros is that money stays an integer.
 */
final readonly class CostRates
{
    public function __construct(
        public int $input,
        public int $output,
        public int $cacheWrite,
        public int $cacheRead,
    ) {}

    /**
     * @param  array<array-key, mixed>  $rates
     */
    public static function from(string $model, array $rates): self
    {
        return new self(
            input: self::integer($model, $rates, 'input'),
            output: self::integer($model, $rates, 'output'),
            cacheWrite: self::integer($model, $rates, 'cache_write'),
            cacheRead: self::integer($model, $rates, 'cache_read'),
        );
    }

    /**
     * @param  array<array-key, mixed>  $rates
     */
    private static function integer(string $model, array $rates, string $key): int
    {
        $value = $rates[$key] ?? null;

        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException(sprintf(
                'The %s rate for %s must be a non-negative integer number of micros per million tokens.',
                $key,
                $model,
            ));
        }

        return $value;
    }
}
