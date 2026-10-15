<?php

namespace App\Models;

use App\Revisions\RevisionObserver;
use App\Revisions\RevisionSource;
use App\Revisions\RevisionStatus;
use Database\Factories\RevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One tailored CV for a posting.
 *
 * Revisions form a tree, not a list: an edit or a regeneration is a child of
 * the revision it started from, so the history of a posting is navigable and
 * any two revisions can be diffed.
 *
 * The observer holds a complete revision immutable. No schema can express
 * that, since it is a rule about the value a column is moving away from.
 *
 * `model` holds whichever of the two model names is true of the revision now:
 * the model its request asked for while it is waiting for a result, because
 * that is the name the result is priced from, and the model the provider
 * reported once the output is stored, because that is the record of what
 * actually ran.
 *
 * @property-read int $id
 * @property-read int $posting_id
 * @property-read int $master_cv_version_id
 * @property-read string $model
 * @property-read string $prompt_version
 * @property-read RevisionStatus $status
 * @property-read int $attempts
 * @property-read ?array<string, mixed> $output_json
 * @property-read ?array<array-key, mixed> $validation_json
 * @property-read ?string $pdf_path
 */
#[ObservedBy([RevisionObserver::class])]
#[Fillable([
    'posting_id',
    'parent_revision_id',
    'master_cv_version_id',
    'source',
    'instructions',
    'model',
    'prompt_version',
    'input_tokens',
    'output_tokens',
    'cache_read_tokens',
    'cache_write_tokens',
    'cost_micros',
    'output_json',
    'resolved_json',
    'validation_json',
    'pdf_path',
    'status',
    'attempts',
    'error',
])]
class Revision extends Model
{
    /** @use HasFactory<RevisionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Posting, $this>
     */
    public function posting(): BelongsTo
    {
        return $this->belongsTo(Posting::class);
    }

    /**
     * @return BelongsTo<MasterCvVersion, $this>
     */
    public function masterCvVersion(): BelongsTo
    {
        return $this->belongsTo(MasterCvVersion::class);
    }

    /**
     * The revision this one started from.
     *
     * @return BelongsTo<Revision, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_revision_id');
    }

    /**
     * @return HasMany<Revision, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_revision_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => RevisionSource::class,
            'status' => RevisionStatus::class,
            'output_json' => 'array',
            'resolved_json' => 'array',
            'validation_json' => 'array',
        ];
    }
}
