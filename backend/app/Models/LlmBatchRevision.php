<?php

namespace App\Models;

use App\Batching\SubmissionOutcome;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One revision's submission in one batch.
 *
 * The table follows Laravel's naming for a join table — singular, the two
 * sides in alphabetical order — but this is an ordinary model rather than a
 * pivot: the row has an identity, a lifecycle of its own in `outcome`, and is
 * written and claimed directly.
 *
 * @property-read int $id
 * @property-read int $llm_batch_id
 * @property-read int $revision_id
 * @property-read SubmissionOutcome $outcome
 */
#[Fillable(['llm_batch_id', 'revision_id', 'outcome'])]
#[Table('llm_batch_revision')]
class LlmBatchRevision extends Model
{
    /**
     * @return BelongsTo<LlmBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(LlmBatch::class, 'llm_batch_id');
    }

    /**
     * @return BelongsTo<Revision, $this>
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(Revision::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => SubmissionOutcome::class,
        ];
    }
}
