<?php

namespace Tests\Feature\Validation;

use App\Validation\Check;
use App\Validation\Checks\ItemsBelongToTheirRole;
use App\Validation\Severity;

class ItemsBelongToTheirRoleTest extends CheckTestCase
{
    public function test_items_shown_under_the_role_they_came_from_pass(): void
    {
        $this->assertPasses($this->selection());
    }

    /**
     * The quietest fabrication in the system: every word is the author's, and
     * the CV says the work happened at an employer where it did not.
     */
    public function test_a_verbatim_bullet_from_another_employer_fails(): void
    {
        $findings = $this->assertFails($this->selectionWithItems('harlequin-transit', [
            $this->verbatim('northgate-tickets.1'),
        ]));

        $this->assertSame(Severity::Blocking, $findings[0]->severity);
        $this->assertSame(['northgate-tickets.1'], $findings[0]->ids);
        $this->assertSame('roles[0].items[0].id', $findings[0]->field);
        $this->assertStringContainsString('Northgate Tickets', $findings[0]->message);
        $this->assertStringContainsString('Harlequin Transit', $findings[0]->message);
    }

    public function test_a_rewording_from_another_employer_fails(): void
    {
        $this->assertFails($this->selectionWithItems('pelham-civic-data', [
            $this->reworded(
                'northgate-tickets.3',
                'Moved venue search off a table scan.',
                ['northgate-tickets.3'],
            ),
        ]));
    }

    /**
     * A sub-bullet is selectable in its own right, so a parent may be narrowed
     * to some of its children.
     */
    public function test_a_sub_bullet_of_the_same_role_passes(): void
    {
        $this->assertPasses($this->selectionWithItems('harlequin-transit', [
            $this->verbatim('harlequin-transit.4.1'),
            $this->verbatim('harlequin-transit.5.2'),
        ]));
    }

    public function test_a_sub_bullet_of_another_role_fails(): void
    {
        $this->assertFails($this->selectionWithItems('quayside-charts', [
            $this->verbatim('fathom-index.1.2'),
        ], 'independent_projects'));
    }

    /**
     * The schema's item enum holds bullets only, so this is a manual edit's to
     * make.
     */
    public function test_a_role_is_not_one_of_its_own_items(): void
    {
        $findings = $this->assertFails($this->selectionWithItems('harlequin-transit', [
            $this->verbatim('harlequin-transit'),
        ]));

        $this->assertSame(['harlequin-transit'], $findings[0]->ids);
    }

    public function test_an_id_the_master_cv_does_not_hold_is_left_to_the_id_check(): void
    {
        $this->assertPasses($this->selectionWithItems('harlequin-transit', [
            $this->verbatim('harlequin-transit.99'),
            $this->verbatim('nothing-at-all'),
        ]));
    }

    /**
     * Evidence legitimately spans the whole document, so the IDs a rewording
     * cites are not read here. The one crossing that is never legitimate is
     * the independence check's.
     */
    public function test_the_ids_a_rewording_cites_are_not_read(): void
    {
        $this->assertPasses($this->selectionWithItems('harlequin-transit', [
            $this->reworded(
                'harlequin-transit.4',
                'Designed and built the dispatch API.',
                ['northgate-tickets.1'],
            ),
        ]));
    }

    protected function check(): Check
    {
        return $this->app->make(ItemsBelongToTheirRole::class);
    }
}
