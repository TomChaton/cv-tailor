<?php

namespace Tests\Feature\Validation;

use App\Generation\WordBudget as Budget;
use App\Validation\Check;
use App\Validation\Checks\WordBudget;
use App\Validation\Severity;

class WordBudgetTest extends CheckTestCase
{
    /**
     * The fixture's policy asks for two pages.
     */
    public function test_a_selection_inside_the_target_passes(): void
    {
        $this->assertPasses($this->selection());
    }

    public function test_a_selection_over_the_target_is_advisory(): void
    {
        $findings = $this->assertFails($this->selectionWithItems('harlequin-transit', [
            $this->reworded(
                'harlequin-transit.4',
                $this->words(Budget::forPages(2) + 1),
                ['harlequin-transit.4'],
            ),
        ]));

        $this->assertSame(Severity::Advisory, $findings[0]->severity);
        $this->assertStringContainsString((string) Budget::forPages(2), $findings[0]->message);
    }

    /**
     * An omitted role is not on the page, so nothing selected under it is
     * spending the budget.
     */
    public function test_items_under_an_omitted_role_cost_nothing(): void
    {
        $selection = $this->selectionWithItems('larkspur-media', [
            $this->reworded(
                'larkspur-media.1',
                $this->words(Budget::forPages(2) + 1),
                ['larkspur-media.1'],
            ),
        ]);

        $this->assertPasses($selection);
    }

    /**
     * A verbatim item puts the master CV's own sentence on the page, so that
     * is the sentence the budget pays for. A count that read the output's null
     * `text` as no words would report every verbatim CV as empty.
     */
    public function test_a_verbatim_item_is_counted_from_the_master_cv(): void
    {
        // A bullet the master CV writes in twenty-two words, and the output in
        // none at all.
        $verbatim = array_fill(0, 50, $this->verbatim('harlequin-transit.4.1'));
        $empty = array_fill(0, 50, $this->reworded('harlequin-transit.4.1', '', ['harlequin-transit.4.1']));

        $this->assertPasses($this->selection([
            'roles' => [$this->role('harlequin-transit', 'full', $empty)],
            'independent_projects' => [],
        ]));

        $this->assertFails($this->selection([
            'roles' => [$this->role('harlequin-transit', 'full', $verbatim)],
            'independent_projects' => [],
        ]));
    }

    private function words(int $count): string
    {
        return trim(str_repeat('word ', $count));
    }

    protected function check(): Check
    {
        return $this->app->make(WordBudget::class);
    }
}
