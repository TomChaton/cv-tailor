<?php

namespace App\Review;

use App\MasterCv\MasterCvParser;
use App\Models\Revision;
use App\Validation\MasterCvIndex;
use App\Validation\ValidationReport;

/**
 * Reads one revision for the screen the owner reviews it on.
 *
 * **Evidence resolves against the master CV version the revision points at,
 * never the current one.** A revision is pinned to a version; showing today's
 * wording beside an older revision would put words in front of the owner that
 * were never sent, which is the same class of mistake the whole system exists
 * to prevent.
 *
 * The parse is re-derived from the version's stored raw content rather than
 * hydrated out of `parsed_json`, for the same reason validation and rendering
 * do it: a hydrator would be a second reading of the master CV format, kept in
 * step with the parser by hand.
 *
 * A revision with no output has nothing to review and reads as empty rather
 * than raising. The screen is where a revision that produced nothing is looked
 * at, so it has to open.
 */
class ReadRevisionReview
{
    public function __construct(
        private readonly MasterCvParser $parser,
        private readonly RequirementsChecklist $checklist,
    ) {}

    public function handle(Revision $revision): RevisionReview
    {
        $version = $revision->masterCvVersion()->firstOrFail();
        $index = new MasterCvIndex($this->parser->parse($version->raw_content));

        $output = $revision->output_json;
        $output = is_array($output) ? $output : [];

        return new RevisionReview(
            requirements: $this->checklist->of($output, $index),
            findings: ValidationReport::fromStored($revision->validation_json),
            notes: ReviewNotes::from($output, $index),
        );
    }
}
