<?php

namespace Tests\Feature\Validation;

use App\Validation\Check;
use App\Validation\Checks\IndependentProjectsStayIndependent;
use App\Validation\Severity;

class IndependentProjectsStayIndependentTest extends CheckTestCase
{
    public function test_work_filed_where_it_belongs_passes(): void
    {
        $this->assertPasses($this->selection());
    }

    /**
     * A project's bullet under an employer claims a client paid for it.
     */
    public function test_a_projects_item_under_an_employer_fails(): void
    {
        $findings = $this->assertFails($this->selectionWithItems('harlequin-transit', [
            $this->verbatim('fathom-index.1'),
        ]));

        $this->assertSame(Severity::Blocking, $findings[0]->severity);
        $this->assertSame(['fathom-index.1'], $findings[0]->ids);
        $this->assertStringContainsString('Fathom Index', $findings[0]->message);
    }

    public function test_an_employers_item_under_a_project_fails(): void
    {
        $this->assertFails($this->selectionWithItems('fathom-index', [
            $this->verbatim('harlequin-transit.4'),
        ], 'independent_projects'));
    }

    /**
     * A rewording under an employer that draws on an independent project is
     * the same transfer by a longer route.
     */
    public function test_a_rewording_supported_by_a_project_under_an_employer_fails(): void
    {
        $findings = $this->assertFails($this->selectionWithItems('harlequin-transit', [
            $this->reworded(
                'harlequin-transit.4',
                'Designed and built a document index.',
                ['fathom-index.1'],
            ),
        ]));

        $this->assertSame('roles[0].items[0].supports[0]', $findings[0]->field);
    }

    public function test_a_project_returned_as_an_employer_fails(): void
    {
        $findings = $this->assertFails(
            $this->selectionWithRoleAt(3, $this->role('quayside-charts', 'omitted', [])),
        );

        $this->assertSame('roles[3].id', $findings[0]->field);
    }

    public function test_an_employer_returned_as_a_project_fails(): void
    {
        $this->assertFails($this->selectionWithRoleAt(
            1,
            $this->role('larkspur-media', 'omitted', []),
            'independent_projects',
        ));
    }

    /**
     * The skills index is a property of the document rather than of an
     * employer, so a skill cited as support belongs to neither side.
     */
    public function test_a_skill_cited_as_support_belongs_to_no_role(): void
    {
        $this->assertPasses($this->selectionWithItems('harlequin-transit', [
            $this->reworded('harlequin-transit.4', 'Designed and built the dispatch API.', ['sql']),
        ]));
    }

    protected function check(): Check
    {
        return $this->app->make(IndependentProjectsStayIndependent::class);
    }
}
