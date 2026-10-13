<?php

namespace App\Validation\Checks;

use App\Generation\WordBudget as Budget;
use App\Validation\Check;
use App\Validation\Finding;
use App\Validation\FindingMessages;
use App\Validation\ValidationContext;

/**
 * The CV is no longer than the presentation policy asks for.
 *
 * Advisory, and advisory is the whole point. A length is a target stated in
 * the prompt and never a limit: structured outputs cannot express one, and
 * truncating generated output would cut a claim in half and leave the half
 * that reads as finished. So an over-long CV is reported for the owner to
 * weigh, and a long CV that is true is better than a short one that is not.
 *
 * **It counts the words the selection chose**, not the words on the page. The
 * headings, the dates and the contact details are the same on every CV this
 * instance produces, so they are not what a budget is spent on, and the
 * selection is the only thing a regeneration can trade against it.
 */
class WordBudget implements Check
{
    public function __construct(private readonly FindingMessages $messages) {}

    public function name(): string
    {
        return 'word_budget';
    }

    public function run(ValidationContext $context): array
    {
        $budget = Budget::forPages($context->cv->presentation->targetPages);
        $words = $this->words($context);

        if ($words <= $budget) {
            return [];
        }

        return [Finding::advisory(
            $this->name(),
            null,
            [],
            $this->messages->get($this->name().'.over', [
                'words' => (string) $words,
                'budget' => (string) $budget,
            ]),
        )];
    }

    private function words(ValidationContext $context): int
    {
        $selection = $context->selection;
        $texts = [$selection->headline ?? '', $selection->summary->text ?? ''];

        foreach ($selection->keySkills as $skill) {
            $texts[] = $context->index->skill($skill->id)->name ?? '';
        }

        foreach ($selection->allRoles() as $role) {
            if (! $role->isShown()) {
                // An omitted role is not on the page, so its items cost
                // nothing.
                continue;
            }

            foreach ($role->items as $item) {
                // A verbatim item puts the master CV's own sentence on the
                // page, so that is the sentence to count.
                $texts[] = $item->text ?? ($item->id === null ? '' : $context->index->textOf($item->id) ?? '');
            }
        }

        $words = 0;

        foreach ($texts as $text) {
            $words += count(array_filter(
                preg_split('/\s+/u', trim($text)) ?: [],
                static fn (string $word): bool => $word !== '',
            ));
        }

        return $words;
    }
}
