<?php

namespace Tests\Unit\Generation;

use App\Generation\RoleModeFinding;
use App\Generation\RoleModeFindingKind;
use App\Generation\RoleModeReview;
use App\MasterCv\RoleMode;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\MasterCvFixtures;

class RoleModeReviewTest extends TestCase
{
    use MasterCvFixtures;

    /**
     * The fixture's cutoffs are 2018 and 2012, so its four roles exercise all
     * three defaults.
     */
    public function test_the_defaults_come_from_the_policy(): void
    {
        $modes = [];

        foreach ($this->parseFixture()->roles as $role) {
            $modes[(string) $role->id] = $role->defaultMode;
        }

        $this->assertSame([
            'harlequin-transit' => RoleMode::Full,
            'northgate-tickets' => RoleMode::Full,
            'pelham-civic-data' => RoleMode::Condensed,
            'larkspur-media' => RoleMode::Omitted,
        ], $modes);
    }

    public function test_a_role_left_at_its_default_passes(): void
    {
        $this->assertSame([], $this->findings([
            'roles' => [$this->selection('larkspur-media', 'omitted')],
        ]));
    }

    public function test_a_promoted_role_with_a_reason_passes(): void
    {
        $this->assertSame([], $this->findings([
            'roles' => [$this->selection('larkspur-media', 'condensed')],
            'review_notes' => ['promotions' => [[
                'id' => 'larkspur-media',
                'from' => 'omitted',
                'to' => 'condensed',
                'reason' => 'The job asks for classified advertising.',
            ]]],
        ]));
    }

    public function test_a_demoted_role_is_a_finding(): void
    {
        $findings = $this->findings([
            'roles' => [$this->selection('pelham-civic-data', 'omitted')],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame('pelham-civic-data', $findings[0]->roleId);
        $this->assertSame(RoleMode::Condensed, $findings[0]->default);
        $this->assertSame(RoleMode::Omitted, $findings[0]->returned);
        $this->assertSame(RoleModeFindingKind::Demotion, $findings[0]->kind);
    }

    /**
     * A demotion is a finding whether or not the model explained itself: the
     * direction is not the model's to choose.
     */
    public function test_a_demotion_with_a_reason_is_still_a_finding(): void
    {
        $findings = $this->findings([
            'roles' => [$this->selection('harlequin-transit', 'condensed')],
            'review_notes' => ['promotions' => [[
                'id' => 'harlequin-transit',
                'from' => 'full',
                'to' => 'condensed',
                'reason' => 'Space.',
            ]]],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame(RoleModeFindingKind::Demotion, $findings[0]->kind);
    }

    public function test_a_promotion_without_a_reason_is_a_finding(): void
    {
        $findings = $this->findings([
            'roles' => [$this->selection('larkspur-media', 'full')],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame(RoleModeFindingKind::PromotionWithoutReason, $findings[0]->kind);
    }

    public function test_a_blank_reason_is_no_reason(): void
    {
        $findings = $this->findings([
            'roles' => [$this->selection('larkspur-media', 'condensed')],
            'review_notes' => ['promotions' => [[
                'id' => 'larkspur-media',
                'from' => 'omitted',
                'to' => 'condensed',
                'reason' => '   ',
            ]]],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame(RoleModeFindingKind::PromotionWithoutReason, $findings[0]->kind);
    }

    public function test_independent_projects_are_held_to_the_same_rule(): void
    {
        $findings = $this->findings([
            'independent_projects' => [$this->selection('quayside-charts', 'omitted')],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame('quayside-charts', $findings[0]->roleId);
        $this->assertSame(RoleModeFindingKind::Demotion, $findings[0]->kind);
    }

    /**
     * An ID that names no role resolves to nothing, which the ID-resolution
     * check reports. Saying it twice, in two vocabularies, would not help.
     */
    public function test_a_selection_that_names_no_role_is_left_to_the_id_checks(): void
    {
        $this->assertSame([], $this->findings([
            'roles' => [$this->selection('harlequin-transit.4', 'omitted')],
        ]));
    }

    public function test_it_reads_a_selection_that_is_missing_its_fields(): void
    {
        $this->assertSame([], $this->findings([
            'roles' => ['not an object', ['mode' => 'omitted'], ['id' => 'larkspur-media']],
            'review_notes' => 'not an object',
        ]));
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<RoleModeFinding>
     */
    private function findings(array $output): array
    {
        return (new RoleModeReview)->findings($this->parseFixture(), $output);
    }

    /**
     * @return array<string, mixed>
     */
    private function selection(string $id, string $mode): array
    {
        return ['id' => $id, 'mode' => $mode, 'items' => []];
    }
}
