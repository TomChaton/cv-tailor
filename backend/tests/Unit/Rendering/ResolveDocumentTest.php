<?php

namespace Tests\Unit\Rendering;

use App\MasterCv\RoleMode;
use App\Rendering\ResolvedDocument;
use App\Rendering\ResolvedItem;
use App\Rendering\ResolveDocument;
use App\Rendering\ResolvedRole;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\MasterCvFixtures;
use Tests\Concerns\SelectionFixtures;

class ResolveDocumentTest extends TestCase
{
    use MasterCvFixtures;
    use SelectionFixtures;

    public function test_an_item_with_no_text_resolves_to_the_master_cv_wording(): void
    {
        $document = $this->resolve($this->selectionWithItems('northgate-tickets', [
            $this->verbatim('northgate-tickets.1'),
        ]));

        $item = $this->resolvedRole($document, 'northgate-tickets')->items[0];

        $this->assertSame(
            'Built the seat-reservation service that holds a seat while a customer pays, '
            .'on Symfony 4.4 and RabbitMQ.',
            $item->text,
        );
        $this->assertFalse($item->reworded);
    }

    public function test_an_item_with_text_resolves_to_the_models_wording(): void
    {
        $document = $this->resolve($this->selectionWithItems('northgate-tickets', [
            $this->reworded(
                'northgate-tickets.1',
                'Built seat reservation on Symfony 4.4 and RabbitMQ.',
                ['northgate-tickets.1'],
            ),
        ]));

        $item = $this->resolvedRole($document, 'northgate-tickets')->items[0];

        $this->assertSame('Built seat reservation on Symfony 4.4 and RabbitMQ.', $item->text);
        $this->assertTrue($item->reworded);
    }

    /**
     * "Do not elaborate" is the author saying the detail is theirs to give or
     * withhold. A rewording of it is discarded in favour of their sentence.
     */
    public function test_do_not_elaborate_content_resolves_verbatim_even_when_reworded(): void
    {
        $document = $this->resolve($this->selectionWithItems('harlequin-transit', [
            $this->reworded(
                'harlequin-transit.9',
                'Led the selection of a telematics vendor for the dispatch platform.',
                ['harlequin-transit.9'],
            ),
        ]));

        $item = $this->resolvedRole($document, 'harlequin-transit')->items[0];

        $this->assertSame(
            '**Vendor selection.** Took part in selecting a telematics vendor; do not elaborate, '
            .'as the vendors were ultimately rejected.',
            $item->text,
        );
        $this->assertFalse($item->reworded);
    }

    public function test_roles_are_reverse_chronological_whatever_order_the_output_gave(): void
    {
        $selection = $this->cleanSelection();
        /** @var list<array<string, mixed>> $roles */
        $roles = $selection['roles'];
        $selection['roles'] = array_reverse($roles);
        $selection = $this->selection($selection);

        $document = $this->resolve($selection);

        $this->assertSame(
            ['harlequin-transit', 'northgate-tickets', 'pelham-civic-data'],
            array_map(static fn (ResolvedRole $role): string => $role->id, $document->roles),
        );
        $this->assertSame(
            ['fathom-index', 'quayside-charts'],
            array_map(static fn (ResolvedRole $role): string => $role->id, $document->independentProjects),
        );
    }

    public function test_dates_come_from_the_parsed_cv_and_not_from_the_output(): void
    {
        $selection = $this->selectionWithItems('northgate-tickets', [$this->verbatim('northgate-tickets.1')]);
        /** @var list<array<string, mixed>> $roles */
        $roles = $selection['roles'];
        // Nothing in the schema takes a date, so a manual edit is the only way
        // one arrives. It is ignored wherever it came from.
        $roles[1]['dates'] = '2019 – 2026';
        $roles[1]['employer'] = 'Northgate Systems';
        $selection['roles'] = $roles;

        $role = $this->resolvedRole($this->resolve($selection), 'northgate-tickets');

        $this->assertSame('February 2018 – August 2021', $role->dates);
        $this->assertSame('Northgate Tickets', $role->employer);
        $this->assertSame('Backend Developer', $role->title);
    }

    public function test_the_header_comes_from_the_parsed_contact_section(): void
    {
        $header = $this->resolve($this->selection())->header;

        $this->assertSame('Jordan Avery', $header->name);
        $this->assertSame('Senior Backend Engineer', $header->headline);

        $labels = array_column($header->contact, 'label');

        // Everything the author put in their contact section, in their order,
        // and the name only once.
        $this->assertNotContains('Name', $labels);
        $this->assertSame(['Location', 'Phone', 'Email'], array_slice($labels, 0, 3));
        $this->assertSame('07700 900123', $header->contact[1]['value']);
    }

    /**
     * The schema offers the model the CV's own titles, so this can only arrive
     * from a manual edit — and a headline the CV does not offer is a claim
     * with nothing behind it.
     */
    public function test_a_headline_the_cv_does_not_offer_is_dropped(): void
    {
        $document = $this->resolve($this->selection(['headline' => 'Head of Engineering']));

        $this->assertNull($document->header->headline);
    }

    public function test_an_omitted_role_is_not_in_the_document(): void
    {
        $document = $this->resolve($this->selection());

        $this->assertNotContains(
            'larkspur-media',
            array_map(static fn (ResolvedRole $role): string => $role->id, $document->roles),
        );
        $this->assertSame(1, $document->counts()['roles_omitted']);
    }

