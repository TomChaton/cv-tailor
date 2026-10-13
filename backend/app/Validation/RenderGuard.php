<?php

namespace App\Validation;

use App\Models\Revision;
use App\Revisions\RevisionStatus;

/**
 * The one gate between a revision and a PDF.
 *
 * A code path that reaches a PDF without passing through here is a bug: the
 * whole guarantee is that nothing an employer reads claims something the
 * master CV does not support, and a renderer that trusts its input has given
 * that away.
 *
 * It refuses a revision that has never been validated as firmly as one that
 * failed. A null `validation_json` is not an empty one, and "nobody checked"
 * is not a reason to render.
 */
class RenderGuard
{
    /**
     * Statuses a revision may be rendered from: one that validation has run
     * on, and one that has already been rendered.
     */
    private const RENDERABLE = [RevisionStatus::Validated, RevisionStatus::Ready];

    public function allows(Revision $revision): bool
    {
        $report = ValidationReport::fromStored($revision->validation_json);

        return $report !== null
            && $report->passes()
            && in_array($revision->status, self::RENDERABLE, true);
    }

    public function ensure(Revision $revision): void
    {
        $report = ValidationReport::fromStored($revision->validation_json);

        if ($report === null) {
            throw RevisionIsNotRenderable::neverValidated($revision);
        }

        if (! $report->passes()) {
            throw RevisionIsNotRenderable::blocked($revision, $report->blockingChecks());
        }

        if (! in_array($revision->status, self::RENDERABLE, true)) {
            throw RevisionIsNotRenderable::wrongStatus($revision);
        }
    }
}
