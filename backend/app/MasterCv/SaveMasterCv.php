<?php

namespace App\MasterCv;

use App\Models\MasterCv;
use App\Observability\EventLogger;

/**
 * Writes the master CV its owner typed, and says what it makes of it.
 *
 * The only writer of the stored CV, and the only place that knows there is
 * exactly one of them.
 *
 * **Saving is free and unversioned.** It writes no `master_cv_versions` row:
 * a version is what a generation used, and a save that created one would put
 * a row behind every keystroke's worth of second thoughts.
 *
 * **A CV that fails lint still saves.** It is a draft, and refusing to store a
 * CV with a problem in it would make that problem unfixable. The gate stays
 * where it has always been — generation refuses a CV that does not lint.
 */
class SaveMasterCv
{
    public function __construct(
        private readonly InspectMasterCv $inspect,
        private readonly EventLogger $log,
    ) {}

    public function handle(string $markdown): MasterCvReport
    {
        // One owner, one CV, one row: a save replaces what is stored rather
        // than adding beside it.
        $cv = MasterCv::query()->firstOrNew([]);
        $cv->markdown = $markdown;
        $cv->save();

        $report = $this->inspect->of($cv);

        // The size and the verdict, never the content. The master CV is the
        // most personal thing this application holds.
        $this->log->log('master_cv.saved', [
            'characters' => mb_strlen($markdown),
            'passes_lint' => $report->passes(),
        ]);

        return $report;
    }
}
