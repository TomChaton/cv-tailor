<?php

namespace App\Rendering;

use App\Models\Revision;
use App\Observability\EventLogger;

/**
 * Rendering's log lines, in one place.
 *
 * Event names are part of the interface, so they live here rather than at their
 * call sites and are not renamed casually.
 *
 * **Counts and sizes, never content.** A resolved document is the CV itself, so
 * none of it appears in a line: what a reader needs is whether the right amount
 * of it came through. A document that lost half its roles, or a PDF that came
 * back one page where three were expected, is visible in these numbers and
 * nowhere else.
 */
final readonly class RenderingEvents
{
    public function __construct(private EventLogger $log) {}

    public function resolved(Revision $revision, ResolvedDocument $document): void
    {
        $this->log->log('revision.resolved', [
            'revision_id' => $revision->id,
            'posting_id' => $revision->posting_id,
            'master_cv_version_id' => $revision->master_cv_version_id,
            ...$document->counts(),
        ]);
    }

    public function rendered(Revision $revision, string $path, int $bytes, ?int $pages): void
    {
        $this->log->log('revision.rendered', [
            'revision_id' => $revision->id,
            'posting_id' => $revision->posting_id,
            'pdf_path' => $path,
            'bytes' => $bytes,
            'pages' => $pages,
        ]);
    }

    /**
     * A warning: a revision that was produced and cannot be sent is something
     * somebody has to look at.
     *
     * `checks` is always present, empty when the refusal was not validation's.
     * A key that appears only sometimes is a key a grep cannot rely on.
     *
     * @param  string  $reason  A fixed phrase. Never a quote from the CV or
     *                          from a finding.
     * @param  list<string>  $checks
     */
    public function failed(Revision $revision, string $reason, array $checks = []): void
    {
        $this->log->warning('revision.render_failed', [
            'revision_id' => $revision->id,
            'posting_id' => $revision->posting_id,
            'reason' => $reason,
            'checks' => $checks,
        ]);
    }
}
