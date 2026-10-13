<?php

namespace Database\Factories;

use App\Batching\LlmBatchState;
use App\Models\LlmBatch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LlmBatch>
 */
class LlmBatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // No test reaches a live provider, so this names no real batch.
            'provider_batch_id' => 'msgbatch_test_'.$this->faker->unique()->numerify('######'),
            'state' => LlmBatchState::Submitted,
            'submitted_at' => CarbonImmutable::now(),
            'ended_at' => null,
            'request_count' => 1,
        ];
    }

    /**
     * Not called `state`: that is the factory API's own method for applying
     * an attribute set, and `state` is also this table's column.
     */
    public function inState(LlmBatchState $state): static
    {
        return $this->state(fn (): array => [
            'state' => $state,
            'ended_at' => $state === LlmBatchState::Submitted ? null : CarbonImmutable::now(),
        ]);
    }
}
