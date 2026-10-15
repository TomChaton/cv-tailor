<?php

namespace App\MasterCv\Fixes;

use App\MasterCv\Lint\FindingLevel;
use App\MasterCv\ParsedMasterCv;
use App\MasterCv\Skill;
use App\Observability\EventLogger;

/**
 * Answers a "Where used" finding: the document with that one row's cell
 * written from the roles and projects that were chosen.
 *
 * The answer is composed here rather than taken from what the browser sent:
 * the keys name choices, and the text each choice writes comes from the parse.
 * An answer the document does not hold therefore reaches the document through
 * nothing.
 *
 * It is offered against a note as readily as against a problem. The question
 * is the same one — which of this document's roles and projects does the row
 * name — and a note is that question with a sibling that happened to match.
 *
 * @see WhereUsedChoices for what may be said, and RewriteWhereUsed for the
 * proof that one row and only one row was written.
 */
class ApplyWhereUsedFix
{
    public function __construct(
        private readonly RewriteWhereUsed $rewrite,
        private readonly EventLogger $log,
    ) {}

    /**
     * @param  list<string>  $chosen  Keys of the choices `WhereUsedChoices`
     *                                offers, and nothing else.
     * @return string The document to store, which is the document given where
     *                the selection says what the row already said.
     *
     * @throws WhereUsedNotRewritten
     */
    public function handle(
        string $markdown,
        ParsedMasterCv $editing,
        Skill $skill,
        array $chosen,
        FindingLevel $level,
    ): string {
        $choices = WhereUsedChoices::of($editing);

        // A selection nobody changed is not a correction. Rewriting anyway
        // would reword the parts of the row that were right, which is harm
        // the finding never asked for.
        $rewritten = $choices->alreadySays($skill->whereUsed, $chosen)
            ? null
            : $this->rewrite->handle($markdown, $skill, $choices->cell($chosen));

        // The finding's level, so a fix applied to a note is distinguishable
        // from one applied to a problem. Not `level`: that name belongs to the
        // line's own severity, and the formatter drops a context key that
        // claims it. Counts and outcomes only, never the row or the names
        // chosen or anything else the document says.
        $this->log->log('master_cv.where_used_fixed', [
            'finding_level' => $level->value,
            'chosen' => count($chosen),
            'rewritten' => $rewritten !== null,
        ]);

        return $rewritten ?? $markdown;
    }
}
