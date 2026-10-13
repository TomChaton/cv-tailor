<?php

namespace App\Models;

use App\Postings\PostingStatus;
use Database\Factories\PostingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A captured job posting.
 *
 * It holds the raw material of the capture and nothing worked out from it, so
 * better extraction can be re-run over postings captured long ago.
 *
 * @property-read int $id
 * @property-read string $url
 * @property-read string $source_site
 * @property-read string|null $title
 * @property-read string|null $company
 * @property-read string|null $location
 * @property-read string|null $salary_text
 * @property-read string $extracted_text
 * @property-read string $dedupe_hash
 * @property-read PostingStatus $status
 */
#[Fillable([
    'url',
    'source_site',
    'title',
    'company',
    'location',
    'salary_text',
    'raw_html',
    'extracted_text',
    'has_hidden_text',
    'dedupe_hash',
    'status',
    'current_revision_id',
    'applied_revision_id',
])]
class Posting extends Model
{
    /** @use HasFactory<PostingFactory> */
    use HasFactory;

    /**
     * @return HasMany<Revision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(Revision::class);
    }

    /**
     * The revision the owner is looking at.
     *
     * @return BelongsTo<Revision, $this>
     */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(Revision::class, 'current_revision_id');
    }

    /**
     * The revision that actually went to the employer, which is the one that
     * matters once an application is live.
     *
     * @return BelongsTo<Revision, $this>
     */
    public function appliedRevision(): BelongsTo
    {
        return $this->belongsTo(Revision::class, 'applied_revision_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'has_hidden_text' => 'boolean',
            'status' => PostingStatus::class,
        ];
    }
}
