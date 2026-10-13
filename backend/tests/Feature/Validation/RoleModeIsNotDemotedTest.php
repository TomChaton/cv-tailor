<?php

namespace Tests\Feature\Validation;

use App\Validation\Check;
use App\Validation\Checks\RoleModeIsNotDemoted;
use App\Validation\Severity;

class RoleModeIsNotDemotedTest extends CheckTestCase
{
    public function test_roles_left_at_their_defaults_pass(): void
    {
        $this->assertPasses($this->selection());
    }

    public function test_a_role_shown_in_less_detail_than_its_default_fails(): void
    {
        $findings = $this->assertFails($this->selectionWithMode('pelham-civic-data', 'omitted'));

        $this->assertSame(Severity::Blocking, $findings[0]->severity);
        $this->assertSame(['pelham-civic-data'], $findings[0]->ids);
        $this->assertStringContainsString('Pelham Civic Data', $findings[0]->message);
    }

    /**
     * The direction is not the model's to choose, so a reason does not buy it.
     */
    public function test_a_reason_does_not_excuse_a_demotion(): void
    {
        $selection = $this->selectionWithMode('harlequin-transit', 'condensed');
        $selection['review_notes'] = $this->reviewNotes([[
            'id' => 'harlequin-transit',
            'from' => 'full',
            'to' => 'condensed',
            'reason' => 'Space.',
        ]]);

        $this->assertFails($selection);
    }

    public function test_a_promotion_is_not_this_checks_business(): void
    {
        $this->assertPasses($this->selectionWithMode('larkspur-media', 'condensed'));
    }

    protected function check(): Check
    {
        return $this->app->make(RoleModeIsNotDemoted::class);
    }
}