    /**
     * A role the output never mentions and one it marks omitted are the same
     * decision, and the counts say so either way.
     */
    public function test_a_role_the_output_left_out_is_counted_as_omitted(): void
    {
        $selection = $this->cleanSelection();
        /** @var list<array<string, mixed>> $roles */
        $roles = $selection['roles'];
        $selection['roles'] = [$roles[0], $roles[1]];

        $document = $this->resolve($this->selection($selection));

        $this->assertSame(2, $document->counts()['roles_omitted']);
    }

    /**
     * A role is condensed by leaving its prose out, which is a decision the
     * document model makes so that every output format makes it the same way.
     */
    public function test_a_condensed_role_keeps_its_items_and_loses_its_context(): void
    {
        $document = $this->resolve($this->selection());

        $this->assertNotNull($this->resolvedRole($document, 'harlequin-transit')->context);

        $condensed = $this->resolvedRole($document, 'pelham-civic-data');

        $this->assertSame(RoleMode::Condensed, $condensed->mode);
        $this->assertNull($condensed->context);
        $this->assertCount(1, $condensed->items);
    }

    /**
     * Which bullet is whose child is a fact of the master CV, so a sub-bullet
     * chosen beside its parent is shown under it.
     */
    public function test_a_sub_bullet_nests_under_its_parent_when_both_are_chosen(): void
    {
        $document = $this->resolve($this->selectionWithItems('harlequin-transit', [
            $this->verbatim('harlequin-transit.4.1'),
            $this->verbatim('harlequin-transit.4'),
            $this->verbatim('harlequin-transit.4.3'),
        ]));

        $items = $this->resolvedRole($document, 'harlequin-transit')->items;

        $this->assertCount(1, $items);
        $this->assertSame('harlequin-transit.4', $items[0]->id);
        $this->assertSame(['harlequin-transit.4.1', 'harlequin-transit.4.3'], $this->ids($items[0]->children));

        // Nesting changes the shape and not the content: three chosen bullets
        // are three items however they are arranged.
        $this->assertSame(7, $document->counts()['items']);
    }

    public function test_a_sub_bullet_chosen_without_its_parent_stands_on_its_own(): void
    {
        $document = $this->resolve($this->selectionWithItems('harlequin-transit', [
            $this->verbatim('harlequin-transit.4.1'),
        ]));

        $items = $this->resolvedRole($document, 'harlequin-transit')->items;

        $this->assertCount(1, $items);
        $this->assertSame('harlequin-transit.4.1', $items[0]->id);
        $this->assertSame([], $items[0]->children);
    }

    /**
     * Validation blocks a revision that files one employer's bullet under
     * another. A renderer that printed it anyway would be a second route onto
     * the page.
     */
    public function test_an_item_the_role_does_not_own_is_dropped(): void
    {
        $document = $this->resolve($this->selectionWithItems('harlequin-transit', [
            $this->verbatim('northgate-tickets.1'),
            $this->verbatim('harlequin-transit.4'),
        ]));

        $this->assertSame(
            ['harlequin-transit.4'],
            $this->ids($this->resolvedRole($document, 'harlequin-transit')->items),
        );
    }

    public function test_an_item_the_author_marked_todo_is_dropped(): void
    {
        $document = $this->resolve($this->selection([
            'roles' => [$this->role('larkspur-media', 'full', [
                $this->verbatim('larkspur-media.1'),
                $this->verbatim('larkspur-media.2'),
            ])],
        ]));

        $this->assertSame(['larkspur-media.1'], $this->ids($this->resolvedRole($document, 'larkspur-media')->items));
    }

    public function test_key_skills_resolve_to_the_authors_own_names(): void
    {
        $document = $this->resolve($this->selection());

        $this->assertSame(
            ['PHP 8.x (8.3)', 'PostgreSQL (13, 15)', 'Elasticsearch (7, 8)'],
            array_map(static fn ($skill): string => $skill->name, $document->skills),
        );
    }

    public function test_awards_and_interests_come_from_the_parse(): void
    {
        $document = $this->resolve($this->selection());

        $this->assertSame(
            ['2019 — Ticketing Innovation Award, Northern Events Association (Northgate Tickets team)'],
            $document->awards,
        );
        $this->assertCount(4, $document->interests);
    }

    public function test_the_resolved_document_leaves_no_ids_to_look_up(): void
    {
        $array = $this->resolve($this->selection())->toArray();

        $this->assertSame(
            ['header', 'summary', 'skills', 'roles', 'independent_projects', 'awards', 'interests'],
            array_keys($array),
        );
        $this->assertIsString($array['summary']);
    }

    public function test_the_counts_say_what_the_document_holds(): void
    {
        $counts = $this->resolve($this->selection())->counts();

        $this->assertSame([
            'roles_included' => 5,
            'roles_condensed' => 1,
            'roles_omitted' => 1,
            'items' => 6,
            'reworded_items' => 1,
        ], $counts);
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function resolve(array $output): ResolvedDocument
    {
        return (new ResolveDocument)->resolve($this->parseFixture(), $output);
    }

    private function resolvedRole(ResolvedDocument $document, string $id): ResolvedRole
    {
        foreach ($document->allRoles() as $role) {
            if ($role->id === $id) {
                return $role;
            }
        }

        $this->fail($id.' is not in the resolved document.');
    }

    /**
     * @param  list<ResolvedItem>  $items
     * @return list<string>
     */
    private function ids(array $items): array
    {
        return array_map(static fn (ResolvedItem $item): string => $item->id, $items);
    }
}
