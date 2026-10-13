<?php

namespace Tests\Feature\Validation;

use App\Validation\Check;
use App\Validation\Checks\IdsResolve;
use App\Validation\Severity;

class IdsResolveTest extends CheckTestCase
{
    public function test_a_selection_of_real_content_passes(): void
    {
        $this->assertPasses($this->selection());
    }

    public function test_an_id_the_master_cv_does_not_hold_fails(): void
    {
        $findings = $this->assertFails($this->selectionWithItems('harlequin-transit', [
            $this->verbatim('harlequin-transit.99'),
        ]));

        $this->assertSame(Severity::Blocking, $findings[0]->severity);
        $this->assertSame(['harlequin-transit.99'], $findings[0]->ids);
        $this->assertSame('roles[0].items[0].id', $findings[0]->field);
    }

    /**
     * The author said the fact is unknown, so an output that names it has
     * filled in a blank rather than cited a fact.
     */
    public function test_content_marked_todo_fails(): void
    {
        $findings = $this->assertFails($this->selectionWithItems('larkspur-media', [
            $this->verbatim('larkspur-media.2'),
        ]));

        $this->assertStringContainsString('TODO', $findings[0]->message);
    }

    public function test_a_key_skill_that_names_no_row_fails(): void
    {
        $findings = $this->assertFails($this->selection(['key_skills' => ['kubernetes']]));

        $this->assertSame('key_skills[0]', $findings[0]->field);
    }

    public function test_evidence_for_a_requirement_is_checked(): void
    {
        $findings = $this->assertFails($this->selection([
            'jd_analysis' => [$this->requirement('Kubernetes', 'met', ['nothing-here'])],
        ]));

        $this->assertSame('jd_analysis[0].evidence[0]', $findings[0]->field);
    }

    public function test_a_promoted_role_that_names_nothing_is_checked(): void
    {
        $findings = $this->assertFails($this->selection([
            'review_notes' => $this->reviewNotes([[
                'id' => 'no-such-role',
                'from' => 'omitted',
                'to' => 'condensed',
                'reason' => 'The job asks for it.',
            ]]),
        ]));

        $this->assertSame('review_notes.promotions[0].id', $findings[0]->field);
    }

    /**
     * The IDs a rewording cites belong to the rewording check, which has more
     * to say about them. Two findings in two vocabularies for one defect tell
     * the owner nothing twice.
     */
    public function test_it_leaves_support_ids_to_the_rewording_check(): void
    {
        $this->assertPasses($this->selectionWithItems('harlequin-transit', [
            $this->reworded('harlequin-transit.4', 'Designed and built the dispatch API.', ['invented']),
        ]));
    }

    protected function check(): Check
    {
        return $this->app->make(IdsResolve::class);
    }
}
