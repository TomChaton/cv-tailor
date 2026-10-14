<?php

namespace App\MasterCv;

use App\MasterCv\Ids\AssignMasterCvIds;
use App\Models\MasterCv;

/**
 * Says what the application makes of the stored master CV.
 *
 * `LoadMasterCvVersion` asks the same questions in order to refuse:
 * generation either gets a usable CV or stops. This answers them in order to
 * report, so a CV that has never been saved, or does not pass lint, is an
 * outcome rather than an exception — the author is the one who has to see it.
 *
 * **It records nothing.** A `master_cv_versions` row is what a generation made
 * of the CV, and only a generation may write one: looking at the CV, or
 * saving it, must leave behind nothing a revision could claim it was built
 * from.
 */
class InspectMasterCv
{
    public function __construct(
        private readonly ReadMasterCv $read,
        private readonly AssignMasterCvIds $assign,
    ) {}

    public function handle(): MasterCvReport
    {
        $stored = $this->read->stored();

        return $stored === null ? MasterCvReport::none() : $this->of($stored);
    }

    public function of(MasterCv $cv): MasterCvReport
    {
        [$parsed, $lint] = $this->read->parse($cv->markdown);

        if (! $lint->passes()) {
            $this->read->logFailure($lint);
        }

        return MasterCvReport::of(
            $cv->markdown,
            $cv->updated_at,
            $parsed,
            $lint,
            // Run whether or not anything is missing: how many IDs a CV wants
            // adding is only knowable by working out what they would be, and
            // the answer is what the download offers.
            $this->assign->handle($cv->markdown),
        );
    }
}
