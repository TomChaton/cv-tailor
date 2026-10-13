<?php

namespace Tests\Feature\Validation;

use App\Validation\Check;
use App\Validation\Checks\RewordingsCarrySupport;
use App\Validation\Severity;

class RewordingsCarrySupportTest extends CheckTestCase
{
    public function test_a_rewording_that_cites_its_sources_passes(): void
    {
        $this->assertPasses($this->selection());
    }

    public function test_a_rewording_with_no_support_fails(): void
    {
        $findings = $this->assertFails($this->selectionWithItems('harlequin-transit', [
            $this->reworded('harlequin-transit.4', 'Designed and built the dispatch API.', []),
        ]));

        $this->assertSame(Severity::Blocking, $findings[0]->severity);
        $this->assertSame('roles[0].items[0].supports', $findings[0]->field);
        $this->assertSame(['harlequin-transit.4'], $findings[0]->ids);
    }

    public function test_a_verbatim_item_needs_no_support(): void
    {
        $this->assertPasses($this->selectionWithItems('harlequin-transit', [
            $this->verbatim('harlequin-transit.4'),
        ]));
    }

    public function test_a_support_id_the_master_cv_does_not_hold_fails(): void
    {
        $findings = $this->assertFails($this->selectionWithItems('harlequin-transit', [
            $this->reworded('harlequin-transit.4', 'Designed and built the dispatch API.', ['invented']),
        ]));

        $this->assertSame('roles[0].items[0].supports[0]', $findings[0]->field);
    }

    public function test_a_support_id_marked_todo_fails(): void
    {
        $findings = $this->assertFails($this->selectionWithItems('harlequin-transit', [
            $this->reworded('harlequin-transit.4', 'Designed and built the dispatch API.', ['larkspur-media.2']),
        ]));

        $this->assertStringContainsString('TODO', $findings[0]->message);
    }

    /**
     * There is no verbatim form of a summary: the master CV has no summary for
     * one to repeat, so every summary is free text and every summary cites.
     */
    public function test_a_summary_with_no_support_fails(): void
    {
        $findings = $this->assertFails($this->selectionWithSummary('A summary of nothing in particular.', []));

        $this->assertSame('summary.supports', $findings[0]->field);
    }

    public function test_a_summary_citing_something_that_is_not_there_fails(): void
    {
        $this->assertFails($this->selectionWithSummary('A summary.', ['kubernetes-at-scale']));
    }

    protected function check(): Check
    {
        return $this->app->make(RewordingsCarrySupport::class);
    }
}
