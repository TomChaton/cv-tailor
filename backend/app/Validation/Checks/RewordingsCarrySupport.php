<?php

namespace App\Validation\Checks;

use App\Validation\Check;
use App\Validation\Finding;
use App\Validation\FindingMessages;
use App\Validation\Output\IdReference;
use App\Validation\ValidationContext;

/**
 * Free text cites what it draws on, and what it cites is in the master CV.
 *
 * A rewording and the summary are the only places where words that are not the
 * author's reach the page. Without a citation there is nothing to check them
 * against, by this check or by the faithfulness check that reads them later —
 * so an uncited rewording is not a weak claim, it is an unfalsifiable one.
 *
 * The summary always needs support: the master CV has no summary for it to
 * repeat, so every summary is free text.
 *
 * This check owns the IDs that appear in a `supports` list, so no other check
 * reports them missing.
 */
class RewordingsCarrySupport implements Check
{
    public function __construct(private readonly FindingMessages $messages) {}

    public function name(): string
    {
        return 'rewordings_carry_support';
    }

    public function run(ValidationContext $context): array
    {
        $findings = [];
        $summary = $context->selection->summary;

        if ($summary !== null) {
            if ($summary->supports === []) {
                $findings[] = Finding::blocking(
                    $this->name(),
                    'summary.supports',
                    [],
                    $this->messages->get('rewordings_carry_support.summary_without_support'),
                );
            }

            $findings = [...$findings, ...$this->unresolved($context, $summary->supports)];
        }

        foreach ($context->selection->items() as [, $item]) {
            if (! $item->isRewording()) {
                // A verbatim item is the master CV's own sentence. There is
                // nothing for it to cite but itself.
                continue;
            }

            if ($item->supports === []) {
                $findings[] = Finding::blocking(
                    $this->name(),
                    $item->field.'.supports',
                    $item->id === null ? [] : [$item->id],
                    $this->messages->get('rewordings_carry_support.item_without_support', [
                        'id' => $item->id ?? '',
                    ]),
                );
            }

            $findings = [...$findings, ...$this->unresolved($context, $item->supports)];
        }

        return $findings;
    }

    /**
     * @param  list<IdReference>  $supports
     * @return list<Finding>
     */
    private function unresolved(ValidationContext $context, array $supports): array
    {
        $findings = [];

        foreach ($supports as $reference) {
            if ($context->index->resolves($reference->id)) {
                continue;
            }

            $key = $context->index->isUnknown($reference->id) ? 'unknown_support' : 'missing_support';

            $findings[] = Finding::blocking(
                $this->name(),
                $reference->field,
                [$reference->id],
                $this->messages->get('rewordings_carry_support.'.$key, ['id' => $reference->id]),
            );
        }

        return $findings;
    }
}
