<?php

namespace App\Rendering;

use App\MasterCv\MasterCvParser;
use App\Models\Revision;
use App\Postings\MarkPostingReady;
use App\Rendering\Html\CvHtml;
use App\Revisions\RevisionStatus;
use App\Validation\RenderGuard;
use App\Validation\RevisionIsNotRenderable;
use App\Validation\ValidationReport;

/**
 * Turns one validated revision into the PDF the owner sends, and takes it to
 * `ready`.
 *
 * **Nothing reaches a PDF without passing the render guard first.** A revision
 * with a blocking finding is refused rather than rendered, and so is one
 * nothing has ever checked: the whole guarantee is that nothing an employer
 * reads claims something the master CV does not support.
 *
 * Resolving is recorded before the conversion is attempted, so a converter that
 * is down leaves behind the document it would have rendered. The revision stays
 * `validated` in that case — it was produced correctly and only the conversion
 * did not happen — and `pdf_path` is left alone, because a path with no file
 * behind it is worse than no path at all.
 *
 * A rendered revision takes its posting with it, so the tracker the owner
 * reads does not understate what is finished.
 */
class RenderRevision
{
    public function __construct(
        private readonly RenderGuard $guard,
        private readonly MasterCvParser $parser,
        private readonly ResolveDocument $resolve,
        private readonly CvHtml $html,
        private readonly PdfConverter $converter,
        private readonly PdfStore $store,
        private readonly MarkPostingReady $posting,
        private readonly RenderingEvents $events,
    ) {}

    public function handle(Revision $revision): Revision
    {
        $this->allowed($revision);

        $output = $revision->output_json;

        if (! is_array($output) || $output === []) {
            $refusal = RevisionIsNotRenderable::withoutOutput($revision);
            $this->events->failed($revision, $refusal->reason);

            throw $refusal;
        }

        $version = $revision->masterCvVersion()->firstOrFail();

        // The parse is re-derived from the stored raw content rather than
        // hydrated out of `parsed_json`, for the same reason validation does
        // it: a hydrator would be a second reading of the master CV format,
        // kept in step with the parser by hand.
        $document = $this->resolve->resolve($this->parser->parse($version->raw_content), $output);

        // A revision already rendered is complete and immutable. Rendering it
        // again resolves to the same document and writes the PDF to the same
        // path, so nothing on the model is dirty and nothing is written: a
        // revision has one PDF, and re-rendering replaces it.
        $revision->fill(['resolved_json' => $document->toArray()])->save();

        $this->events->resolved($revision, $document);

        $pdf = $this->convert($revision, $document);
        $path = $this->store->put($revision, $pdf);

        $revision->fill([
            'pdf_path' => $path,
            'status' => RevisionStatus::Ready,
            'error' => null,
        ])->save();

        $this->events->rendered($revision, $path, strlen($pdf), PdfPageCount::of($pdf));

        // The posting follows from here rather than from the caller, for the
        // same reason validation is queued where the output is stored: the
        // step that puts a revision at `ready` is the step that knows the
        // posting's denormalised `ready` is now out of date, and every path
        // that renders then keeps the tracker in step without having to
        // remember to.
        $this->posting->handle($revision);

        return $revision;
    }

    private function allowed(Revision $revision): void
    {
        try {
            $this->guard->ensure($revision);
        } catch (RevisionIsNotRenderable $refusal) {
            $report = ValidationReport::fromStored($revision->validation_json);

            $this->events->failed($revision, $refusal->reason, $report?->blockingChecks() ?? []);

            throw $refusal;
        }
    }

    private function convert(Revision $revision, ResolvedDocument $document): string
    {
        try {
            return $this->converter->convert($this->html->for($document));
        } catch (PdfConversionFailed $failure) {
            $this->events->failed($revision, $failure->reason);

            // A revision that has already been rendered is immutable, and a
            // conversion that failed on the way to replacing its PDF has not
            // changed what the revision is.
            if (! $revision->status->isComplete()) {
                $revision->fill(['error' => $failure->getMessage()])->save();
            }

            throw $failure;
        }
    }
}
