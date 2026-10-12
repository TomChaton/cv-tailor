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
 * The file on disk is the editing surface; this is what was used. Rows are
 * deduplicated by `hash`, so an unchanged CV is not stored twice.
 *
 * @property-read int $id
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
