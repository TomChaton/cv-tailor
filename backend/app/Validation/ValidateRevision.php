<?php

namespace App\Validation;

use App\MasterCv\MasterCvParser;
use App\Models\Revision;
use App\Revisions\RevisionStatus;

/**
 * Validates one revision and records what was found.
 *
 * **`validated` means validation has run, not that it passed.** The findings
 * say whether it passed. A revision with a blocking finding stays `validated`
 * and is never rendered; it is not `failed`, because `failed` is for a
 * revision that could not be produced, not one produced wrongly. The owner
 * regenerates from it with instructions, which needs the revision and its
 * findings intact.
 *
 * A revision that passed cleanly stores an empty list of findings, never null.
 * "Validated with no findings" and "never validated" have to be
 * distinguishable, or a revision nobody checked would render like one that
 * passed.
 */
class ValidateRevision
{
    public function __construct(
        private readonly MasterCvParser $parser,
        private readonly Validator $validator,
        private readonly ValidationEvents $events,
    ) {}

    public function handle(Revision $revision): Revision
    {
        $output = $revision->output_json;

        if (! is_array($output) || $output === []) {
            throw RevisionCannotBeValidated::withoutOutput($revision);
        }

        $version = $revision->masterCvVersion()->firstOrFail();

        // The parse is re-derived from the stored raw content rather than
        // hydrated out of `parsed_json`, for the same reason the request
        // builder does it: a hydrator would be a second reading of the master
        // CV format, kept in step with the parser by hand.
        $report = $this->validator->validate($this->parser->parse($version->raw_content), $output);

        $revision->fill([
            'validation_json' => $report->toArray(),
            'status' => RevisionStatus::Validated,
        ])->save();

        $this->events->validated($revision, $report);

        if (! $report->passes()) {
            $this->events->blocked($revision, $report);
        }

        return $revision;
    }
}
