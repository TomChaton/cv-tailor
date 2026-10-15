<?php

namespace App\Models;

use App\Postings\PostingStatus;
use Database\Factories\PostingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A captured job posting.
 *
 * `page_text` and `json_ld` are the raw material of the capture, as the page
 * presented it. Everything else about what the posting says — its title, the
 * company, where the work is, what it pays, and the job description itself —
 * is extraction's answer, and `extracted_at` says whether it has been asked.
 *
 * `cover_letter_requested` is the owner's own: they asked for a cover letter
 * for this job. The postings list reads it and nothing in generation does.
 *
 * @property-read int $id
 * @property-read string $url
 * @property-read string $source_site
 * @property-read string|null $title
 * @property-read string|null $company
 * @property-read string|null $location
 * @property-read string|null $salary_text
 * @property-read string $page_text
 * @property-read array<string, mixed>|null $json_ld
 * @property-read string|null $description
 * @property-read Carbon|null $extracted_at
 * @property-read string $dedupe_hash
 * @property-read bool $has_hidden_text
 * @property-read bool $cover_letter_requested
 * @property-read PostingStatus $status
 * @property-read ?int $current_revision_id
 */
#[Fillable([
    'url',
    'source_site',
    'title',
    'company',
    'location',
    'salary_text',
    'page_text',
    'json_ld',
    'description',
    'extracted_at',
    'has_hidden_text',
    'cover_letter_requested',
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
            'cover_letter_requested' => 'boolean',
            'json_ld' => 'array',
            'extracted_at' => 'datetime',
            'status' => PostingStatus::class,
        ];
    }
}
