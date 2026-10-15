<?php

namespace Tests\Unit\Review;

use App\Generation\RequirementPriority;
use App\Generation\RequirementStatus;
use App\MasterCv\Skill;
use App\Review\Evidence;
use App\Review\Requirement;
use App\Review\RequirementsChecklist;
use App\Validation\MasterCvIndex;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\MasterCvFixtures;

class RequirementsChecklistTest extends TestCase
{
    use MasterCvFixtures;

    /**
     * A gap against a must-have is the single most useful thing on the review
     * screen, so it is first. Everything else sorts behind it.
     */
    public function test_it_orders_must_before_nice_and_gap_before_partial_before_met(): void
    {
        $output = ['jd_analysis' => [
            $this->requirement('Nice and met', 'nice', 'met'),
            $this->requirement('Must and met', 'must', 'met'),
            $this->requirement('Nice and gap', 'nice', 'gap'),
            $this->requirement('Must and gap', 'must', 'gap'),
            $this->requirement('Nice and partial', 'nice', 'partial'),
            $this->requirement('Must and partial', 'must', 'partial'),
        ]];

        $this->assertSame(
            [
                'Must and gap',
                'Must and partial',
                'Must and met',
                'Nice and gap',
                'Nice and partial',
                'Nice and met',
            ],
            array_map(
                static fn (Requirement $requirement): string => $requirement->requirement,
                $this->checklist($output),
            ),
        );
    }

    /**
     * Two requirements that need the same attention stay in the order the
     * model extracted them: that order is its reading of the advert.
     */
    public function test_requirements_that_need_the_same_attention_keep_their_order(): void
    {
        $output = ['jd_analysis' => [
            $this->requirement('First', 'must', 'gap'),
            $this->requirement('Second', 'must', 'gap'),
            $this->requirement('Third', 'must', 'gap'),
        ]];

        $this->assertSame(
            ['First', 'Second', 'Third'],
            array_map(
                static fn (Requirement $requirement): string => $requirement->requirement,
                $this->checklist($output),
            ),
        );
    }

    public function test_it_reads_the_priority_the_status_and_the_note(): void
    {
        $output = ['jd_analysis' => [[
            'requirement' => 'Modern PHP',
            'priority' => 'nice',
            'status' => 'partial',
            'evidence' => [],
            'note' => 'One sentence for the owner.',
        ]]];

        $requirement = $this->checklist($output)[0];

        $this->assertSame('Modern PHP', $requirement->requirement);
        $this->assertSame(RequirementPriority::Nice, $requirement->priority);
        $this->assertSame(RequirementStatus::Partial, $requirement->status);
        $this->assertSame('One sentence for the owner.', $requirement->note);
    }

    /**
     * An ID on its own is not an audit: the owner is checking what the CV
     * claims about them, which means reading the sentence it points at.
     */
    public function test_it_resolves_an_item_id_to_the_master_cvs_own_words(): void
    {
        $output = ['jd_analysis' => [
            $this->requirement('Modern PHP', 'must', 'met', ['harlequin-transit.6']),
        ]];

        $evidence = $this->checklist($output)[0]->evidence;

        $this->assertCount(1, $evidence);
        $this->assertSame('harlequin-transit.6', $evidence[0]->id);
        $this->assertStringContainsString('PHP 7.4 to 8.3 modernisation', $evidence[0]->text);
    }

    public function test_it_resolves_a_role_id_to_its_heading(): void
    {
        $output = ['jd_analysis' => [
            $this->requirement('Backend experience', 'must', 'met', ['harlequin-transit']),
        ]];

        $this->assertSame(
            'Harlequin Transit — Senior Backend Engineer',
            $this->checklist($output)[0]->evidence[0]->text,
        );
    }

    public function test_it_resolves_a_skill_id_to_the_row_it_names(): void
    {
        $id = Skill::idFor('PHP 8.x (8.3)');

        $output = ['jd_analysis' => [
            $this->requirement('PHP 8', 'must', 'met', [$id]),
        ]];

        $this->assertSame('PHP 8.x (8.3)', $this->checklist($output)[0]->evidence[0]->text);
    }

    /**
     * A gap is expected to cite nothing, and showing none is the honest
     * answer rather than a blank for something to fill.
     */
    public function test_a_requirement_citing_nothing_shows_no_evidence(): void
    {
        $output = ['jd_analysis' => [
            $this->requirement('Kubernetes', 'must', 'gap'),
        ]];

        $this->assertSame([], $this->checklist($output)[0]->evidence);
    }

    /**
     * Dropping it would hide a citation the validators have something to say
     * about, and a citation nobody can see is worse than an unresolved one.
     */
    public function test_an_id_this_version_does_not_hold_stands_as_itself(): void
    {
        $output = ['jd_analysis' => [
            $this->requirement('Something', 'must', 'met', ['nothing-of-the-sort']),
        ]];

        $this->assertEquals(
            [new Evidence('nothing-of-the-sort', 'nothing-of-the-sort')],
            $this->checklist($output)[0]->evidence,
        );
    }

    /**
     * A manual edit has met no schema, so a field with nothing readable in it
     * is read as the one that asks for the most attention: a requirement
     * nobody can classify must not sort below one that is met.
     */
    public function test_an_unreadable_priority_or_status_asks_for_attention(): void
    {
        $output = ['jd_analysis' => [[
            'requirement' => 'Something',
            'priority' => 'whenever',
            'status' => null,
            'evidence' => 'not a list',
            'note' => '',
        ]]];

        $requirement = $this->checklist($output)[0];

        $this->assertSame(RequirementPriority::Must, $requirement->priority);
        $this->assertSame(RequirementStatus::Gap, $requirement->status);
        $this->assertNull($requirement->note);
        $this->assertSame([], $requirement->evidence);
    }

    public function test_output_with_no_analysis_in_it_is_an_empty_checklist(): void
    {
        $this->assertSame([], $this->checklist([]));
        $this->assertSame([], $this->checklist(['jd_analysis' => 'not a list']));
        $this->assertSame([], $this->checklist(['jd_analysis' => ['not an object']]));
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<Requirement>
     */
    private function checklist(array $output): array
    {
        return (new RequirementsChecklist)->of(
            $output,
            new MasterCvIndex($this->parseFixture()),
        );
    }

    /**
     * @param  list<string>  $evidence
     * @return array<string, mixed>
     */
    private function requirement(
        string $requirement,
        string $priority,
        string $status,
        array $evidence = [],
    ): array {
        return [
            'requirement' => $requirement,
            'priority' => $priority,
            'status' => $status,
            'evidence' => $evidence,
            'note' => 'One sentence for the owner.',
        ];
    }
}
