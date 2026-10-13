<?php

namespace App\Generation;

use App\MasterCv\ParsedMasterCv;

/**
 * The second system block: the master CV itself.
 *
 * It is built from `ParsedMasterCv::withoutIdentity()` and only ever removes
 * keys from it. That is deliberate: the contact section is reachable in one
 * place in the parse, and a block assembled field by field would be a second
 * place for it to leak from. Nothing here adds to what that method returns.
 *
 * The rules and "Do not claim" come first and word for word, because they are
 * rules and read as rules. The facts follow as JSON, which is the form that
 * keeps every ID beside the content it addresses without a second rendering of
 * the format to maintain.
 *
 * This is the large stable prefix of every generation request for a given CV
 * version, so it is the block the cache breakpoint goes on.
 */
final readonly class MasterCvPrompt
{
    /**
     * Keys taken out of the facts.
     *
     * The two rule blocks are above the facts, verbatim. The presentation
     * policy belongs to the run rather than to the CV, so the brief states it
     * and states it once. The list of sections the parser recognised is
     * bookkeeping about the file: the facts themselves say which of them the
     * CV has.
     */
    private const NOT_FACTS = ['agent_rules', 'do_not_claim', 'presentation', 'sections_present'];

    public function text(ParsedMasterCv $cv): string
    {
        $facts = $cv->withoutIdentity();

        foreach (self::NOT_FACTS as $key) {
            unset($facts[$key]);
        }

        return implode("\n\n", [
            'The master CV follows. Nothing outside it is a fact about this person.',
            '## Rules from the master CV',
            trim($cv->agentRules->raw),
            '## Do not claim',
            trim($cv->doNotClaim->raw),
            '## Facts',
            "```json\n".$this->json($facts)."\n```",
        ]);
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function json(array $facts): string
    {
        return json_encode(
            $facts,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
