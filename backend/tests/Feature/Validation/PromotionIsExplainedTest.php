<?php

namespace Tests\Feature\Validation;

use App\Validation\Check;
use App\Validation\Checks\PromotionIsExplained;
use App\Validation\Severity;

class PromotionIsExplainedTest extends CheckTestCase
{
    public function test_a_selection_that_promotes_nothing_passes(): void
    {
        $this->assertPasses($this->selection());
    }

    public function test_a_promotion_with_a_reason_passes(): void
    {
        $selection = $this->selectionWithMode('larkspur-media', 'condensed');
        $selection['review_notes'] = $this->reviewNotes([[
            'id' => 'larkspur-media',
            'from' => 'omitted',
            'to' => 'condensed',
            'reason' => 'The job asks for classified advertising.',
        ]]);

        $this->assertPasses($selection);
    }

    /**
     * Advisory: the promotion itself is the judgement the model was asked to
     * make, and nothing in the CV is a false claim because the reason is
     * missing.
     */
    public function test_a_promotion_with_no_reason_is_advisory(): void
    {
        $findings = $this->assertFails($this->selectionWithMode('larkspur-media', 'full'));

        $this->assertSame(Severity::Advisory, $findings[0]->severity);
        $this->assertSame(['larkspur-media'], $findings[0]->ids);
        $this->assertStringContainsString('Larkspur Media', $findings[0]->message);
    }

    public function test_a_demotion_is_not_this_checks_business(): void
    {
        $this->assertPasses($this->selectionWithMode('pelham-civic-data', 'omitted'));
    }

    protected function check(): Check
    {
        return $this->app->make(PromotionIsExplained::class);
    }
}
