<?php

namespace App\Models;

use App\Batching\LlmBatchState;
use Carbon\CarbonImmutable;
use Database\Factories\LlmBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One batch of requests, as the provider holds it and as we track it.
 *
 * The table is `llm_batches` rather than `batches`: Laravel's database queue
 * owns `job_batches`, and a `batch_id` in a log line would be ambiguous. The
 * context key is `llm_batch_id` for the same reason.
 *
 * @property-read int $id
 * @property-read string $provider_batch_id
 * @property-read LlmBatchState $state
 * @property-read CarbonImmutable $submitted_at
 * @property-read ?CarbonImmutable $ended_at
 * @property-read int $request_count
 */
#[Fillable([
    'provider_batch_id',
    'state',
    'submitted_at',
    'ended_at',
    'request_count',
])]
class LlmBatch extends Model
{
    /** @use HasFactory<LlmBatchFactory> */
    use HasFactory;

    /**
     * The revisions this batch carried, one row per submission.
     *
     * @return HasMany<LlmBatchRevision, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(LlmBatchRevision::class);
    }

    /**
     * Batches that still owe us results, oldest first.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('state', LlmBatchState::open())->oldest('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => LlmBatchState::class,
            'submitted_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }
}
