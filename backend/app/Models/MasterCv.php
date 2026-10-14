<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The master CV as its owner is editing it.
 *
 * This is the editing surface; `MasterCvVersion` is what a generation used.
 * The two are separate so that saving can be free and unversioned while a
 * revision's link to the text that produced it stays immutable — a save that
 * created a version could not be both.
 *
 * One owner, one CV, one row. `App\MasterCv\SaveMasterCv` is the only writer,
 * and it replaces what is stored rather than adding beside it.
 *
 * @property-read int $id
 * @property string $markdown
 * @property-read CarbonImmutable $updated_at
 */
#[Fillable(['markdown'])]
class MasterCv extends Model
{
    /**
     * Laravel would look for `master_cvs`. See the migration for why the
     * table is singular.
     */
    protected $table = 'master_cv';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'updated_at' => 'immutable_datetime',
        ];
    }
}
