<?php

namespace Tests\Unit\MasterCv\Fixes;

use App\MasterCv\Fixes\WhereUsedChoices;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\MasterCvFixtures;

/**
 * What a "Where used" cell is allowed to say, and what the author already
 * said.
 *
 * The set on offer is the whole of the safety here: an answer that is not a
 * role or project in the document cannot be given, so no choice can put a
 * claim in the master CV that the master CV does not already make.
 */
class WhereUsedChoicesTest extends TestCase
{
    use MasterCvFixtures;

    public function test_the_choices_are_exactly_the_roles_and_projects_in_the_parse(): void
    {
        $cv = $this->parseFixture();

        $this->assertSame([
            'harlequin-transit' => 'Harlequin Transit',
            'northgate-tickets' => 'Northgate Tickets',
            'pelham-civic-data' => 'Pelham Civic Data',
            'larkspur-media' => 'Larkspur Media',
            'fathom-index' => 'Independent: Fathom Index',
            'quayside-charts' => 'Independent: Quayside Charts',
        ], WhereUsedChoices::of($cv)->all());
    }

    /**
     * The CV's own rules turn on the distinction, so a chosen project is
     * written the way a derived row writes one.
     */
    public function test_an_independent_project_is_written_as_one(): void
    {
        $choices = WhereUsedChoices::of($this->parseFixture());

        $this->assertSame('Independent: Fathom Index', $choices->cell(['fathom-index']));
        $this->assertSame('Harlequin Transit', $choices->cell(['harlequin-transit']));
    }

    public function test_a_cell_is_written_in_the_order_the_document_is_in(): void
    {
        $choices = WhereUsedChoices::of($this->parseFixture());

        $this->assertSame(
            'Harlequin Transit, Northgate Tickets, Independent: Fathom Index',
            $choices->cell(['fathom-index', 'northgate-tickets', 'harlequin-transit']),
        );
    }

    /**
     * The set on offer is the whole of what may be written. A key from
     * anywhere else reaches the document through nothing.
     */
    public function test_a_key_that_names_no_choice_writes_nothing(): void
    {
        $choices = WhereUsedChoices::of($this->parseFixture());

        $this->assertSame('', $choices->cell(['pentland-haulage']));
        $this->assertSame('Harlequin Transit', $choices->cell(['harlequin-transit', 'pentland-haulage']));
    }

    public function test_choosing_nothing_writes_an_empty_cell(): void
    {
        $this->assertSame('', WhereUsedChoices::of($this->parseFixture())->cell([]));
    }

    /**
     * Pre-selection is lint's own idea of what resolves, so what the modal
     * shows as already claimed is exactly what lint counts as evidence.
     */
    public function test_the_parts_of_a_cell_that_resolve_are_selected_and_the_rest_are_not(): void
    {
        $choices = WhereUsedChoices::of($this->parseFixture());

        $this->assertSame(
            ['harlequin-transit', 'northgate-tickets'],
            $choices->selected('Harlequin Transit (legacy, upgraded), Pentland Haulage, Northgate Tickets'),
        );
    }

    public function test_a_cell_naming_nothing_in_the_file_selects_nothing(): void
    {
        $choices = WhereUsedChoices::of($this->parseFixture());

        $this->assertSame([], $choices->selected('Pentland Haulage'));
        $this->assertSame([], $choices->selected(null));
    }

    public function test_a_part_naming_an_independent_project_selects_it(): void
    {
        $choices = WhereUsedChoices::of($this->parseFixture());

        $this->assertSame(['fathom-index'], $choices->selected('Independent: Fathom Index (indexer)'));
    }

    /**
     * A CV missing an ID has a problem of its own, and a fix for one row has
     * no business refusing to work until that one is dealt with.
     */
    public function test_a_role_with_no_id_is_still_a_choice(): void
    {
        $cv = $this->parser()->parse(str_replace(
            ' {#wrenbury-freight}',
            '',
            $this->fixture('master-cv/minimal.md'),
        ));

        $this->assertSame(
            ['Wrenbury Freight — Backend Engineer' => 'Wrenbury Freight'],
            WhereUsedChoices::of($cv)->all(),
        );
    }
}
