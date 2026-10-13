<?php

namespace App\MasterCv;

use App\MasterCv\Ids\AssignMasterCvIds;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;

/**
 * Reads the master CV and says what the application makes of it.
 *
 * `LoadMasterCvVersion` asks the same questions in order to refuse: generation
 * either gets a usable CV or stops. This answers them in order to report, so a
 * CV that cannot be read or does not pass lint is an outcome rather than an
 * exception — the author is the one who has to see it.
 *
 * It applies the same gate: a CV that fails lint never becomes a version, so
 * no revision can reference one. Reporting on a file is never a reason to
 * record it as something a CV could be generated from.
 */
class InspectMasterCv
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly ReadMasterCv $read,
        private readonly StoreMasterCvVersion $store,
        private readonly AssignMasterCvIds $assign,
    ) {}

    public function handle(?string $path = null): MasterCvReport
    {
        $path = $this->read->path($path);
        $state = MasterCvState::at($this->files, $path);

        if ($state !== MasterCvState::Readable) {
            return MasterCvReport::unreadable($path, $state);
        }

        [$raw, $parsed, $lint] = $this->read->handle($path);

        if ($lint->passes()) {
            $this->store->handle($raw, $parsed);
        } else {
            $this->read->logFailure($path, $lint);
        }

        return MasterCvReport::of(
            $path,
            // Laravel sets PHP's default timezone from the application's.
            // Naming it is what makes a formatted time say which zone it is
            // in, rather than printing a bare offset.
            CarbonImmutable::createFromTimestamp(
                $this->files->lastModified($path),
                date_default_timezone_get(),
            ),
            $parsed,
            $lint,
            // Run whether or not anything is missing: how many IDs a CV wants
            // adding is only knowable by working out what they would be, and
            // the answer is what the download offers.
            $this->assign->handle($raw),
        );
    }
}
