<?php

namespace Tests\Feature\Validation;

use App\Validation\Check;
use App\Validation\Checks\TechnologyToRole;
use App\Validation\Severity;

class TechnologyToRoleTest extends CheckTestCase
{
    public function test_a_rewording_naming_the_roles_own_technology_passes(): void
    {
        $this->assertPasses($this->selection());
    }

    /**
     * The fabrication this check exists for: a technology moved from the role
     * that used it to the role the job description asks about. Nothing about
     * the sentence looks wrong.
     */
    public function test_a_technology_from_another_role_fails(): void
    {
        $findings = $this->assertFails($this->selectionWithItems('northgate-tickets', [
            $this->reworded(
                'northgate-tickets.6',
                'Containerised the application and took its estate into Terraform.',
                ['northgate-tickets.6'],
            ),
        ]));

        $this->assertSame(Severity::Blocking, $findings[0]->severity);
        $this->assertStringContainsString('Terraform', $findings[0]->message);
        $this->assertStringContainsString('Northgate Tickets', $findings[0]->message);
        $this->assertSame('roles[1].items[0].text', $findings[0]->field);
    }

    /**
     * A verbatim item is the author's own sentence about that role, so there
     * is nothing in it the role does not support.
     */
    public function test_a_verbatim_item_is_not_read(): void
    {
        $this->assertPasses($this->selectionWithItems('northgate-tickets', [
            $this->verbatim('northgate-tickets.6'),
        ]));
    }

    /**
     * The index writes "Slim Framework (Slim 4)" where the stack writes
     * "Slim 4". The two are the same technology, and a check that could not
     * see that would block the wording the CV itself uses.
     */
    public function test_the_index_and_the_stack_may_name_a_technology_differently(): void
    {
        $this->assertPasses($this->selectionWithItems('harlequin-transit', [
            $this->reworded(
                'harlequin-transit.4.1',
                'Replaced a nightly batch hand-off with a REST API on Slim Framework.',
                ['harlequin-transit.4.1'],
            ),
        ]));
    }

    public function test_a_technology_the_skills_index_has_never_heard_of_is_not_this_checks_business(): void
    {
        $this->assertPasses($this->selectionWithItems('northgate-tickets', [
            $this->reworded(
                'northgate-tickets.6',
                'Containerised the application for its deployment on Caddy.',
                ['northgate-tickets.6'],
            ),
        ]));
    }

    public function test_an_independent_project_is_held_to_its_own_stack(): void
    {
        $this->assertFails($this->selectionWithItems('fathom-index', [
            $this->reworded(
                'fathom-index.1',
                'Rust indexer writing an inverted index to PostgreSQL.',
                ['fathom-index.1'],
            ),
        ], 'independent_projects'));
    }

    protected function check(): Check
    {
        return $this->app->make(TechnologyToRole::class);
    }
}
