<?php

namespace App\Revisions;

/**
 * What produced a revision.
 *
 * A manual edit skips the LLM but passes through the same deterministic
 * validators, so it is a revision like any other and says so here.
 */
enum RevisionSource: string
{
    case Generated = 'generated';

    case Regenerated = 'regenerated';

    case ManualEdit = 'manual_edit';
}
