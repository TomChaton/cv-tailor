<?php

namespace App\Models;

use Database\Factories\MasterCvVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The parsed master CV a revision was generated from.
 *
 * `MasterCv` is the editing surface; this is what was used. The two are
 * separate so that saving can be free and unversioned while a revision's link
 * to the text that produced it stays immutable.
 *
 * Rows are deduplicated by `hash`, so an unchanged CV is not stored twice, and
 * a generation is the only thing that writes one.
 *
 * @property-read int $id
 * @property-read string $hash
 * @property-read string $raw_content
 */
#[Fillable(['hash', 'raw_content', 'parsed_json'])]
class MasterCvVersion extends Model
{
    /** @use HasFactory<MasterCvVersionFactory> */
    use HasFactory;

    /**
     * @return HasMany<Revision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(Revision::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parsed_json' => 'array',
        ];
    }
}
